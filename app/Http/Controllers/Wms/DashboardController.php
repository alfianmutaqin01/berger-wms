<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;

class DashboardController extends Controller
{
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
