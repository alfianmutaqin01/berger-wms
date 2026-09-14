<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Verifikasi Anti-Bot (Google reCAPTCHA v2) — PRD §6.1 F-AUTH-02.
    | secret_key sengaja boleh kosong di lingkungan lokal/testing: lihat
    | AuthController::verifyRecaptcha() untuk perilaku saat kosong.
    */
    'recaptcha' => [
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
    ],

    /*
    | WhatsApp — tautan konfirmasi untuk supir (PRD §6.5 F-OUT-04 #10).
    |
    | driver = manual : bawaan. Sistem menyiapkan pesan + tautan, Logistik
    |                   yang menekan kirim lewat WhatsApp-nya sendiri. Tanpa
    |                   langganan, tanpa risiko nomor diblokir.
    |         = cloud  : WhatsApp Cloud API resmi Meta. Butuh SELURUH isian di
    |                   bawah terisi; lihat CloudApiWhatsAppSender untuk apa
    |                   yang harus disiapkan di sisi Meta lebih dulu.
    |         = fonnte : gateway pihak ketiga lewat nomor WhatsApp biasa yang
    |                   ditautkan. Butuh fonnte_token; lihat
    |                   FonnteWhatsAppSender untuk risikonya.
    |         = log    : mencatat ke log, untuk pengembangan.
    |
    | Berpindah penyedia TIDAK mengubah kode mana pun — hanya nilai ini.
    */
    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'manual'),

        // Nomor yang SEHARUSNYA tampil sebagai pengirim. Catatan, bukan
        // pengatur: Meta menentukan pengirim dari phone_number_id, Fonnte
        // dari tokennya. Ditampilkan di layar supaya kalau pesan keluar dari
        // nomor lain, orang punya pembanding.
        'sender_number' => env('WHATSAPP_SENDER_NUMBER'),

        // --- Meta Cloud API
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'token' => env('WHATSAPP_TOKEN'),
        'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'id'),

        /*
         | Jenis pesan => nama template yang DISETUJUI Meta. Satu per jenis:
         | memakai satu template untuk semuanya membuat atasan MRF menerima
         | pesan berbunyi "konfirmasi pengiriman". Isi template yang harus
         | diajukan ke Meta tertulis di .env.example.
         */
        'templates' => [
            'konfirmasi_pengiriman' => env('WHATSAPP_TEMPLATE', 'konfirmasi_pengiriman'),
            'persetujuan_mrf' => env('WHATSAPP_TEMPLATE_MRF', 'persetujuan_mrf'),
            'barang_sampai_sales' => env('WHATSAPP_TEMPLATE_BARANG_SAMPAI', 'barang_sampai_sales'),
        ],

        // --- Fonnte
        'fonnte_token' => env('WHATSAPP_FONNTE_TOKEN'),
    ],

];
