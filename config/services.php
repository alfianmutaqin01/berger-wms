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
    |         = log    : mencatat ke log, untuk pengembangan.
    |
    | Berpindah ke 'cloud' TIDAK mengubah kode mana pun — hanya nilai ini.
    */
    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'manual'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'token' => env('WHATSAPP_TOKEN'),
        // Nama template "utility" yang sudah disetujui Meta. Pesan yang
        // dimulai bisnis ke nomor yang belum pernah membalas WAJIB template.
        'template' => env('WHATSAPP_TEMPLATE', 'konfirmasi_pengiriman'),
        'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'id'),
    ],

];
