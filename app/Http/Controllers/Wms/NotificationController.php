<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;

class NotificationController extends Controller
{
    public function index()
    {
        return view('wms.notifications');
    }
}
