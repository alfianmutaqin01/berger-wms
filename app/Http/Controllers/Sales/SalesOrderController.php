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
}