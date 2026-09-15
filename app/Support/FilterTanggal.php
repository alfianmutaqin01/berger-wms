<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Tanggal dari filter URL, dibersihkan sebelum menyentuh query — temuan SQA.
 *
 * Filter tanggal di halaman daftar diteruskan mentah ke whereDate(). Nilai
 * seperti `2026-13-45` atau `abc` ditolak PostgreSQL dengan galat, dan
 * halamannya jatuh 500. Yang benar untuk filter: tanggal yang tidak masuk
 * akal diabaikan, daftarnya tetap tampil.
 *
 * KETAT Y-m-d, tidak memakai Carbon::parse(): parse() menerima "next monday"
 * dan "-1", yang sebagai filter lebih membingungkan daripada diabaikan.
 */
final class FilterTanggal
{
    public static function bersih(mixed $nilai): ?string
    {
        if (! is_string($nilai) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai)) {
            return null;
        }

        $tanggal = Carbon::createFromFormat('!Y-m-d', $nilai);

        // createFromFormat menggulirkan 2026-02-31 menjadi 3 Maret; tanggal
        // yang tidak kembali ke teks aslinya berarti tidak pernah ada.
        return $tanggal !== false && $tanggal->format('Y-m-d') === $nilai ? $nilai : null;
    }
}
