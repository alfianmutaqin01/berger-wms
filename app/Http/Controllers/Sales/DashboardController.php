<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Support\OrderCutoff;
use App\Support\Reporting\SalesDashboard;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dashboard Portal Sales.
 *
 * DULUNYA CLOSURE DI routes/web.php yang hanya mengembalikan view — karena
 * memang tidak ada apa pun yang perlu dihitung; seluruh angkanya ditulis
 * tangan di dalam Blade. Begitu angkanya menjadi nyata, ia butuh tempat yang
 * bisa diuji dan dibaca, bukan baris di tengah daftar rute.
 *
 * DATA CONTRACT (view: sales.dashboard)
 *   $m          : lihat App\Support\Reporting\SalesDashboard::untuk()
 *   $cutoffOpen : bool — batas jam submit hari ini masih terbuka?
 *   $cutoffLabel: string — jamnya, mis. "15:00 WIB"
 *   $promo      : list<array> — 0 sampai 3 slide iklan, lihat config/wms.php
 */
class DashboardController extends Controller
{
    /**
     * Slide iklan paling banyak yang digambar.
     *
     * Dipotong DI SINI, bukan dipercayakan kepada yang mengisi config: empat
     * slide di layar HP tidak pernah terbaca sampai habis, dan yang keempat
     * hanya memperlambat sisanya berganti.
     */
    private const MAKS_PROMO = 3;

    public function index(Request $request, SalesDashboard $dashboard): View
    {
        return view('sales.dashboard', [
            'm' => $dashboard->untuk($request->user()),
            // Disaring ke baris yang benar-benar berupa daftar. Isi config ini
            // akan disunting orang yang tidak membaca Blade-nya; satu entri
            // yang terlanjur ditulis sebagai teks akan membuat layar Sales
            // memunculkan peringatan PHP, bukan promo.
            'promo' => array_slice(
                array_values(array_filter(
                    (array) config('wms.promo_sales', []),
                    fn ($slide) => is_array($slide),
                )),
                0,
                self::MAKS_PROMO,
            ),
            // Batas jam ikut dikirim karena inilah yang mengubah "ada 3 draft"
            // dari catatan kecil menjadi hal yang mendesak: lewat pukul 15:00,
            // draft yang belum disubmit mundur satu hari penuh. Halaman
            // Pesanan Saya sudah memakai keduanya — dashboard tidak boleh
            // memberi kabar yang berbeda.
            'cutoffOpen' => OrderCutoff::isOpen(),
            'cutoffLabel' => OrderCutoff::label(),
        ]);
    }
}
