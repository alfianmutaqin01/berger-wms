<?php

namespace App\Http\Controllers;

class EpodController extends Controller
{
    public function show($po_number)
    {
        return view('driver.epod', compact('po_number'));
    }

    /**
     * BELUM TERPASANG — dijadwalkan Fase 12 (E-POD).
     *
     * Sebelumnya method ini menjawab "Barang berhasil dikonfirmasi sampai di
     * tujuan" tanpa menyentuh apa pun: tidak ada status pesanan yang
     * berubah, tidak ada delivered_at yang terisi, tidak ada catatan yang
     * tersimpan. Driver akan pergi dengan keyakinan bahwa pengirimannya sudah
     * tercatat, dan Logistik tidak akan pernah melihatnya.
     *
     * Rute ini PUBLIK (tanpa login, sesuai rancangan tautan untuk driver),
     * sehingga kebohongan itu terpapar ke luar organisasi. Lebih baik
     * mengatakan belum tersedia daripada memberi tanda terima palsu.
     */
    public function confirm($po_number)
    {
        return redirect()->back()->with(
            'error',
            'Konfirmasi penerimaan belum dapat diproses lewat tautan ini. '.
            'Silakan hubungi tim Logistik untuk mencatatkan pengiriman ini.'
        );
    }
}
