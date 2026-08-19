<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class OutboundController extends Controller
{
    public function approval()
    {
        return view('wms.outbound.approval');
    }

    public function approveOrder(Request $request, $id)
    {
        return redirect()->back()->with('success', "PO-$id berhasil di-approve (Auto-Adjustment diterapkan jika stok kurang).");
    }

    public function picking()
    {
        return view('wms.outbound.picking');
    }

    public function completePicking(Request $request, $id)
    {
        return redirect()->back()->with('success', "Proses picking untuk PO-$id selesai. Barang siap loading.");
    }

    public function delivery()
    {
        return view('wms.outbound.delivery');
    }

    public function generateSuratJalan(Request $request, $id)
    {
        $noWa = $request->input('wa_supir', '08123456789');
        $dummyLink = url("/epod/$id");
        return redirect()->back()->with('success', "Surat Jalan berhasil dicetak. Tautan Konfirmasi E-POD telah dikirim ke WA Supir ($noWa).")
                                 ->with('epod_link', $dummyLink);
    }

    public function verification()
    {
        return view('wms.outbound.verification');
    }

    public function verifyBukti(Request $request, $id)
    {
        return redirect()->back()->with('success', "Bukti Surat Jalan PO-$id berhasil diverifikasi. Pesanan selesai sepenuhnya.");
    }
}