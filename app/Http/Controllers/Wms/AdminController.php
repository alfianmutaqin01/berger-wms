<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;

class AdminController extends Controller
{
    // Manajemen User dipindahkan ke UserController (CRUD penuh + validasi).
    public function sequence()
    {
        return view('wms.admin.sequence');
    }
}
