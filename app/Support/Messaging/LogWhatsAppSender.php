<?php

namespace App\Support\Messaging;

use Illuminate\Support\Facades\Log;

/**
 * Menulis pesan ke log alih-alih mengirimnya — pengembangan dan test.
 *
 * Nomornya ikut dicatat karena di lingkungan pengembangan justru nomor
 * itulah yang perlu diperiksa: salah normalisasi baru kelihatan saat
 * dibandingkan dengan yang diketik.
 */
class LogWhatsAppSender implements WhatsAppSender
{
    public function send(string $phone, string $message): DispatchResult
    {
        Log::info('WhatsApp (mode log, tidak benar-benar dikirim)', [
            'to' => $phone,
            'message' => $message,
        ]);

        return DispatchResult::sent();
    }
}
