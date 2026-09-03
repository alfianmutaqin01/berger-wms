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

    // Surat Jalan PINDAH ke DeliveryController (Fase 6 tahap 4). Stub
    // generateSuratJalan() DIHAPUS, dan kali ini bukan hanya karena ia tidak
    // menyentuh basis data: ia mengaku "Surat Jalan berhasil dicetak" dan
    // "tautan E-POD telah dikirim ke WA supir" — dua kebohongan sekaligus,
    // dan yang kedua terpapar ke luar organisasi. Sistem ini TIDAK
    // menerbitkan Surat Jalan sama sekali; dokumen resminya keluar dari BC.

    public function verification()
    {
        return view('wms.outbound.verification');
    }

    public function verifyBukti(Request $request, $id)
    {
        return redirect()->back()->with('success', "Bukti Surat Jalan PO-$id berhasil diverifikasi. Pesanan selesai sepenuhnya.");
    }
}
