<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\Reporting\AdminDashboard;
use App\Support\Reporting\OperatorDashboard;
use App\Support\Reporting\ProductionDashboard;
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

    /**
     * Dashboard Produksi — empat angka, dan batasnya disengaja.
     *
     * Alasan lengkap kenapa angka lamanya (target produksi, mesin aktif,
     * bahan baku) dibuang sepenuhnya ada di App\Support\Reporting\
     * ProductionDashboard: tidak satu pun modulnya ada di sistem ini.
     */
    public function produksi(Request $request, ProductionDashboard $dashboard)
    {
        $user = $request->user();

        return view('wms.dashboard.produksi', [
            'm' => $dashboard->untuk($user),
            'gudang' => $user?->warehouse?->display_label,
        ]);
    }

    /**
     * Dashboard Operator — daftar pekerjaan, bukan laporan.
     *
     * Kartu yang tidak ada pekerjaannya sengaja tidak digambar. Lihat
     * App\Support\Reporting\OperatorDashboard.
     */
    public function operator(Request $request, OperatorDashboard $dashboard)
    {
        $user = $request->user();

        return view('wms.dashboard.operator', [
            'm' => $dashboard->untuk($user),
            'gudang' => $user?->warehouse?->display_label,
        ]);
    }
}
