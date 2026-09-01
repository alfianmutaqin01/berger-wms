<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\ShelfLife;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Modul Inventory / Stok — PRD §6.4.
 *
 * Stok TIDAK disimpan sebagai satu angka per produk. Tiap kombinasi
 * produk × lokasi × batch adalah barisnya sendiri, karena FIFO (§7.2) dan
 * aturan kedaluwarsa (§7.2.1) menuntut batch tetap terpisah.
 */
class InventoryController extends Controller
{
    /**
     * F-INV-01: Tampilan Stok.
     *
     * DATA CONTRACT (view: wms.inventory.index)
     * -----------------------------------------
     * $stocks     : LengthAwarePaginator<InventoryStock> — eager-load product,
     *               location, warehouse
     * $warehouses : Collection<Warehouse>
     * $categories : Collection<ProductCategory>
     * $statuses   : array<string, string>
     * $stats      : array{good:int, dialokasikan:int, ddp:int, kritis:int}
     * $filters    : array{search, warehouse_id, category_id, location_id,
     *                     batch, status, production_date, expiring:?string}
     *
     * Good Stock dan Stok DDP DIPISAH lewat filter status, bukan dicampur di
     * satu daftar: DDP tidak pernah boleh terbaca sebagai barang yang siap
     * dijual (PRD §6.4 F-INV-01).
     */
    public function index(Request $request): View
    {
        $filters = [
            'search' => $request->query('search'),
            'warehouse_id' => $request->query('warehouse_id'),
            'category_id' => $request->query('category_id'),
            'location_id' => $request->query('location_id'),
            'batch' => $request->query('batch'),
            'status' => $request->query('status'),
            'production_date' => $request->query('production_date'),
            // "hampir kedaluwarsa" = dalam ambang peringatan dini 90 hari.
            'expiring' => $request->query('expiring'),
        ];

        $base = InventoryStock::query()
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['category_id'], fn ($q, $id) => $q->whereHas('product', fn ($p) => $p->where('category_id', $id)));

        $stocks = (clone $base)
            ->with(['product:id,sku,name,uom,category_id', 'location:id,code,zone', 'warehouse:id,code,name'])
            ->search($filters['search'])
            ->when($filters['location_id'], fn ($q, $id) => $q->where('location_id', $id))
            ->when($filters['batch'], fn ($q, $b) => $q->where('batch_no', 'ILIKE', '%'.$b.'%'))
            ->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
            ->when($filters['production_date'], fn ($q, $d) => $q->whereDate('production_date', $d))
            ->when($filters['expiring'], fn ($q) => $q
                ->where('status', InventoryStock::STATUS_ACTIVE)
                ->whereDate('expiry_date', '<=', now()->addDays(ShelfLife::WARNING_DAYS)->toDateString()))
            // Paling mendesak di atas: yang tanggal kedaluwarsanya terdekat.
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return view('wms.inventory.index', [
            'stocks' => $stocks,
            'warehouses' => Warehouse::orderBy('code')->get(),
            'categories' => ProductCategory::orderBy('name')->get(),
            'statuses' => InventoryStock::STATUS_LABELS,
            'stats' => [
                'good' => (int) (clone $base)->where('status', InventoryStock::STATUS_ACTIVE)->sum('qty_available'),
                'dialokasikan' => (int) (clone $base)->where('status', InventoryStock::STATUS_ACTIVE)->sum('qty_allocated'),
                'ddp' => (int) (clone $base)->quarantined()->sum('qty_available'),
                'kritis' => (clone $base)
                    ->where('status', InventoryStock::STATUS_ACTIVE)
                    ->whereDate('expiry_date', '<=', now()->addDays(ShelfLife::WARNING_DAYS)->toDateString())
                    ->count(),
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * F-INV-02: Stok Adjustment — Manager & Super Admin saja.
     *
     * Dua aturan yang membentuk method ini:
     *
     * 1. ALASAN WAJIB. Keputusan pemilik produk (docs/2 §3.4): tiap koreksi
     *    dicatat sebagai ADJUSTMENT dengan notes wajib + user pencatat.
     *    Angka stok yang berubah tanpa alasan tidak bisa diaudit.
     * 2. TIDAK BOLEH DI BAWAH qty_allocated. Stok yang sudah dikunci untuk
     *    order pelanggan tidak boleh hilang lewat koreksi — kalau boleh,
     *    order yang sudah disetujui mendadak tidak punya barang.
     */
    public function adjust(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'stock_id' => ['required', 'integer', 'exists:inventory_stocks,id'],
            'qty_new' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'ddp_reason' => ['nullable', 'in:'.implode(',', array_keys(InventoryStock::DDP_REASON_LABELS))],
        ], [], [
            'qty_new' => 'qty baru',
            'reason' => 'alasan koreksi',
        ]);

        $stock = InventoryStock::with('product:id,sku')->findOrFail($validated['stock_id']);
        $qtyBaru = (int) $validated['qty_new'];

        if ($qtyBaru < $stock->qty_allocated) {
            return back()->with('error', sprintf(
                'Qty tidak boleh di bawah %d yang sudah dialokasikan untuk pesanan.',
                $stock->qty_allocated
            ));
        }

        $qtyLama = $stock->qty_available;

        if ($qtyBaru === $qtyLama && blank($validated['ddp_reason'] ?? null)) {
            return back()->with('error', 'Tidak ada perubahan untuk disimpan.');
        }

        DB::transaction(function () use ($stock, $qtyBaru, $qtyLama, $validated, $request) {
            $stock->qty_available = $qtyBaru;

            // Menandai DDP adalah perubahan STATUS, bukan perubahan qty —
            // barangnya masih ada di rak, hanya tidak boleh dijual.
            if ($ddp = $validated['ddp_reason'] ?? null) {
                $stock->status = InventoryStock::STATUS_DDP;
                $stock->ddp_reason = $ddp;
            }

            $stock->save();

            StockMovement::create([
                'product_id' => $stock->product_id,
                'location_id' => $stock->location_id,
                'warehouse_id' => $stock->warehouse_id,
                'movement_type' => StockMovement::TYPE_ADJUSTMENT,
                'qty_change' => $qtyBaru - $qtyLama,
                'qty_before' => $qtyLama,
                'qty_after' => $qtyBaru,
                'reference_type' => StockMovement::REF_ADJUSTMENT,
                'reference_id' => $stock->id,
                'batch_no' => $stock->batch_no,
                'notes' => $validated['reason'],
                'user_id' => $request->user()?->id,
            ]);
        });

        return back()->with('success', sprintf(
            'Koreksi tersimpan: %s batch %s dari %d menjadi %d.',
            $stock->product?->sku ?? '—',
            $stock->batch_no,
            $qtyLama,
            $qtyBaru
        ));
    }

    /**
     * F-INV-02 turunan: memindahkan stok antar lokasi rak.
     *
     * Direkam sebagai PASANGAN TRANSFER_OUT/TRANSFER_IN dalam satu transaksi
     * (docs/2 §3.4): transfer adalah mutasi murni tanpa siklus hidup dokumen,
     * jadi tidak butuh tabel header sendiri. Total qty_change kedua entri
     * harus nol — kalau tidak, stok tercipta atau lenyap dari ketiadaan.
     *
     * Batch, tanggal produksi, dan tanggal kedaluwarsa IKUT PINDAH apa adanya.
     * Membuat batch baru di lokasi tujuan akan merusak FIFO (barang lama
     * tampak baru) sekaligus perhitungan kedaluwarsa.
     */
    public function transfer(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'stock_id' => ['required', 'integer', 'exists:inventory_stocks,id'],
            'to_location_code' => ['required', 'string', 'max:20'],
            'qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [], [
            'to_location_code' => 'lokasi tujuan',
            'reason' => 'alasan pemindahan',
        ]);

        $stock = InventoryStock::with('product:id,sku')->findOrFail($validated['stock_id']);
        $qty = (int) $validated['qty'];

        if ($qty > $stock->qty_available) {
            return back()->with('error', sprintf(
                'Qty pindah (%d) melebihi stok tersedia (%d).',
                $qty,
                $stock->qty_available
            ));
        }

        $tujuan = Location::where('warehouse_id', $stock->warehouse_id)
            ->active()
            ->whereRaw('UPPER(code) = ?', [strtoupper(trim($validated['to_location_code']))])
            ->first();

        if (! $tujuan) {
            return back()->with('error', sprintf(
                'Lokasi tujuan "%s" tidak ada atau tidak aktif di gudang ini.',
                strtoupper(trim($validated['to_location_code']))
            ));
        }

        if ($tujuan->id === $stock->location_id) {
            return back()->with('error', 'Lokasi tujuan sama dengan lokasi asal.');
        }

        DB::transaction(function () use ($stock, $tujuan, $qty, $validated, $request) {
            $asalSebelum = $stock->qty_available;
            $stock->qty_available = $asalSebelum - $qty;
            $stock->save();

            // Batch yang sama di rak tujuan digabung, bukan dibuat baris baru
            // kembar yang harus dijumlahkan manual setiap kali dilihat.
            $tujuanStok = InventoryStock::firstOrNew([
                'product_id' => $stock->product_id,
                'location_id' => $tujuan->id,
                'batch_no' => $stock->batch_no,
                'production_date' => $stock->production_date->toDateString(),
            ]);

            $tujuanSebelum = $tujuanStok->exists ? $tujuanStok->qty_available : 0;

            if (! $tujuanStok->exists) {
                $tujuanStok->fill([
                    'warehouse_id' => $stock->warehouse_id,
                    'qty_allocated' => 0,
                    // Kedaluwarsa & status IKUT dari asalnya, tidak dihitung ulang.
                    'expiry_date' => $stock->expiry_date->toDateString(),
                    'status' => $stock->status,
                    'ddp_reason' => $stock->ddp_reason,
                    'inbound_detail_id' => $stock->inbound_detail_id,
                    'verified_by' => $stock->verified_by,
                    'verified_at' => $stock->verified_at,
                ]);
            }

            $tujuanStok->qty_available = $tujuanSebelum + $qty;
            $tujuanStok->save();

            $jejak = [
                'product_id' => $stock->product_id,
                'warehouse_id' => $stock->warehouse_id,
                'reference_type' => StockMovement::REF_STOCK_TRANSFER,
                'reference_id' => $stock->id,
                'batch_no' => $stock->batch_no,
                'notes' => $validated['reason'],
                'user_id' => $request->user()?->id,
            ];

            StockMovement::create($jejak + [
                'location_id' => $stock->location_id,
                'movement_type' => StockMovement::TYPE_TRANSFER_OUT,
                'qty_change' => -$qty,
                'qty_before' => $asalSebelum,
                'qty_after' => $stock->qty_available,
            ]);

            StockMovement::create($jejak + [
                'location_id' => $tujuan->id,
                'movement_type' => StockMovement::TYPE_TRANSFER_IN,
                'qty_change' => $qty,
                'qty_before' => $tujuanSebelum,
                'qty_after' => $tujuanStok->qty_available,
            ]);
        });

        return back()->with('success', sprintf(
            '%d %s batch %s dipindahkan ke %s.',
            $qty,
            $stock->product?->sku ?? '—',
            $stock->batch_no,
            $tujuan->code
        ));
    }
}
