<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Batas waktu submit pesanan — PRD §7.5 ORDER_CUTOFF.
 *
 * Lewat pukul 15:00 WIB, tombol Submit dikunci. Yang TIDAK ikut terkunci
 * adalah Simpan Draft (docs/4 §3.3.2): kalau keduanya mati, Sales yang
 * menerima pesanan sore hari tidak punya tempat menyimpan apa pun dan akan
 * mencatatnya di luar sistem.
 *
 * Jam cutoff dibaca dari config supaya Fase 10 tinggal mengarahkan sumbernya
 * ke system_settings tanpa mengubah satu pun tempat pemanggilan.
 */
class OrderCutoff
{
    public static function hour(): int
    {
        return (int) config('wms.order_cutoff_hour', 15);
    }

    public static function timezone(): string
    {
        return (string) config('wms.timezone', 'Asia/Jakarta');
    }

    /** Apakah saat ini masih boleh submit? */
    public static function isOpen(?CarbonInterface $now = null): bool
    {
        $sekarang = ($now ?? now())->copy()->setTimezone(self::timezone());

        // Pukul 15:00:00 tepat sudah DITOLAK — PRD menulis `>= 15:00`.
        return $sekarang->hour < self::hour();
    }

    /** Jam cutoff sebagai teks siap tampil, mis. "15:00 WIB". */
    public static function label(): string
    {
        return str_pad((string) self::hour(), 2, '0', STR_PAD_LEFT).':00 WIB';
    }

    public static function closedMessage(): string
    {
        return 'Batas waktu pemesanan hari ini sudah lewat. Silakan order kembali besok.';
    }
}
