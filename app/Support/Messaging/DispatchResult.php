<?php

namespace App\Support\Messaging;

use App\Models\DeliveryNote;

/**
 * Hasil satu percobaan pengiriman pesan.
 *
 * BUKAN boolean. "Gagal" dan "belum dikirim karena memang harus dikirim
 * manual" adalah dua keadaan yang sangat berbeda: yang pertama perlu
 * ditindaklanjuti, yang kedua adalah cara kerja normal pada mode tanpa
 * penyedia. Memampatkan keduanya jadi true/false membuat layar tidak bisa
 * membedakan mana yang perlu perhatian.
 */
final readonly class DispatchResult
{
    private function __construct(
        public string $status,
        public ?string $error = null,
    ) {}

    /** Penyedia menyatakan pesannya terkirim. */
    public static function sent(): self
    {
        return new self(DeliveryNote::NOTIFY_SENT);
    }

    /**
     * Tidak dikirim sistem — Logistik yang mengirimkannya sendiri.
     *
     * Ini keadaan SAH, bukan kegagalan: pada mode manual, sistem menyiapkan
     * pesan dan tautannya lalu orang yang menekan kirim.
     */
    public static function manual(): self
    {
        return new self(DeliveryNote::NOTIFY_MANUAL);
    }

    public static function failed(string $error): self
    {
        return new self(DeliveryNote::NOTIFY_FAILED, $error);
    }

    public function berhasil(): bool
    {
        return $this->status === DeliveryNote::NOTIFY_SENT;
    }
}
