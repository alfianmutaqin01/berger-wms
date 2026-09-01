<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Menyatakan sisa umur simpan stok dalam BULAN + MINGGU.
 *
 * "12 Feb 2027" tidak memberi tahu apa pun tanpa menghitung mundur di kepala.
 * "5 bln 3 minggu" langsung bisa dipakai memutuskan mana yang harus dijual
 * duluan — itulah yang dilihat orang gudang saat memilih batch.
 *
 * Bulan dihitung sebagai bulan KALENDER, bukan 30 hari. Dari 31 Januari ke
 * 28 Februari adalah "1 bulan", bukan "28 hari" — kalau dibagi rata 30 hari,
 * angkanya akan meleset makin jauh untuk masa simpan panjang seperti 30 bulan.
 */
class ShelfLife
{
    /** Ambang peringatan dini kedaluwarsa (PRD §7.2.1: 90 hari). */
    public const WARNING_DAYS = 90;

    /**
     * Sisa umur simpan sebagai teks siap tampil.
     *
     * Contoh keluaran: "5 bln 3 minggu", "6 bulan pas", "10 bln 1 minggu",
     * "3 minggu", "5 hari", "Hari ini", "Kedaluwarsa 12 hari lalu".
     */
    public static function remainingLabel(?CarbonInterface $expiryDate, ?CarbonInterface $now = null): string
    {
        if ($expiryDate === null) {
            return '—';
        }

        $now = ($now ?? now())->copy()->startOfDay();
        $expiry = $expiryDate->copy()->startOfDay();

        if ($expiry->lessThan($now)) {
            return 'Kedaluwarsa '.self::plainDuration($expiry, $now).' lalu';
        }

        // Batch yang kedaluwarsa HARI INI sudah tidak boleh dijual: aturan
        // FIFO menyaring `expiry_date > CURRENT_DATE` (PRD §7.2), bukan >=.
        // Karena itu ditulis tegas "kedaluwarsa", bukan "tinggal hari ini".
        if ($expiry->equalTo($now)) {
            return 'Kedaluwarsa hari ini';
        }

        return self::plainDuration($now, $expiry);
    }

    /**
     * Selisih dua tanggal sebagai "X bln Y minggu".
     *
     * Sisa hari di bawah satu minggu SENGAJA dibuang begitu durasinya sudah
     * mencapai hitungan bulan: "5 bln 3 minggu 4 hari" lebih sulit dibaca
     * sekilas daripada "5 bln 3 minggu", dan ketelitian harian tidak mengubah
     * keputusan mana yang dijual duluan. Di bawah satu bulan barulah harinya
     * ditampilkan, karena di situ tiap hari mulai berarti.
     */
    private static function plainDuration(CarbonInterface $from, CarbonInterface $to): string
    {
        // Carbon 3 mengembalikan PECAHAN dari diffIn*(). Tanpa pembulatan ke
        // bawah, labelnya keluar sebagai "5.3928571428571 bln".
        $months = (int) $from->diffInMonths($to);
        $afterMonths = $from->copy()->addMonths($months);
        $weeks = intdiv((int) $afterMonths->diffInDays($to), 7);

        if ($months > 0) {
            // "6 bulan pas" dibaca lebih tegas daripada "6 bln 0 minggu",
            // dan menegaskan bahwa tidak ada sisa yang dibulatkan diam-diam.
            return $weeks > 0
                ? "{$months} bln {$weeks} minggu"
                : "{$months} bulan pas";
        }

        if ($weeks > 0) {
            return "{$weeks} minggu";
        }

        $days = (int) $from->diffInDays($to);

        return $days === 1 ? '1 hari' : "{$days} hari";
    }

    /**
     * Tingkat kegentingan, untuk mewarnai baris di layar.
     *
     * 'expired' | 'critical' (<= 90 hari) | 'warning' (<= 180 hari) | 'safe'
     */
    public static function urgency(?CarbonInterface $expiryDate, ?CarbonInterface $now = null): string
    {
        if ($expiryDate === null) {
            return 'safe';
        }

        $now = ($now ?? now())->copy()->startOfDay();
        $expiry = $expiryDate->copy()->startOfDay();

        if ($expiry->lessThanOrEqualTo($now)) {
            return 'expired';
        }

        $sisaHari = (int) $now->diffInDays($expiry);

        return match (true) {
            $sisaHari <= self::WARNING_DAYS => 'critical',
            $sisaHari <= self::WARNING_DAYS * 2 => 'warning',
            default => 'safe',
        };
    }
}
