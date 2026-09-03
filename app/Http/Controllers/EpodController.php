<?php

namespace App\Http\Controllers;

use App\Models\DeliveryNote;
use App\Support\Outbound\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Konfirmasi penerimaan oleh supir — PRD §6.5 F-OUT-04 #10.
 *
 * SATU-SATUNYA HALAMAN PUBLIK YANG MENGUBAH DATA. Supir tidak punya akun dan
 * tidak akan pernah punya: ia berganti setiap hari dan sebagian besar dari
 * perusahaan jasa lain. Karena itu TOKEN-nya yang menjadi kunci, dan
 * konsekuensinya menentukan seluruh rancangan halaman ini:
 *
 *   - Token dicari sebagai KOLOM, bukan disusun dari id. Tautan yang bisa
 *     ditebak dari nomor urut membuat siapa pun bisa mengonfirmasi kiriman
 *     orang lain.
 *   - Token yang tidak dikenal dijawab 404 POLOS, tanpa menyebut apa pun
 *     tentang dokumen yang ada. Halaman ini terbuka ke internet.
 *   - Yang bisa dilakukan hanya SATU: menyatakan barang sampai. Tidak ada
 *     daftar, tidak ada pencarian, tidak ada data pelanggan lain.
 *   - Yang ditampilkan seperlunya saja untuk supir memastikan ia membuka
 *     kiriman yang benar — bukan seluruh isi pesanan berikut harganya.
 */
class EpodController extends Controller
{
    public function __construct(private readonly Shipment $pengiriman) {}

    public function show(string $token): View
    {
        return view('driver.epod', [
            'note' => $this->cari($token),
        ]);
    }

    public function confirm(Request $request, string $token): RedirectResponse
    {
        $note = $this->cari($token);

        $data = $request->validate([
            // Tidak wajib: supir sering tidak sempat menanyakan nama
            // penerima, dan menahan konfirmasi karenanya berarti pengiriman
            // yang sudah sampai tidak pernah tercatat sampai.
            'received_by_name' => ['nullable', 'string', 'max:100'],
        ], [], ['received_by_name' => 'nama penerima']);

        try {
            $this->pengiriman->confirmDelivery($note, $data['received_by_name'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('epod.show', $token)
            ->with('success', 'Terima kasih. Pengiriman ini sudah tercatat sampai di tujuan.');
    }

    /**
     * Dokumen pemegang token ini.
     *
     * 404 untuk token yang tidak dikenal MAUPUN dokumen yang belum berangkat:
     * keduanya dijawab sama supaya halaman publik ini tidak bisa dipakai
     * menebak-nebak token mana yang ada.
     */
    private function cari(string $token): DeliveryNote
    {
        $note = DeliveryNote::query()
            ->with(['lines.product:id,sku,name,uom', 'customer:id,name'])
            ->where('epod_token', $token)
            ->whereIn('status', [DeliveryNote::STATUS_SHIPPED, DeliveryNote::STATUS_DELIVERED])
            ->first();

        abort_if($note === null, 404);

        return $note;
    }
}
