<?php

namespace App\Support\Messaging;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WhatsApp Cloud API resmi Meta.
 *
 * YANG PERLU DISIAPKAN SEBELUM MODE INI BISA DINYALAKAN (bukan pekerjaan
 * kode, dan karena itu ditulis di sini supaya tidak hilang):
 *
 *   1. Akun Meta Business yang SUDAH TERVERIFIKASI.
 *   2. Satu nomor telepon KHUSUS yang belum pernah dipakai WhatsApp biasa —
 *      nomor yang sudah aktif di aplikasi WhatsApp harus dilepas dulu, dan
 *      setelah dipakai Cloud API ia tidak bisa dipakai sebagai WhatsApp
 *      biasa lagi.
 *   3. TEMPLATE PESAN yang disetujui Meta, kategori "utility". Pesan yang
 *      dimulai oleh bisnis ke nomor yang belum pernah membalas HARUS berupa
 *      template; teks bebas hanya boleh di dalam jendela 24 jam setelah
 *      lawan bicara membalas — dan supir tidak akan pernah membalas lebih
 *      dulu.
 *
 * KARENA ITU parameter template dikirim TERPISAH dari teksnya. Isi pesan
 * yang dipakai mode manual tidak bisa langsung dikirim lewat jalur ini;
 * yang dikirim adalah nama template beserta variabelnya.
 *
 * Isi variabelnya diambil dari pesan yang sama supaya keduanya tidak pernah
 * berbeda: baris terakhir yang berisi tautan adalah variabel yang penting,
 * sisanya sudah tertulis di template.
 */
class CloudApiWhatsAppSender implements WhatsAppSender
{
    public function __construct(
        private readonly string $phoneNumberId,
        private readonly string $token,
        private readonly string $template,
        private readonly string $language = 'id',
        private readonly string $version = 'v21.0',
    ) {}

    public function send(string $phone, string $message): DispatchResult
    {
        // Variabel template diambil dari tautan di dalam pesan. Template
        // "utility" milik Meta hanya menerima nilai variabel, bukan seluruh
        // teks — dan tautan inilah satu-satunya bagian yang berbeda tiap
        // pengiriman.
        $tautan = $this->tautanDari($message);

        if ($tautan === null) {
            return DispatchResult::failed('Pesan tidak memuat tautan konfirmasi, jadi tidak ada yang bisa dikirim.');
        }

        try {
            $respons = Http::withToken($this->token)
                ->timeout(15)
                ->post("https://graph.facebook.com/{$this->version}/{$this->phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $phone,
                    'type' => 'template',
                    'template' => [
                        'name' => $this->template,
                        'language' => ['code' => $this->language],
                        'components' => [[
                            'type' => 'body',
                            'parameters' => [['type' => 'text', 'text' => $tautan]],
                        ]],
                    ],
                ]);
        } catch (Throwable $e) {
            // Gangguan jaringan bukan alasan menahan barang; ia dicatat lalu
            // ditampilkan supaya ada yang menindaklanjuti.
            return DispatchResult::failed('Tidak dapat menghubungi WhatsApp: '.$e->getMessage());
        }

        if ($respons->successful()) {
            return DispatchResult::sent();
        }

        // Pesan galat Meta disimpan APA ADANYA. Menerjemahkannya jadi
        // "gagal kirim" menghapus satu-satunya keterangan yang membedakan
        // nomor salah, template belum disetujui, dan kuota habis.
        return DispatchResult::failed(
            $respons->json('error.message') ?? 'Ditolak WhatsApp dengan kode '.$respons->status()
        );
    }

    private function tautanDari(string $message): ?string
    {
        preg_match('~https?://\S+~', $message, $cocok);

        return $cocok[0] ?? null;
    }
}
