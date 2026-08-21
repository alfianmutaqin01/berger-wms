<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SalesOrderController extends Controller
{
    public function create()
    {
        return view('sales.new_order');
    }

    public function store(Request $request)
    {
        // Dummy logic: Redirect back to my_orders with a success message
        return redirect('/sales/my-orders')->with('success', 'Pesanan berhasil dibuat.');
    }

    public function history()
    {
        return view('sales.my_orders');
    }
    public function reportReturn(Request $request)
    {
        $retur = [
            'id' => 'RET-' . rand(1000, 9999),
            'po' => $request->po_number,
            'customer' => $request->customer,
            'sku' => $request->sku,
            'qty' => $request->qty,
            'reason' => $request->reason,
            'time' => now()->format('H:i') . ' WIB',
            'date' => 'Hari Ini'
        ];
        session()->push('pending_returns', $retur);
        session()->put('po_has_return_' . $request->po_number, true);
        return redirect()->back()->with('success', 'Laporan Retur Kendala untuk ' . $request->po_number . ' telah dikirim ke Gudang.');
    }
    
    public function tracking() { return view('sales.tracking'); }
    public function customers() { return view('sales.customers'); }
}