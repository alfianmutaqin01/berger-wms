<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\UploadDeliveryProofRequest;
use App\Models\DeliveryProof;
use App\Models\SalesOrder;
use App\Support\Outbound\ProofOfDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Sales mengunggah bukti Surat Jalan bertanda tangan — PRD §6.5 F-OUT-05.
 *
 * DIKERJAKAN DARI HP, DI DEPAN TOKO. Itu bukan catatan tampilan melainkan
 * batasan rancangan: tidak ada halaman unggah tersendiri, tidak ada langkah
 * pratinjau, tidak ada pilihan apa pun selain memilih foto. Formulirnya
 * menempel di halaman detail pesanan yang memang sedang dibuka Sales, dan
 * tombol kameranya membuka kamera langsung lewat atribut HTML5 `capture`.
 *
 * Kepemilikan dijawab 404, BUKAN 403 — aturan yang sama dengan seluruh
 * Portal Sales: 403 mengakui bahwa pesanan itu ada.
 */
class DeliveryProofController extends Controller
{
    public function __construct(private readonly ProofOfDelivery $bukti) {}

    public function store(UploadDeliveryProofRequest $request, SalesOrder $order): RedirectResponse
    {
        $this->pastikanMilikSendiri($request, $order);

        try {
            $jumlah = $this->bukti->upload(
                $order,
                $request->file('photos'),
                $request->user()->id,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf(
            '%d foto Surat Jalan terkirim. Logistik akan memeriksanya.',
            $jumlah,
        ));
    }

    /** Menampilkan kembali foto yang sudah diunggah. Berkasnya privat. */
    public function preview(Request $request, DeliveryProof $proof)
    {
        $this->pastikanBuktiMilikSendiri($request, $proof);

        return $this->bukti->response($proof);
    }

    /* ------------------------------------------------------------- Dalam */

    private function pastikanMilikSendiri(Request $request, SalesOrder $order): void
    {
        abort_unless($order->user_id === $request->user()->id, 404);
    }

    private function pastikanBuktiMilikSendiri(Request $request, DeliveryProof $proof): void
    {
        abort_unless($proof->salesOrder?->user_id === $request->user()->id, 404);
    }
}
