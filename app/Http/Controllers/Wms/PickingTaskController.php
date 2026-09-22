<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Support\Activity;
use App\Support\Export\XlsxWriter;
use App\Support\Outbound\PickingRun;
use App\Support\Permission;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Picking — sisi Operator: antrean tugas, layar kerja satu daftar, unduhan
 * untuk dicocokkan dengan BC, serta mengambil dan melepas tugas.
 *
 * Menandai baris ada di PickingLineController; menyelesaikan daftar di
 * PickingCompletionController. Penyusunan daftar oleh Logistik ada di
 * PickingController.
 *
 * DATA CONTRACT
 * -------------
 * queue() : $tugas Collection<PickingList>, $milikSaya ?PickingList
 * show()  : $list PickingList, $baris Collection<PickingListItem>,
 *           $ringkas array, $bolehDikerjakan bool
 */
class PickingTaskController extends Controller
{
    public function __construct(
        private readonly PickingRun $picking,
    ) {}

    /* ------------------------------------------------------------ Operator */

    /** Antrean tugas operator: daftar yang bebas + yang sedang ia pegang. */
    public function queue(Request $request): View
    {
        $user = $request->user();

        $tugas = WarehouseScope::apply(PickingList::query(), $user)
            ->aktif()
            // transfer ikut dimuat supaya antrean operator bisa menyebut
            // dengan jelas mana tugas pesanan dan mana tugas kiriman antar
            // gudang. Keduanya pekerjaan yang sama di rak, tetapi barangnya
            // berakhir di kendaraan yang berbeda.
            ->with(['warehouse:id,code,name', 'claimedBy:id,full_name',
                'transfer:id,picking_list_id,transfer_number,to_warehouse_id',
                'transfer.toWarehouse:id,code,name',
                'requisition:id,picking_list_id,mrf_number,request_type,department_name'])
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
            // stock ikut dimuat demi penanda "Dahulukan Keluar": operator yang
            // melihat batch baru diambil sementara yang lama masih di rak akan
            // mengira daftarnya salah. Alasannya harus terbaca di kertas yang
            // ia bawa, bukan cuma tersimpan di layar Logistik.
            ->with(['product:id,sku,name,uom', 'location:id,code', 'salesOrder.customer:id,code,name',
                'transferDetail:id,stock_transfer_id,qty_requested',
                'allocation:id,material_requisition_id,qty_allocated',
                'stock:id,prioritize_out,prioritize_reason'])
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
                'orders.customer:id,code,name',
                'transfer.toWarehouse:id,code,name', 'transfer.fromWarehouse:id,code,name',
                'requisition.requestedBy:id,full_name']),
            'baris' => $baris,
            'ringkas' => [
                'total' => $baris->count(),
                'selesai' => $baris->where('status', '<>', PickingListItem::STATUS_PENDING)->count(),
                'kurang' => $baris->where('status', PickingListItem::STATUS_SHORT)->count(),
                'qty' => $baris->sum('qty_to_pick'),
            ],
            'bolehDikerjakan' => $list->status === PickingList::STATUS_PICKING
                && $list->claimed_by === $request->user()?->id,
            // Logistik/Manager melepas tugas milik ORANG LAIN. Sengaja tidak
            // ditampilkan untuk daftar yang ia pegang sendiri — untuk itu
            // tombolnya sudah ada di kelompok tombol operator.
            'bolehMelepasTugas' => $list->status === PickingList::STATUS_PICKING
                && $list->claimed_by !== $request->user()?->id
                && Gate::allows(Permission::OUTBOUND_PICKING_LIST),
            /*
             | Tempat yang boleh dipilih sebagai titik serah terima MRF.
             |
             | RAK TRANSIT DIDAHULUKAN, bukan disendirikan. Barang MRF hampir
             | selalu berhenti di salah satu dari dua titik transit, jadi
             | keduanya berdiri paling atas dan tidak perlu dicari. Tetapi
             | kenyataan di lantai gudang tidak selalu begitu — kadang barangnya
             | memang dititipkan di rak biasa — dan daftar yang menolak
             | menyebutkan tempat sebenarnya hanya melahirkan catatan yang
             | tidak cocok dengan keadaan.
             |
             | Hanya dimuat untuk daftar MRF: pada daftar pesanan dan transfer
             | barangnya naik kendaraan, dan daftar rak yang tidak pernah
             | dipakai cuma memperberat halaman yang dibuka dari HP gudang.
             */
            'rakSerah' => $list->requisition()->exists()
                ? Location::where('warehouse_id', $list->warehouse_id)
                    ->active()
                    ->orderByRaw('CASE WHEN zone IN (?, ?) THEN 0 ELSE 1 END', Location::ZONES_TRANSIT)
                    ->orderBy('code')
                    ->get(['id', 'code', 'zone'])
                : collect(),
        ]);
    }

    /**
     * Berkas .xlsx satu daftar picking, untuk dicocokkan dengan sistem BC.
     *
     * HANYA SETELAH SELESAI DIPICKING. Sebelum itu qty_picked masih berubah
     * setiap kali operator menandai satu baris, dan berkas yang keluar di
     * tengah jalan menyatakan "diambil 0" untuk barang yang lima menit lagi
     * sudah di troli. Berkas begitu tidak sekadar tidak berguna — ia beredar
     * ke luar sistem sebagai angka yang terlihat resmi, lalu dipakai
     * mencocokkan dan memunculkan selisih yang tidak pernah ada.
     *
     * YANG DIMUAT QTY DIMINTA DAN QTY DIAMBIL SEKALIGUS, berikut selisihnya.
     * Mencocokkan dengan BC berarti mencari beda, dan beda tidak bisa dicari
     * dari satu kolom saja — daftar yang cuma memuat "qty" memaksa yang
     * membacanya menebak qty yang mana.
     */
    public function download(Request $request, PickingList $list): StreamedResponse
    {
        WarehouseScope::assert($list->warehouse_id, $request->user());

        abort_unless(
            $list->status === PickingList::STATUS_COMPLETED,
            404,
            'Daftar ini belum selesai dipicking, jadi belum ada angka yang bisa dicocokkan.',
        );

        $list->load(['warehouse:id,code,name', 'claimedBy:id,full_name', 'completedBy:id,full_name',
            'transfer:id,picking_list_id,transfer_number',
            'requisition:id,picking_list_id,mrf_number,department_name']);

        $baris = $list->items()
            ->with(['product:id,sku,name,uom', 'location:id,code',
                'salesOrder:id,order_number,bc_so_number,customer_id',
                'salesOrder.customer:id,code,name'])
            // Urutan yang sama dengan layarnya: yang mencocokkan berkas ini
            // dengan kertas yang dibawa operator tidak boleh harus mengurutkan
            // ulang lebih dulu.
            ->join('locations', 'locations.id', '=', 'picking_list_items.location_id')
            ->orderBy('locations.code')
            ->orderBy('picking_list_items.id')
            ->select('picking_list_items.*')
            ->get();

        Activity::record(
            ActivityLog::REPORT_EXPORT,
            sprintf('Mengunduh daftar picking %s — %d baris.', $list->list_number, $baris->count()),
            $list,
            $list->warehouse_id,
            ['daftar_picking' => $list->list_number, 'baris' => $baris->count()],
        );

        return XlsxWriter::unduh(
            'daftar-picking-'.$list->list_number.'.xlsx',
            'Daftar Picking '.$list->list_number,
            ['No. SO (BC)', 'Dokumen', 'Pelanggan / Tujuan', 'SKU', 'Deskripsi', 'Batch',
                'Rak', 'Qty Diminta', 'Qty Diambil', 'Selisih', 'UOM', 'Status', 'Alasan Selisih'],
            $baris->map(fn (PickingListItem $item) => [
                $item->salesOrder?->bc_so_number ?? '—',
                $item->salesOrder?->order_number
                    ?? $list->transfer?->transfer_number
                    ?? $list->requisition?->mrf_number
                    ?? '—',
                $item->salesOrder?->customer?->name
                    ?? $list->requisition?->department_name
                    ?? '—',
                $item->product?->sku ?? '—',
                $item->product?->name ?? '—',
                $item->batch_no ?? '—',
                $item->location?->code ?? '—',
                (int) $item->qty_to_pick,
                (int) $item->qty_picked,
                (int) $item->qty_picked - (int) $item->qty_to_pick,
                $item->product?->uom ?? '—',
                PickingListItem::STATUS_LABELS[$item->status] ?? $item->status,
                $item->discrepancy_reason ?? '',
            ])->all(),
            angka: [7, 8, 9],
            keterangan: array_filter([
                'Daftar' => $list->list_number,
                'Gudang' => (string) $list->warehouse?->code,
                'Dikerjakan' => $list->claimedBy?->full_name ?? '—',
                'Selesai' => $list->completed_at?->format('Y-m-d H:i') ?? '—',
                'Baris' => (string) $baris->count(),
                'Diunduh' => now()->format('Y-m-d H:i:s'),
            ]),
        );
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

    /**
     * Melepas tugas yang sudah diambil — daftar kembali bebas diambil orang lain.
     *
     * DUA PINTU MASUK, SATU JALAN KELUAR. Operator melepas tugasnya sendiri;
     * Logistik/Manager boleh melepas milik siapa pun, dan itu satu-satunya
     * jalan saat operatornya sudah pulang dan daftarnya tertinggal terkunci.
     * Keduanya lewat PickingRun::release() supaya tidak ada dua versi aturan.
     *
     * ALASAN WAJIB. Yang melepas biasanya tahu sebabnya — pengiriman digeser
     * ke besok, atau tugasnya dioper ke orang lain — dan itulah yang Logistik
     * butuhkan untuk memutuskan daftar ini disusun ulang atau tidak.
     */
    public function release(Request $request, PickingList $list): RedirectResponse
    {
        WarehouseScope::assert($list->warehouse_id, $request->user());

        $data = $request->validate([
            'release_reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'release_reason.required' => 'Alasan melepas tugas wajib diisi — Logistik perlu tahu kenapa daftar ini kembali.',
        ], ['release_reason' => 'alasan']);

        $user = $request->user();
        // Pengawas = yang boleh menyusun daftar picking. Ia pula yang akan
        // mengatur ulang daftarnya sesudah dilepas.
        $pengawas = Gate::allows(Permission::OUTBOUND_PICKING_LIST);

        try {
            $dikosongkan = $this->picking->release($list, $user, $pengawas && $list->claimed_by !== $user?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::PICKING_RELEASE,
            sprintf(
                'Melepas tugas picking %s — daftar kembali bebas diambil. Alasan: %s',
                $list->list_number,
                $data['release_reason'],
            ),
            $list,
            $list->warehouse_id,
            [
                'daftar' => $list->list_number,
                'baris_dikosongkan' => $dikosongkan,
                'alasan' => $data['release_reason'],
            ],
        );

        $pesan = sprintf(
            'Tugas %s dilepas. Daftarnya kembali ke antrean dan bisa diambil operator lain.',
            $list->list_number,
        );

        // Baris yang tandanya ikut hilang WAJIB disebut. Operator yang sudah
        // menandai lima rak berhak tahu bahwa lima tanda itu kini kosong lagi.
        if ($dikosongkan > 0) {
            $pesan .= sprintf(' %d baris yang sudah ditandai dikembalikan ke keadaan belum diambil.', $dikosongkan);
        }

        return redirect()
            ->route($pengawas ? 'wms.picking.batching' : 'wms.picking.queue')
            ->with('warning', $pesan);
    }
}
