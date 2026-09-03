<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\ReportPickingShortageRequest;
use App\Http\Requests\Wms\StorePickingListRequest;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\SalesOrder;
use App\Support\Outbound\PickingListBuilder;
use App\Support\Outbound\PickingRun;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Picking — PRD §6.5 F-OUT-03, Fase 6 tahap 3.
 *
 * DUA LAYAR UNTUK DUA ORANG, dan itu sebabnya keduanya ada di satu controller
 * tetapi di balik hak akses yang berbeda:
 *
 *   batching()  Logistik  menyusun daftar: pesanan mana berangkat bersama
 *   queue()     Operator  mengerjakannya: ambil tugas, jalan, tandai
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
 * queue()    : $tugas Collection<PickingList>, $milikSaya ?PickingList
 * show()     : $list PickingList, $baris Collection<PickingListItem>,
 *              $ringkas array, $bolehDikerjakan bool
 */
class PickingController extends Controller
{
    public function __construct(
        private readonly PickingListBuilder $penyusun,
        private readonly PickingRun $picking,
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
            ->with(['warehouse:id,code,name', 'createdBy:id,full_name', 'claimedBy:id,full_name'])
            ->withCount(['orders', 'items'])
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

    /** Membubarkan daftar yang belum tersentuh; pesanannya kembali ke antrean. */
    public function cancel(Request $request, PickingList $list): RedirectResponse
    {
        WarehouseScope::assert($list->warehouse_id, $request->user());

        $data = $request->validate([
            'cancellation_reason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [], ['cancellation_reason' => 'alasan pembubaran']);

        try {
            $this->penyusun->cancel($list, $data['cancellation_reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('wms.picking.batching')
            ->with('success', sprintf(
                'Daftar %s dibubarkan. Pesanannya kembali ke antrean dan bisa disusun ulang.',
                $list->list_number
            ));
    }

    /* ------------------------------------------------------------ Operator */

    /** Antrean tugas operator: daftar yang bebas + yang sedang ia pegang. */
    public function queue(Request $request): View
    {
        $user = $request->user();

        $tugas = WarehouseScope::apply(PickingList::query(), $user)
            ->aktif()
            ->with(['warehouse:id,code,name', 'claimedBy:id,full_name'])
            ->withCount(['orders', 'items'])
            ->orderBy('created_at')
            ->get();

        return view('wms.outbound.picking', [
            'tugas' => $tugas,
            'milikSaya' => $tugas->firstWhere('claimed_by', $user?->id),
        ]);
    }

    /** Rincian satu daftar — dipakai Logistik maupun Operator. */
    public function show(Request $request, PickingList $list): View
    {
        WarehouseScope::assert($list->warehouse_id, $request->user());

        $baris = $list->items()
            ->with(['product:id,sku,name,uom', 'location:id,code', 'salesOrder.customer:id,code,name'])
            // Urutan berjalan operator: menurut kode rak, dari A ke belakang
            // (F-OUT-03 #3). Diurutkan lewat join supaya yang menentukan
            // adalah KODE raknya, bukan id barisnya.
            ->join('locations', 'locations.id', '=', 'picking_list_items.location_id')
            ->orderBy('locations.code')
            ->orderBy('picking_list_items.id')
            ->select('picking_list_items.*')
            ->get();

        return view('wms.outbound.picking-detail', [
            'list' => $list->load(['warehouse:id,code,name', 'createdBy:id,full_name',
                'claimedBy:id,full_name', 'completedBy:id,full_name',
                'orders.customer:id,code,name']),
            'baris' => $baris,
            'ringkas' => [
                'total' => $baris->count(),
                'selesai' => $baris->where('status', '<>', PickingListItem::STATUS_PENDING)->count(),
                'kurang' => $baris->where('status', PickingListItem::STATUS_SHORT)->count(),
                'qty' => $baris->sum('qty_to_pick'),
            ],
            'bolehDikerjakan' => $list->status === PickingList::STATUS_PICKING
                && $list->claimed_by === $request->user()?->id,
        ]);
    }

    public function claim(Request $request, PickingList $list): RedirectResponse
    {
        WarehouseScope::assert($list->warehouse_id, $request->user());

        try {
            $this->picking->claim($list, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('wms.picking.show', $list)
            ->with('success', sprintf('Daftar %s sekarang tugas Anda. Selamat berjalan.', $list->list_number));
    }

    /** Jalur cepat: satu ketuk, barangnya lengkap sesuai daftar. */
    public function pick(Request $request, PickingList $list, PickingListItem $item): RedirectResponse
    {
        $this->pastikanBarisMilikDaftar($request, $list, $item);

        try {
            $this->picking->pick($item, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf(
            '%s di rak %s ditandai terambil.',
            $item->product?->sku ?? 'Baris',
            $item->location?->code ?? '—'
        ));
    }

    /** Pintu terpisah untuk keadaan khusus: barang di rak kurang. */
    public function short(ReportPickingShortageRequest $request, PickingList $list, PickingListItem $item): RedirectResponse
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
            return back()->with('error', $e->getMessage());
        }

        return back()->with('warning', sprintf(
            'Selisih dicatat: %s di rak %s tertulis %d, ditemukan %d. Selisihnya akan menjadi koreksi stok saat Siap Loading.',
            $item->product?->sku ?? 'Baris',
            $item->location?->code ?? '—',
            $item->qty_to_pick,
            (int) $request->validated('qty_picked'),
        ));
    }

    /** Membatalkan penandaan satu baris — operator salah ketuk. */
    public function reset(Request $request, PickingList $list, PickingListItem $item): RedirectResponse
    {
        $this->pastikanBarisMilikDaftar($request, $list, $item);

        try {
            $this->picking->resetItem($item, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Tanda pada baris itu dibatalkan.');
    }

    /** "Siap Loading" — stok berkurang, pesanan berpindah ke Siap Kirim. */
    public function complete(Request $request, PickingList $list): RedirectResponse
    {
        WarehouseScope::assert($list->warehouse_id, $request->user());

        try {
            $hasil = $this->picking->complete($list, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $pesan = sprintf(
            'Daftar %s selesai. %d unit turun dari rak dan siap dimuat.',
            $list->list_number,
            $hasil['diambil'],
        );

        if ($hasil['kurang'] > 0) {
            // Selisih TIDAK boleh lewat sebagai pesan sukses biasa. Angka
            // stok baru saja dikoreksi turun, dan yang mengoreksinya adalah
            // temuan di rak — itu perlu dibaca seseorang, bukan disembunyikan
            // di balik kalimat "selesai".
            return redirect()->route('wms.picking.queue')->with('warning', $pesan.sprintf(
                ' %d unit TIDAK ditemukan di rak dan sudah dicatat sebagai koreksi stok — periksa di Riwayat Mutasi.',
                $hasil['kurang']
            ));
        }

        return redirect()->route('wms.picking.queue')->with('success', $pesan);
    }

    /* --------------------------------------------------------------- Dalam */

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
