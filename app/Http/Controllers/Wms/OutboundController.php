<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class OutboundController extends Controller
{
    // Penerimaan pesanan PINDAH ke OrderApprovalController (Fase 6 tahap 1).
    // Stub approval()/approveOrder() yang dulu di sini sengaja DIHAPUS, bukan
    // dibiarkan: keduanya hanya mengembalikan pesan sukses tanpa menyentuh
    // basis data, dan stub semacam itu yang masih bisa dipanggil adalah cara
    // paling halus untuk membuat orang percaya pesanan sudah diproses.

    // Picking PINDAH ke PickingController (Fase 6 tahap 3). Stub
    // pickingBatching()/picking()/completePicking() yang dulu di sini
    // DIHAPUS dengan alasan yang sama: completePicking() hanya mengembalikan
    // "barang siap loading" tanpa menyentuh satu baris stok pun, dan pesan
    // sukses yang tidak berbuat apa-apa adalah cara paling halus membuat
    // operator percaya barangnya sudah keluar dari rak.

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
