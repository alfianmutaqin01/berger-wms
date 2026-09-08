<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\Warehouse;
use App\Support\Activity;
use App\Support\Inventory\StockTakeRun;
use App\Support\WarehouseScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Stok opname — mencocokkan angka sistem dengan barang yang benar-benar ada
 * di rak, sebulan atau tiga bulan sekali (permintaan pemilik produk).
 *
 * MENGAPA MENU SENDIRI, BUKAN MENUMPANG DENAH
 * -------------------------------------------
 * Denah menjawab "di mana barangnya" dan boleh dibuka kapan saja. Opname
 * adalah PROSES bertahap dengan awal, akhir, dan penanggung jawab: sesi
 * dibuka, rak dihitung satu per satu selama berhari-hari, lalu laporannya
 * disahkan dan stok berubah. Menaruh proses berpemilik seperti itu di dalam
 * layar master data akan menyembunyikannya justru dari orang yang harus
 * memantaunya.
 *
 * Yang DIPINJAM dari denah adalah susunannya: layar penghitungan
 * dikelompokkan deret -> rak persis seperti denah, sehingga orang yang
 * menghitung membaca layar dengan urutan yang sama seperti saat ia berjalan
 * menyusuri gudang.
 *
 * DATA CONTRACT
 * -------------
 * index()  : $sesi LengthAwarePaginator<StockTake>, $berjalan ?StockTake,
 *            $warehouses, $zones, $racks
 * show()   : $sesi StockTake, $deret Collection, $ringkasan array
 * report() : $sesi StockTake, $baris list<array>, $ringkasan array
 */
class StockTakeController extends Controller
{
    public function __construct(private readonly StockTakeRun $opname) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $gudangId = WarehouseScope::resolveFilter($request, $user, 'warehouse');
        $pilihan = WarehouseScope::options($user);
        $gudang = $gudangId ? $pilihan->firstWhere('id', $gudangId) : $pilihan->first();

        $sesi = WarehouseScope::apply(StockTake::query(), $user)
            ->with(['warehouse:id,code,name', 'openedBy:id,full_name', 'finalizedBy:id,full_name'])
            ->withCount([
                'items',
                'items as items_dihitung_count' => fn ($q) => $q->whereNotNull('qty_physical'),
            ])
            ->latest('opened_at')
            ->paginate(15)
            ->withQueryString();

        return view('wms.inventory.stocktake-index', [
            'sesi' => $sesi,
            'berjalan' => WarehouseScope::apply(StockTake::berjalan(), $user)
                ->with('warehouse:id,code,name')->first(),
            'warehouses' => $pilihan,
            'warehouse' => $gudang,
            'zones' => Location::ZONES,
            'racks' => Location::query()
                ->where('warehouse_id', $gudang?->id)
                ->distinct()->orderBy('rack')->pluck('rack'),
        ]);
    }

    /** Membuka sesi baru; angka sistem dibekukan di sini. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'scope_type' => ['required', 'in:'.implode(',', array_keys(StockTake::SCOPE_LABELS))],
            'scope_value' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'scope_type' => 'cakupan',
            'scope_value' => 'zona/deret',
        ]);

        WarehouseScope::assert((int) $data['warehouse_id'], $request->user());

        $nilai = $data['scope_type'] === StockTake::SCOPE_WAREHOUSE
            ? null
            : trim((string) ($data['scope_value'] ?? ''));

        if ($data['scope_type'] !== StockTake::SCOPE_WAREHOUSE && $nilai === '') {
            return back()->with('error', 'Cakupan zona atau deret wajib dipilih.');
        }

        try {
            $sesi = $this->opname->open(
                Warehouse::findOrFail($data['warehouse_id']),
                $data['scope_type'],
                $nilai,
                $data['note'] ?? null,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('wms.stocktake.show', $sesi)->with('success', sprintf(
            'Sesi opname %s dibuka. %d baris stok dibekukan angkanya — stok belum berubah sama sekali '.
            'sampai laporannya disahkan.',
            $sesi->reference,
            $sesi->items()->count(),
        ));
    }

    /** Layar penghitungan, disusun deret -> rak seperti denah. */
    public function show(Request $request, StockTake $stocktake): View
    {
        WarehouseScope::assert($stocktake->warehouse_id, $request->user());

        $items = $stocktake->items()
            ->with(['location:id,code,rack,level,cell', 'product:id,sku,name,uom', 'countedBy:id,full_name'])
            ->get()
            ->sortBy([
                fn (StockTakeItem $i) => $i->location?->rack ?? '',
                fn (StockTakeItem $i) => $i->location?->level ?? 0,
                fn (StockTakeItem $i) => $i->location?->cell ?? 0,
            ]);

        return view('wms.inventory.stocktake-show', [
            'sesi' => $stocktake->load(['warehouse', 'openedBy:id,full_name']),
            'deret' => $items->groupBy(fn (StockTakeItem $i) => $i->location?->rack ?? '—')
                ->map(fn ($baris) => $baris->groupBy(fn (StockTakeItem $i) => $i->location?->code ?? '—')),
            'ringkasan' => $this->ringkasan($stocktake),
        ]);
    }

    /**
     * Menyimpan hasil hitungan satu baris.
     *
     * MENJAWAB DUA PEMANGGIL, dan itu disengaja. Layar penghitungan
     * mengirimnya lewat fetch() dan menerima JSON, sehingga halaman TIDAK
     * dimuat ulang: sesi opname bisa berisi ribuan baris, dan memuat ulang
     * setiap kali satu baris disimpan akan melempar orang yang sudah
     * menghitung sampai baris terakhir kembali ke puncak halaman.
     *
     * Formulirnya tetap formulir sungguhan yang bisa disubmit biasa. Kalau
     * JavaScript-nya gagal dimuat, penghitungan tetap berjalan — hanya
     * kembali ke perilaku muat ulang. Yang tidak boleh terjadi adalah
     * operator berdiri di depan rak dengan tombol yang tidak melakukan
     * apa-apa.
     */
    public function count(Request $request, StockTakeItem $item): RedirectResponse|JsonResponse
    {
        WarehouseScope::assert($item->stockTake->warehouse_id, $request->user());

        $data = $request->validate([
            'qty_physical' => ['required', 'integer', 'min:0', 'max:1000000'],
            'count_note' => ['nullable', 'string', 'max:500'],
        ], [
            'qty_physical.required' => 'Hasil hitungan wajib diisi.',
        ], [
            'qty_physical' => 'hasil hitungan',
            'count_note' => 'catatan',
        ]);

        try {
            $this->opname->count(
                $item,
                (int) $data['qty_physical'],
                $data['count_note'] ?? null,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return $request->wantsJson()
                ? response()->json(['pesan' => $e->getMessage()], 422)
                : back()->with('error', $e->getMessage());
        }

        $item->refresh()->load('countedBy:id,full_name');

        if ($request->wantsJson()) {
            return response()->json([
                'qty_physical' => $item->qty_physical,
                'selisih' => $item->selisih,
                'oleh' => $item->countedBy?->full_name,
                'waktu' => $item->counted_at?->format('d M H:i'),
                // Kartu ringkas ikut dikirim supaya angkanya tidak diam-diam
                // basi. Menghitungnya ulang di sisi layar berarti dua tempat
                // yang harus sepakat, dan cepat atau lambat keduanya berbeda.
                'ringkasan' => $this->ringkasan($item->stockTake),
            ]);
        }

        return back()->with('success', sprintf(
            'Hitungan rak %s tersimpan. Stok belum berubah — koreksinya baru berlaku setelah laporan disahkan.',
            $item->location?->code ?? '—',
        ));
    }

    /**
     * Mengesahkan laporan. INILAH yang mengubah stok.
     *
     * Sesudahnya pengguna dibawa ke halaman laporan, yang langsung membuka
     * dialog cetak — sesuai permintaan pemilik produk, stok terbaru berlaku
     * bersamaan dengan terbitnya laporan itu.
     */
    public function finalize(Request $request, StockTake $stocktake): RedirectResponse
    {
        WarehouseScope::assert($stocktake->warehouse_id, $request->user());

        try {
            $hasil = $this->opname->finalize($stocktake, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::STOCKTAKE_FINALIZE,
            sprintf(
                'Mengesahkan laporan stok opname %s: %d baris disesuaikan (+%d / -%d unit), %d baris belum dihitung.',
                $stocktake->reference,
                $hasil['disesuaikan'],
                $hasil['naik'],
                $hasil['turun'],
                $hasil['belum'],
            ),
            $stocktake,
            $stocktake->warehouse_id,
            [
                'referensi' => $stocktake->reference,
                'disesuaikan' => $hasil['disesuaikan'],
                'naik' => $hasil['naik'],
                'turun' => $hasil['turun'],
                'belum_dihitung' => $hasil['belum'],
            ],
        );

        $pesan = sprintf(
            'Laporan opname %s disahkan. %d baris disesuaikan (+%d / -%d unit).',
            $stocktake->reference,
            $hasil['disesuaikan'],
            $hasil['naik'],
            $hasil['turun'],
        );

        if ($hasil['belum'] > 0) {
            // Dikatakan apa adanya. Laporan yang menyembunyikan bagian yang
            // belum dihitung akan dibaca sebagai "seluruh gudang sudah cocok".
            $pesan .= sprintf(
                ' %d baris TIDAK sempat dihitung dan sengaja tidak disentuh — cakupan opname ini belum penuh.',
                $hasil['belum'],
            );
        }

        return redirect()->route('wms.stocktake.report', ['stocktake' => $stocktake, 'cetak' => 1])
            ->with($hasil['belum'] > 0 ? 'warning' : 'success', $pesan);
    }

    public function cancel(Request $request, StockTake $stocktake): RedirectResponse
    {
        WarehouseScope::assert($stocktake->warehouse_id, $request->user());

        try {
            $this->opname->cancel($stocktake, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('wms.stocktake.index')->with('success', sprintf(
            'Sesi opname %s dibatalkan. Tidak ada angka stok yang berubah.',
            $stocktake->reference,
        ));
    }

    /**
     * Laporan stok global hasil opname — per SKU, bukan per rak.
     *
     * Yang ditanyakan pembacanya adalah "SKU ini sekarang berapa", dan
     * jawabannya tidak boleh berupa daftar rak yang harus dijumlahkan sendiri.
     * Rincian per raknya tetap ada di layar penghitungan.
     */
    public function report(Request $request, StockTake $stocktake): View
    {
        WarehouseScope::assert($stocktake->warehouse_id, $request->user());

        $baris = $stocktake->items()
            ->with('product:id,sku,name,uom')
            ->get()
            ->groupBy('product_id')
            ->map(function ($isi) {
                $pertama = $isi->first();

                // Baris yang belum dihitung menyumbang qty_after = qty_system:
                // stoknya memang tidak disentuh, jadi selisihnya nol dan
                // totalnya tetap jujur.
                $sesudah = $isi->sum(fn (StockTakeItem $i) => $i->qty_after ?? $i->qty_system);
                $sebelum = $isi->sum('qty_system');

                return [
                    'sku' => $pertama->product?->sku ?? '—',
                    'nama' => $pertama->product?->name ?? '—',
                    'uom' => $pertama->product?->uom,
                    'sebelum' => (int) $sebelum,
                    'sesudah' => (int) $sesudah,
                    'selisih' => (int) ($sesudah - $sebelum),
                    'baris' => $isi->count(),
                    'belum' => $isi->filter(fn (StockTakeItem $i) => ! $i->sudahDihitung())->count(),
                ];
            })
            ->sortBy('sku')
            ->values()
            ->all();

        return view('wms.inventory.stocktake-report', [
            'sesi' => $stocktake->load([
                'warehouse', 'openedBy:id,full_name', 'finalizedBy:id,full_name',
            ]),
            'baris' => $baris,
            'ringkasan' => $this->ringkasan($stocktake),
            'cetakOtomatis' => $request->boolean('cetak'),
        ]);
    }

    /**
     * @return array{baris:int, dihitung:int, belum:int, selisih:int, cocok:int}
     */
    private function ringkasan(StockTake $sesi): array
    {
        $items = $sesi->items()->get(['qty_system', 'qty_physical']);

        $dihitung = $items->filter(fn (StockTakeItem $i) => $i->sudahDihitung());

        return [
            'baris' => $items->count(),
            'dihitung' => $dihitung->count(),
            'belum' => $items->count() - $dihitung->count(),
            'selisih' => $dihitung->filter(fn (StockTakeItem $i) => $i->selisih !== 0)->count(),
            'cocok' => $dihitung->filter(fn (StockTakeItem $i) => $i->selisih === 0)->count(),
        ];
    }
}
