<?php

namespace App\Http\Middleware;

use App\Support\Bahasa;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman bertautan selalu bahasa Indonesia — keputusan pemilik produk.
 *
 * Yang membukanya supir, atasan produksi, dan pelanggan di Indonesia. Mereka
 * tidak punya akun, tidak pernah memilih bahasa, dan sedang berdiri di depan
 * gudang memegang HP. Menawarkan pilihan bahasa di sana hanya menambah satu
 * keputusan pada orang yang sedang ingin cepat selesai.
 *
 * DIPAKSA, BUKAN DIBIARKAN IKUT BAWAAN — dan di situlah letak gunanya.
 * Halaman-halaman ini berada di dalam middleware `web`, jadi mereka ikut
 * membaca session peramban yang sama. Logistik yang memilih English lalu
 * membuka tautan ePOD untuk memeriksanya akan melihat halaman berbahasa
 * Inggris; lebih buruk lagi, tangkapan layar yang ia kirim ke supir menjadi
 * halaman yang bukan halaman yang dilihat supirnya.
 */
class PaksaBahasaIndonesia
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(Bahasa::INDONESIA);

        return $next($request);
    }
}
