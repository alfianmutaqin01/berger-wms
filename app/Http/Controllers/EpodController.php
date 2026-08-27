<?php

namespace App\Http\Controllers;

class EpodController extends Controller
{
    public function show($po_number)
    {
        return view('driver.epod', compact('po_number'));
    }

    public function confirm($po_number)
    {
        return redirect()->back()->with('success', 'Barang berhasil dikonfirmasi sampai di tujuan. Terima kasih!');
    }
}
