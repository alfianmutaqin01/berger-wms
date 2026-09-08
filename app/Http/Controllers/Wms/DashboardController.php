<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\Reporting\AdminDashboard;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Dashboard yang sesuai untuk sebuah user.
     *
     * Dipakai bersama oleh dua tempat yang harus selalu sepakat: redirect
     * setelah login (AuthController) dan redirect /wms/dashboard. Tanpa ini,
     * Produksi/Operator akan dilempar ke dashboard utama yang tidak boleh
     * mereka akses lalu langsung ditolak 403 oleh gate.
     */
    public static function pathFor(?User $user): string
    {
        return match ($user?->role?->slug) {
            Role::SALES => '/sales/dashboard',
            Role::PRODUCTION => '/wms/dashboard/produksi',
            Role::WAREHOUSE_OPERATOR => '/wms/dashboard/operator',
            default => '/wms/dashboard/admin',
        };
    }

    /**
     * Dashboard utama — dibuka Super Admin, Manager, dan Logistik.
     *
     * SATU HALAMAN, TIGA SUDUT PANDANG. Yang berbeda bukan halamannya
     * melainkan kartu mana yang punya isi: AdminDashboard hanya menghitung
     * metrik yang boleh dilihat pemanggilnya, sehingga kartu yang tidak
     * berhak tidak pernah ikut terkirim ke layar. Lihat alasan lengkapnya di
     * App\Support\Reporting\AdminDashboard.
     */
    public function admin(Request $request, AdminDashboard $dashboard)
    {
        $user = $request->user();

        return view('wms.dashboard.admin', [
            'm' => $dashboard->untuk($user),
            'gudang' => $user?->warehouse?->display_label,
        ]);
    }

    public function produksi()
    {
        return view('wms.dashboard.produksi');
    }

    public function operator()
    {
        return view('wms.dashboard.operator');
    }
}
