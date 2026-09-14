<?php

namespace App\Support\Messaging;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Gateway Fonnte — nomor WhatsApp biasa yang ditautkan sebagai perangkat.
 *
 * YANG PERLU DISIAPKAN (bukan pekerjaan kode):
 *
 *   1. Akun di fonnte.com dan satu perangkat yang ditautkan dengan memindai
 *      kode QR dari aplikasi WhatsApp di HP nomor pengirim — sama seperti
 *      menautkan WhatsApp Web. HP-nya harus tetap menyala dan tersambung.
 *   2. Token perangkat itu, diisi ke WHATSAPP_FONNTE_TOKEN.
 *
 * NOMOR PENGIRIM TIDAK DIKIRIM DARI SINI. Fonnte menentukan nomor pengirim
 * dari TOKEN-nya, bukan dari parameter permintaan. WHATSAPP_SENDER_NUMBER di
 * .env hanya catatan agar orang yang membaca setelan tahu nomor mana yang
 * seharusnya tertaut — kalau tokennya milik perangkat lain, pesannya keluar
 * dari nomor lain, dan kode ini tidak punya cara mengetahuinya.
 *
 * RISIKO YANG DITERIMA DENGAN SADAR. Gateway seperti ini melanggar ketentuan
 * layanan WhatsApp, dan nomornya bisa diblokir. Untuk pesan ke Sales —
 * karyawan sendiri, nomor tetap, dan mengenal nomor perusahaan — risikonya
 * jauh lebih kecil daripada ke supir yang berganti setiap hari. Kecil, bukan
 * nol. Karena itu mode ini tidak menjadi bawaan.
 *
 * TEKS BEBAS, TANPA TEMPLATE. Berbeda dari jalur Meta, di sini yang dikirim
 * adalah teks lengkap pesannya — persis yang juga dibuka tombol wa.me.
 */
class FonnteWhatsAppSender implements WhatsAppSender
{
    private const ENDPOINT = 'https://api.fonnte.com/send';

    public function __construct(private readonly string $token) {}

    public function send(string $phone, PesanWhatsApp $pesan): DispatchResult
    {
        try {
            $respons = Http::withHeaders(['Authorization' => $this->token])
                ->asForm()
                ->timeout(15)
                ->post(self::ENDPOINT, [
                    'target' => $phone,
                    'message' => $pesan->teks,
                    // Nomornya sudah berawalan 62; countryCode disebut supaya
                    // Fonnte tidak menambahkan awalan lain di depannya.
                    'countryCode' => '62',
                ]);
        } catch (Throwable $e) {
            return DispatchResult::failed('Tidak dapat menghubungi Fonnte: '.$e->getMessage());
        }

        /*
         * FONNTE MENJAWAB 200 WALAU GAGAL. Penanda sebenarnya ada di isi
         * jawabannya (`status: false` beserta `reason`). Memercayai kode HTTP
         * saja akan mencatat "terkirim" untuk pesan yang ditolak karena
         * perangkatnya terputus — dan Sales yang tidak menerima apa pun tidak
         * akan pernah tahu harus menanyakannya.
         */
        if ($respons->successful() && $respons->json('status') === true) {
            return DispatchResult::sent();
        }

        return DispatchResult::failed(
            $respons->json('reason')
                ?? $respons->json('detail')
                ?? 'Ditolak Fonnte dengan kode '.$respons->status()
        );
    }
}
