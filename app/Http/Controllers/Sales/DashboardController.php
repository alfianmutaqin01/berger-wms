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
 */
class DashboardController extends Controller
{
    public function index(Request $request, SalesDashboard $dashboard): View
    {
        return view('sales.dashboard', [
            'm' => $dashboard->untuk($request->user()),
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
