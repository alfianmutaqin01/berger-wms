<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pembangkit nomor dokumen berurutan per hari.
 *
 * Format: {AWALAN}-{YYMMDD}-{NNN}, contoh: IN-260828-001.
 * Nomor urut kembali ke 001 setiap ganti hari.
 *
 * CATATAN: ini implementasi sementara yang menghitung dokumen hari berjalan.
 * Fase 10 akan menggantinya dengan tabel `document_sequences` yang menyimpan
 * penghitung secara eksplisit per gudang dan jenis dokumen. Antarmukanya
 * (`next()`) sengaja dibuat sesederhana ini agar penggantian nanti tidak
 * menyentuh pemanggilnya.
 */
class DocumentNumber
{
    /** Dokumen produksi masuk. */
    public const PREFIX_INBOUND = 'IN';

    /**
     * Nomor berikutnya yang BELUM dipakai.
     *
     * Dipakai untuk pratinjau di layar. Nomor final tetap dibangkitkan ulang
     * saat menyimpan lewat `reserve()`, karena antara layar terbuka dan tombol
     * simpan ditekan bisa saja ada dokumen lain yang tersimpan lebih dulu.
     */
    public static function peek(string $prefix, string $table, string $column = 'document_number'): string
    {
        return self::format($prefix, self::countToday($prefix, $table, $column) + 1);
    }

    /**
     * Nomor final saat menyimpan, dijamin belum terpakai.
     *
     * Mengulang pencarian bila nomor kandidat ternyata sudah dipakai — itu
     * terjadi saat dua orang menyimpan hampir bersamaan. Kolom
     * `document_number` tetap berconstraint UNIQUE sebagai pengaman terakhir
     * di sisi basis data.
     */
    public static function reserve(string $prefix, string $table, string $column = 'document_number'): string
    {
        $sequence = self::countToday($prefix, $table, $column) + 1;

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $candidate = self::format($prefix, $sequence + $attempt);

            if (! DB::table($table)->where($column, $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            "Gagal membangkitkan nomor dokumen {$prefix}: 50 nomor berurutan sudah terpakai."
        );
    }

    public static function format(string $prefix, int $sequence, ?Carbon $date = null): string
    {
        return sprintf('%s-%s-%03d', $prefix, ($date ?? now())->format('ymd'), $sequence);
    }

    /** Jumlah dokumen berawalan sama yang sudah dibuat hari ini. */
    private static function countToday(string $prefix, string $table, string $column): int
    {
        return DB::table($table)
            ->where($column, 'LIKE', $prefix.'-'.now()->format('ymd').'-%')
            ->count();
    }
}
