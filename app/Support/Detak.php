<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Detak penjadwal dan antrean — bukti bahwa keduanya BENAR-BENAR berjalan.
 *
 * KENAPA PERLU
 * ------------
 * Antrean dan penjadwal mati tanpa suara. Halaman tetap terbuka, pesanan tetap
 * bisa dibuat, dan tidak ada yang tahu email ke Sales, WhatsApp ke supir,
 * sapuan stok kedaluwarsa, maupun pengingat piutang berhenti keluar —
 * sampai seseorang bertanya kenapa kabarnya tidak pernah datang. Konfigurasi
 * production yang lama persis begitu: layanan antreannya menjalankan Horizon
 * yang tidak terpasang.
 *
 * Penjadwal menulis detaknya tiap menit. Tiap lima menit ia juga menitipkan
 * DetakAntrean ke antrean; detak antrean hanya tertulis kalau ada worker yang
 * mengambilnya. /health melaporkan keduanya, sehingga pemantau luar (mis.
 * UptimeRobot) yang memanggil /health ikut tahu bila salah satunya berhenti.
 */
class Detak
{
    public const PENJADWAL = 'detak:penjadwal';

    public const ANTREAN = 'detak:antrean';

    /** Batas basi dalam detik. Antrean diberi ruang untuk jadwal 5 menit + antrean yang sedang ramai. */
    public const BATAS = [
        self::PENJADWAL => 5 * 60,
        self::ANTREAN => 15 * 60,
    ];

    public static function catat(string $kunci): void
    {
        Cache::forever($kunci, now()->getTimestamp());
    }

    /**
     * Keadaan satu detak: true (segar), false (basi), atau null (belum pernah
     * berdetak — mis. beberapa detik setelah pemasangan pertama).
     */
    public static function segar(string $kunci): ?bool
    {
        try {
            $terakhir = Cache::get($kunci);
        } catch (Throwable) {
            return false;
        }

        if ($terakhir === null) {
            return null;
        }

        return now()->getTimestamp() - (int) $terakhir <= self::BATAS[$kunci];
    }
}
