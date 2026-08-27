<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;

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

    public function admin()
    {
        return view('wms.dashboard.admin');
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
