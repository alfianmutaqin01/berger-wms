<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Membuang parameter query string yang bentuknya mustahil — temuan SQA.
 *
 * Seluruh halaman daftar membaca filternya dengan `$request->query('x')` dan
 * meneruskannya mentah ke query atau ke fungsi bertipe teks. Tim SQA menemukan
 * dua bentuk yang menjatuhkan halaman dengan galat 500 alih-alih menampilkan
 * daftar tanpa filter:
 *
 *   1. ARRAY — `?search[]=x` dilempar ke scopeSearch(?string), trim(),
 *      (string). Pesanan Saya, Riwayat Produksi, pencarian customer & produk.
 *
 *   2. `*_id` YANG BUKAN ANGKA — `?category_id=abc` diteruskan ke kolom
 *      bigint dan ditolak PostgreSQL. Stok, Master Produk, Log Aktivitas,
 *      Manajemen User.
 *
 * Sumbernya bisa tautan yang diubah tangan, tautan yang terpotong saat
 * dibagikan lewat chat, atau pemindai keamanan. Memperbaikinya di tiap dari 73
 * pemanggilan query() hanya menunggu pemanggilan ke-74 yang lupa, jadi
 * penjaganya satu, di depan semua rute web.
 *
 * AMAN: tidak ada formulir GET di aplikasi ini yang sah mengirim array, dan
 * tidak ada `*_id` di URL yang sah berisi selain angka. Tanggal dibersihkan
 * terpisah oleh App\Support\FilterTanggal karena nama parameternya beragam.
 */
class NormalizeQueryString
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            $semula = $request->query->all();

            $bersih = array_filter(
                $semula,
                fn ($nilai, $kunci) => ! is_array($nilai)
                    && (! str_ends_with((string) $kunci, '_id') || $nilai === '' || preg_match('/^\d{1,18}$/', (string) $nilai)),
                ARRAY_FILTER_USE_BOTH,
            );

            if (count($bersih) !== count($semula)) {
                $request->query->replace($bersih);
            }
        }

        return $next($request);
    }
}
