<?php

namespace App\Support\Messaging;

use Illuminate\Support\Facades\Log;

/**
 * Menulis pesan ke log alih-alih mengirimnya — pengembangan dan test.
 *
 * Nomor dan nama templatenya ikut dicatat karena di lingkungan pengembangan
 * justru keduanya yang perlu diperiksa: salah normalisasi nomor dan salah
 * pilih template sama-sama baru kelihatan saat dibandingkan dengan yang
 * seharusnya.
 */
class LogWhatsAppSender implements WhatsAppSender
{
    public function send(string $phone, PesanWhatsApp $pesan): DispatchResult
    {
        Log::info('WhatsApp (mode log, tidak benar-benar dikirim)', [
            'to' => $phone,
            'template' => $pesan->template,
            'variabel' => $pesan->variabel,
            'message' => $pesan->teks,
        ]);

        return DispatchResult::sent();
    }
}
