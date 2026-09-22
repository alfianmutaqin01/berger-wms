<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\ReportPickingShortageRequest;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Support\Outbound\PickingRun;
use App\Support\WarehouseScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Picking — menandai satu baris daftar: diambil, kurang, atau diulang.
 *
 * Dijawab JSON untuk layar dan redirect ber-anchor sebagai jalan mundur;
 * alasannya di komentar di atas berhasil().
 */
class PickingLineController extends Controller
{
    public function __construct(
        private readonly PickingRun $picking,
    ) {}

    /** Jalur cepat: satu ketuk, barangnya lengkap sesuai daftar. */
    public function pick(Request $request, PickingList $list, PickingListItem $item)
    {
        $this->pastikanBarisMilikDaftar($request, $list, $item);

        try {
            $this->picking->pick($item, $request->user());
        } catch (RuntimeException $e) {
            return $this->gagal($request, $item, $e->getMessage());
        }

        return $this->berhasil($request, $list, $item, 'success', sprintf(
            '%s di rak %s ditandai terambil.',
            $item->product?->sku ?? 'Baris',
            $item->location?->code ?? '—'
        ));
    }

    /** Pintu terpisah untuk keadaan khusus: barang di rak kurang. */
    public function short(ReportPickingShortageRequest $request, PickingList $list, PickingListItem $item)
    {
        $this->pastikanBarisMilikDaftar($request, $list, $item);

        try {
            $this->picking->reportShort(
                $item,
                $request->user(),
                (int) $request->validated('qty_picked'),
                $request->validated('discrepancy_reason'),
            );
        } catch (RuntimeException $e) {
            return $this->gagal($request, $item, $e->getMessage());
        }

        return $this->berhasil($request, $list, $item, 'warning', sprintf(
            'Selisih dicatat: %s di rak %s tertulis %d, ditemukan %d. Selisihnya akan menjadi koreksi stok saat Siap Loading.',
            $item->product?->sku ?? 'Baris',
            $item->location?->code ?? '—',
            $item->qty_to_pick,
            (int) $request->validated('qty_picked'),
        ));
    }

    /** Membatalkan penandaan satu baris — operator salah ketuk. */
    public function reset(Request $request, PickingList $list, PickingListItem $item)
    {
        $this->pastikanBarisMilikDaftar($request, $list, $item);

        try {
            $this->picking->resetItem($item, $request->user());
        } catch (RuntimeException $e) {
            return $this->gagal($request, $item, $e->getMessage());
        }

        return $this->berhasil($request, $list, $item, 'success', 'Tanda pada baris itu dibatalkan.');
    }

    /* --------------------------------------------------------------- Dalam */

    /*
     | MENANDAI BARIS TIDAK BOLEH MEMUAT ULANG HALAMAN.
     |
     | Temuan lapangan pemilik produk: satu daftar bisa berisi 100 baris.
     | Kalau tiap ketukan memuat ulang halaman, operator yang sedang di baris
     | ke-80 dilempar kembali ke atas dan harus menggulir turun lagi — seratus
     | kali dalam satu tugas. Itu bukan gangguan kecil; itu membuat orang
     | berhenti menandai baris satu per satu dan mulai menandainya sekaligus
     | di akhir dari ingatan, yang persis menghapus gunanya penandaan ini.
     |
     | Karena itu kedua jawaban disediakan:
     |   - JSON  untuk layar, yang memperbarui satu baris saja tanpa berpindah
     |   - redirect BER-ANCHOR sebagai jalan mundur bila JavaScript mati,
     |     supaya halaman setidaknya kembali ke baris yang barusan ditekan
     |
     | Jalan mundurnya bukan basa-basi: HP gudang sering tua, dan layar yang
     | hanya bekerja dengan JavaScript berarti tugas yang tidak bisa
     | diselesaikan sama sekali.
     */

    /** @return JsonResponse|RedirectResponse */
    private function berhasil(Request $request, PickingList $list, PickingListItem $item, string $jenis, string $pesan)
    {
        $item->refresh();

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'jenis' => $jenis,
                'pesan' => $pesan,
                'item' => [
                    'id' => $item->id,
                    'status' => $item->status,
                    'qty_picked' => $item->qty_picked,
                    'qty_kurang' => $item->qty_kurang,
                    'alasan' => $item->discrepancy_reason,
                ],
                'ringkas' => $this->ringkasan($list),
            ]);
        }

        return back()->withFragment('baris-'.$item->id)->with($jenis, $pesan);
    }

    /** @return JsonResponse|RedirectResponse */
    private function gagal(Request $request, PickingListItem $item, string $pesan)
    {
        if ($request->expectsJson()) {
            // 422, bukan 200 berisi ok:false — layar memakai response.ok
            // untuk memutuskan, dan galat yang dikirim sebagai sukses akan
            // menandai baris di layar padahal servernya menolak.
            return response()->json(['ok' => false, 'pesan' => $pesan], 422);
        }

        return back()->withFragment('baris-'.$item->id)->with('error', $pesan);
    }

    /** @return array{total:int, selesai:int, kurang:int} */
    private function ringkasan(PickingList $list): array
    {
        $perStatus = $list->items()
            ->selectRaw('status, COUNT(*) AS jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');

        return [
            'total' => (int) $perStatus->sum(),
            'selesai' => (int) $perStatus->sum() - (int) $perStatus->get(PickingListItem::STATUS_PENDING, 0),
            'kurang' => (int) $perStatus->get(PickingListItem::STATUS_SHORT, 0),
        ];
    }

    /**
     * Baris HARUS milik daftar di URL-nya.
     *
     * Tanpa ini, /picking/{daftar A}/item/{baris milik daftar B} lolos:
     * pemeriksaan gudang membaca daftar A yang memang boleh, lalu yang
     * ditandai adalah baris daftar B milik operator lain.
     */
    private function pastikanBarisMilikDaftar(Request $request, PickingList $list, PickingListItem $item): void
    {
        WarehouseScope::assert($list->warehouse_id, $request->user());

        abort_unless($item->picking_list_id === $list->id, 404);
    }
}
