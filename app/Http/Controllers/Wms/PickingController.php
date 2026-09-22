<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\StorePickingListRequest;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\SalesOrder;
use App\Support\Outbound\PickingListBuilder;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Picking — PRD §6.5 F-OUT-03, Fase 6 tahap 3: sisi Logistik.
 *
 * DUA LAYAR UNTUK DUA ORANG, di balik hak akses yang berbeda:
 *
 *   batching()  Logistik  menyusun daftar: pesanan mana berangkat bersama
 *   queue()     Operator  mengerjakannya: ambil tugas, jalan, tandai
 *               (PickingTaskController, PickingLineController,
 *               PickingCompletionController)
 *
 * Wewenangnya sengaja tidak sama. Yang menentukan isi container bukan yang
 * berjalan ke rak — menyatukannya berarti operator bisa memilih sendiri
 * pesanan mana yang ia kerjakan hari ini.
 *
 * PEMBATASAN GUDANG. Daftar picking adalah pekerjaan fisik di satu bangunan,
 * jadi ia tidak pernah lintas gudang seperti transfer. Penyaringannya cukup
 * WarehouseScope::apply() biasa, dan tiap titik masuk yang menerima satu
 * objek memanggil assert() — menyaring daftar saja tidak menutup URL detail.
 *
 * DATA CONTRACT
 * -------------
 * batching() : $lists LengthAwarePaginator<PickingList>, $antrean
 *              Collection<SalesOrder>, $gudangSaya, $filters
 */
class PickingController extends Controller
{
    public function __construct(
        private readonly PickingListBuilder $penyusun,
    ) {}

    /* ------------------------------------------------------------ Logistik */

    /** Layar penyusunan daftar: antrean pesanan siap picking + daftar berjalan. */
    public function batching(Request $request): View
    {
        $user = $request->user();
        $gudang = WarehouseScope::resolveFilter($request, $user);

        $antrean = SalesOrder::query()
            ->where('status', SalesOrder::STATUS_APPROVED)
            // Yang sudah masuk daftar lain tidak boleh muncul lagi: satu
            // pesanan di dua daftar berarti barangnya diambil dua kali.
            ->whereNull('picking_list_id')
            ->when($gudang, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->with(['customer:id,code,name', 'warehouse:id,code,name'])
            ->withCount('details')
            // Terlama dulu: pesanan yang paling lama menunggu adalah yang
            // paling dekat melanggar SLA (§7.6).
            ->orderBy('approved_at')
            ->get();

        $lists = WarehouseScope::apply(PickingList::query(), $user)
            ->when($gudang, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->with(['warehouse:id,code,name', 'createdBy:id,full_name', 'claimedBy:id,full_name',
                'transfer:id,picking_list_id,transfer_number,to_warehouse_id',
                'transfer.toWarehouse:id,code,name',
                'requisition:id,picking_list_id,mrf_number,request_type,department_name'])
            ->withCount(['orders', 'items',
                // Dibaca PickingList::bolehDibatalkan() untuk tombol Batal.
                'items as items_tersentuh_count' => fn ($q) => $q->where('status', '<>', PickingListItem::STATUS_PENDING)])
            // Yang masih perlu dikerjakan selalu di atas.
            ->orderByRaw("CASE WHEN status IN ('open', 'picking') THEN 0 ELSE 1 END")
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return view('wms.outbound.picking-batching', [
            'antrean' => $antrean,
            'lists' => $lists,
            'gudangSaya' => $user?->warehouse,
            'gudangOptions' => WarehouseScope::options($user),
            'filters' => ['warehouse_id' => $gudang],
        ]);
    }

    public function store(StorePickingListRequest $request): RedirectResponse
    {
        $user = $request->user();

        try {
            $daftar = $this->penyusun->build(
                warehouseId: (int) $request->validated('warehouse_id'),
                orderIds: array_map('intval', $request->validated('order_ids')),
                catatan: $request->validated('notes'),
                userId: $user?->id,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('wms.picking.show', $daftar)
            ->with('success', sprintf(
                'Daftar picking %s dibuat: %d pesanan, %d baris pengambilan. Operator sudah bisa mengambilnya.',
                $daftar->list_number,
                $daftar->orders()->count(),
                $daftar->items()->count(),
            ));
    }

    /** Membatalkan daftar yang belum tersentuh; pesanannya kembali ke antrean. */
    public function cancel(Request $request, PickingList $list): RedirectResponse
    {
        WarehouseScope::assert($list->warehouse_id, $request->user());

        $data = $request->validate([
            'cancellation_reason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [], ['cancellation_reason' => 'alasan pembatalan']);

        try {
            $this->penyusun->cancel($list, $data['cancellation_reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('wms.picking.batching')
            ->with('success', sprintf(
                'Daftar %s dibatalkan. Pesanannya kembali ke antrean dan bisa disusun ulang.',
                $list->list_number
            ));
    }
}
