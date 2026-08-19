<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function index()
    {
        return view('wms.billing.index');
    }

    public function confirm(Request $request, $id)
    {
        $date = $request->input('payment_date');
        return redirect()->back()->with('success', "Pembayaran untuk PO-$id pada tanggal $date telah berhasil diverifikasi. Status pelanggan telah dipulihkan.");
    }
}