<?php

namespace App\Support\Messaging;

/**
 * Mode tanpa penyedia: sistem menyiapkan, orang yang mengirim.
 *
 * TIDAK MENGIRIM APA PUN, dan itu memang tugasnya. Layar Surat Jalan
 * menyediakan tombol yang membuka WhatsApp dengan nomor dan pesan sudah
 * terisi — Logistik tinggal menekan kirim — beserta tombol salin tautan
 * sebagai cadangan.
 *
 * Ia mengembalikan status `manual`, BUKAN `failed`. Membedakannya penting:
 * pada mode ini "belum terkirim" adalah cara kerja normal yang menunggu satu
 * ketukan manusia, sedangkan `failed` berarti ada yang rusak. Kalau
 * keduanya disamakan, layar akan penuh peringatan merah pada hari-hari yang
 * sebenarnya berjalan normal — dan peringatan yang selalu menyala berhenti
 * dibaca.
 */
class ManualWhatsAppSender implements WhatsAppSender
{
    public function send(string $phone, string $message): DispatchResult
    {
        return DispatchResult::manual();
    }
}
