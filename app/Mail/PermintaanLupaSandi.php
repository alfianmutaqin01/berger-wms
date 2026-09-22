<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Ke admin: seseorang meminta sandinya direset.
 *
 * TIDAK menumpang App\Mail\Pesanan\EmailPesanan. Kerangka itu milik email
 * pesanan — mencatat ke sales_order_emails dan menunjuk satu pesanan — dan
 * email ini tidak berurusan dengan pesanan mana pun.
 */
class PermintaanLupaSandi extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $pemohon,
        public readonly string $tautan,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Permintaan reset sandi — '.$this->pemohon->full_name,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.akun.lupa-sandi', with: [
            'nama' => $this->pemohon->full_name,
            'email' => $this->pemohon->email,
            'peran' => $this->pemohon->role?->name ?? 'tanpa peran',
            'waktu' => now()->timezone(config('app.timezone'))->format('d/m/Y H:i').' WIB',
            'tautan' => $this->tautan,
        ]);
    }
}
