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
 *   3. TEMPLATE PESAN yang disetujui Meta, kategori "utility", SATU PER JENIS
 *      PESAN (lihat PesanWhatsApp dan .env.example). Pesan yang dimulai oleh
 *      bisnis ke nomor yang belum pernah membalas HARUS berupa template; teks
 *      bebas hanya boleh di dalam jendela 24 jam setelah lawan bicara
 *      membalas — dan supir maupun atasan tidak akan membalas lebih dulu.
 *
 * NAMA TEMPLATE DITERJEMAHKAN LEWAT PETA, bukan dikirim apa adanya. Nama yang
 * disetujui Meta sering berbeda dari yang direncanakan (Meta menolak nama
 * yang sudah dipakai, atau pemiliknya menambahkan akhiran versi saat
 * mengajukan ulang). Peta di config/services.php memungkinkan penggantian itu
 * tanpa menyentuh kode.
 */
class CloudApiWhatsAppSender implements WhatsAppSender
{
    /**
     * @param  array<string, string>  $templates  nama jenis pesan => nama template di Meta
     */
    public function __construct(
        private readonly string $phoneNumberId,
        private readonly string $token,
        private readonly array $templates = [],
        private readonly string $language = 'id',
        private readonly string $version = 'v21.0',
    ) {}

    public function send(string $phone, PesanWhatsApp $pesan): DispatchResult
    {
        if ($pesan->variabel === []) {
            return DispatchResult::failed(
                'Pesan tidak membawa variabel template, jadi tidak ada yang bisa dikirim lewat WhatsApp resmi.'
            );
        }

        $nama = $this->templates[$pesan->template] ?? $pesan->template;

        try {
            $respons = Http::withToken($this->token)
                ->timeout(15)
                ->post("https://graph.facebook.com/{$this->version}/{$this->phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $phone,
                    'type' => 'template',
                    'template' => [
                        'name' => $nama,
                        'language' => ['code' => $this->language],
                        'components' => [[
                            'type' => 'body',
                            'parameters' => array_map(
                                fn (string $nilai) => ['type' => 'text', 'text' => $nilai],
                                $pesan->variabel,
                            ),
                        ]],
                    ],
                ]);
        } catch (Throwable $e) {
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
}
