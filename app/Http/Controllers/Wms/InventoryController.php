<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\StoreInventoryStockRequest;
use App\Models\ActivityLog;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Support\Activity;
use App\Support\Inventory\StockQuarantine;
use App\Support\Outbound\PendingAllocationFiller;
use App\Support\ShelfLife;
use App\Support\WarehouseScope;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Modul Inventory / Stok — PRD §6.4.
 *
 * Stok TIDAK disimpan sebagai satu angka per produk. Tiap kombinasi
 * produk × lokasi × batch adalah barisnya sendiri, karena FIFO (§7.2) dan
 * aturan kedaluwarsa (§7.2.1) menuntut batch tetap terpisah.
 */
class InventoryController extends Controller
{
    public function __construct(
        private readonly PendingAllocationFiller $pengisi,
        private readonly StockQuarantine $karantina,
    ) {}

    /**
     * F-INV-01: Tampilan Stok — accordion per SKU (docs/4 §4.3.9).
     *
     * SATU BARIS = SATU SKU, bukan satu batch. Satu SKU dengan lima palet
     * dahulu memakan lima baris sehingga satu layar hanya memuat lima produk;
     * sekarang barisnya tertutup dan hanya memuat angka ringkas, lalu batch,
     * lokasi, dan sisa umur simpannya terbuka saat baris itu diklik.
     *
     * Isi accordion terbagi DUA BLOK berwarna sesuai §4.3.9: Good Stock
     * (layak jual) dan Stok DDP (rusak/karantina/kedaluwarsa). Blok DDP
     * selalu dirender meski kosong — ketiadaan stok rusak harus terbaca
     * sebagai informasi, bukan sebagai data yang belum dimuat.
     *
     * DATA CONTRACT (view: wms.inventory.index)
     * -----------------------------------------
     * $halaman    : LengthAwarePaginator — satu entri per SKU, untuk links()
     * $barisSku   : Collection<array{product:Product, good:Collection,
     *                                ddp:Collection, karantina:Collection,
     *                                total_good:int, total_ddp:int,
     *                                total_karantina:int, kritis:bool}>
     * $warehouses : Collection<Warehouse>
     * $categories : Collection<ProductCategory>
     * $statuses   : array<string, string>
     * $stats      : array{good:int, dialokasikan:int, ddp:int, karantina:int, kritis:int}
     * $filters    : array{search, warehouse_id, category_id, location_id,
     *                     batch, status, production_date, expiring:?string}
     *
     * Batch TIDAK PERNAH dilebur menjadi satu angka di dalam blok: FIFO
     * (§7.2) dan aturan kedaluwarsa (§7.2.1) menuntut tiap batch tetap
     * punya tanggal produksi dan kedaluwarsanya sendiri.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $filters = [
            'search' => $request->query('search'),
            // Dijepit ke gudang user; bagi yang terikat, isian URL diabaikan.
            'warehouse_id' => WarehouseScope::resolveFilter($request, $user),
            'category_id' => $request->query('category_id'),
            'location_id' => $request->query('location_id'),
            'batch' => $request->query('batch'),
            'status' => $request->query('status'),
            'production_date' => $request->query('production_date'),
            // "hampir kedaluwarsa" = dalam ambang peringatan dini 90 hari.
            'expiring' => $request->query('expiring'),
        ];

        // apply() DAN filter gudang keduanya dipasang. Yang pertama adalah
        // batas kewenangan (tidak bisa dikosongkan), yang kedua pilihan
        // tampilan milik Super Admin yang memang lintas gudang.
        $base = WarehouseScope::apply(InventoryStock::query(), $user)
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['category_id'], fn ($q, $id) => $q->whereHas('product', fn ($p) => $p->where('category_id', $id)));

        // Semua penyaring baris dikumpulkan sekali supaya daftar SKU dan
        // daftar batch di dalamnya TIDAK PERNAH memakai kriteria berbeda —
        // kalau berbeda, sebuah SKU bisa muncul dengan accordion kosong.
        $terpilih = fn () => (clone $base)
            ->search($filters['search'])
            ->when($filters['location_id'], fn ($q, $id) => $q->where('location_id', $id))
            ->when($filters['batch'], fn ($q, $b) => $q->where('batch_no', 'ILIKE', '%'.$b.'%'))
            ->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
            ->when($filters['production_date'], fn ($q, $d) => $q->whereDate('production_date', $d))
            ->when($filters['expiring'], fn ($q) => $q
                ->where('status', InventoryStock::STATUS_ACTIVE)
                ->whereDate('expiry_date', '<=', now()->addDays(ShelfLife::WARNING_DAYS)->toDateString()));

        // Paginasi di tingkat SKU. Diurutkan dari SKU yang salah satu
        // batch-nya paling dekat kedaluwarsa: itulah yang harus dijual duluan.
        $halaman = $terpilih()
            ->select('product_id')
            ->selectRaw('MIN(expiry_date) AS expiry_terdekat')
            ->groupBy('product_id')
            ->orderBy('expiry_terdekat')
            ->orderBy('product_id')
            ->paginate(15)
            ->withQueryString();

        $idProduk = collect($halaman->items())->pluck('product_id')->all();

        // Satu query untuk SELURUH batch di halaman ini, bukan satu query per
        // SKU — accordion 15 baris tidak boleh berarti 15 kali jalan ke DB.
        $batch = $idProduk === [] ? collect() : $terpilih()
            ->with(['product:id,sku,name,uom,category_id', 'location:id,code,zone', 'warehouse:id,code,name'])
            ->whereIn('product_id', $idProduk)
            ->orderBy('production_date')   // urutan FIFO: yang tertua di atas
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        $barisSku = collect($idProduk)
            ->map(function (int $id) use ($batch) {
                $isi = $batch->get($id, collect());
                $good = $isi->where('status', InventoryStock::STATUS_ACTIVE)->values();
                $ddp = $isi->whereIn('status', [InventoryStock::STATUS_DDP, InventoryStock::STATUS_EXPIRED])->values();
                // BLOK KETIGA. Sebelum ditambahkan, batch berstatus 'quarantine'
                // tidak cocok dengan $good (status != active) MAUPUN $ddp
                // (bukan ddp/expired) — akan lenyap dari accordion sama sekali
                // begitu status ini ada, padahal barangnya masih di rak.
                $karantina = $isi->where('status', InventoryStock::STATUS_QUARANTINE)->values();

                return [
                    'product' => $isi->first()?->product,
                    'good' => $good,
                    'ddp' => $ddp,
                    'karantina' => $karantina,
                    'total_good' => (int) $good->sum('qty_available'),
                    'total_ddp' => (int) $ddp->sum('qty_available'),
                    'total_karantina' => (int) $karantina->sum('qty_available'),
                    // Menandai baris tertutup: ada batch yang harus segera dijual.
                    'kritis' => $good->contains(fn ($s) => in_array($s->shelf_life_urgency, ['critical', 'expired'], true)),
                ];
            })
            ->filter(fn (array $baris) => $baris['product'] !== null)
            ->values();

        return view('wms.inventory.index', [
            'halaman' => $halaman,
            'barisSku' => $barisSku,
            'warehouses' => WarehouseScope::options($user),
            'categories' => ProductCategory::orderBy('name')->get(),
            'statuses' => InventoryStock::STATUS_LABELS,
            'stats' => [
                'good' => (int) (clone $base)->where('status', InventoryStock::STATUS_ACTIVE)->sum('qty_available'),
                'dialokasikan' => (int) (clone $base)->where('status', InventoryStock::STATUS_ACTIVE)->sum('qty_allocated'),
                'ddp' => (int) (clone $base)->ddpOrExpired()->sum('qty_available'),
                'karantina' => (int) (clone $base)->inQuarantine()->sum('qty_available'),
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

        // `stock_id` datang dari formulir dan bisa diganti nomor apa pun.
        // exists: hanya memastikan barisnya ADA, bukan bahwa barisnya boleh
        // disentuh user ini.
        WarehouseScope::assert($stock->warehouse_id, $request->user());

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

        $susulan = DB::transaction(function () use ($stock, $qtyBaru, $qtyLama, $validated, $request) {
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

            // Stok BERTAMBAH berarti pesanan yang tertahan mungkin sudah bisa
            // dipenuhi. Hanya saat bertambah — koreksi yang mengurangi tidak
            // punya apa pun untuk dibagikan. Stok yang baru saja ditandai DDP
            // juga dilewati: barangnya ada, tapi tidak boleh dijual.
            $bertambah = $qtyBaru > $qtyLama && $stock->status === InventoryStock::STATUS_ACTIVE;

            return $bertambah
                ? $this->pengisi->fill($stock->product_id, $stock->warehouse_id, $request->user()?->id)
                : ['terisi' => 0, 'pesanan' => [], 'booking' => []];
        });

        Activity::record(
            ActivityLog::STOCK_ADJUST,
            sprintf(
                'Mengoreksi %s batch %s dari %d menjadi %d%s.',
                $stock->product?->sku ?? '—',
                $stock->batch_no ?? '—',
                $qtyLama,
                $qtyBaru,
                ($validated['ddp_reason'] ?? null) ? ' dan menandainya DDP' : '',
            ),
            $stock,
            $stock->warehouse_id,
            [
                'sku' => $stock->product?->sku,
                'batch' => $stock->batch_no,
                'qty_sebelum' => $qtyLama,
                'qty_sesudah' => $qtyBaru,
                'selisih' => $qtyBaru - $qtyLama,
                'ddp' => $validated['ddp_reason'] ?? null,
                'alasan' => $validated['reason'],
            ],
        );

        $pesan = sprintf(
            'Koreksi tersimpan: %s batch %s dari %d menjadi %d.',
            $stock->product?->sku ?? '—',
            $stock->batch_no,
            $qtyLama,
            $qtyBaru
        );

        if ($ringkas = $this->pengisi->ringkasan($susulan)) {
            return back()->with('warning', $pesan.' '.$ringkas);
        }

        return back()->with('success', $pesan);
    }

    /**
     * F-INV-02 diperluas: menambahkan baris stok yang BELUM PERNAH tercatat.
     *
     * adjust() hanya bisa mengoreksi baris yang sudah ada. Sistem ini dipasang
     * di gudang yang sudah berjalan, jadi banyak barang fisiknya di rak tetapi
     * belum punya baris untuk dikoreksi. Tanpa pintu ini satu-satunya jalan
     * adalah memalsukan dokumen inbound.
     *
     * Batch yang sama, di rak yang sama, dari tanggal produksi yang sama
     * DIGABUNG ke baris yang ada — aturan yang sama dengan StockActivator,
     * supaya tidak muncul dua baris kembar yang harus dijumlahkan manual
     * setiap kali dilihat.
     */
    public function store(StoreInventoryStockRequest $request): RedirectResponse
    {
        $produk = $request->produk;
        $lokasi = $request->lokasi;

        // Gudangnya ditentukan RAK yang dipilih, bukan isian tersendiri —
        // karena itu penjagaannya dipasang pada rak itu.
        WarehouseScope::assert($lokasi->warehouse_id, $request->user());

        $qty = (int) $request->validated('qty');
        $tanggal = Carbon::parse($request->validated('production_date'));

        $hasil = DB::transaction(function () use ($request, $produk, $lokasi, $qty, $tanggal) {
            $stock = InventoryStock::query()
                ->where('product_id', $produk->id)
                ->where('location_id', $lokasi->id)
                ->where('batch_no', $request->validated('batch_no'))
                ->whereDate('production_date', $tanggal->toDateString())
                ->lockForUpdate()
                ->first();

            $sebelum = $stock?->qty_available ?? 0;

            if ($stock === null) {
                $stock = new InventoryStock([
                    'product_id' => $produk->id,
                    'location_id' => $lokasi->id,
                    'warehouse_id' => $lokasi->warehouse_id,
                    'batch_no' => $request->validated('batch_no'),
                    'qty_allocated' => 0,
                    'production_date' => $tanggal->toDateString(),
                    // Aturan kedaluwarsa yang SAMA dengan jalur inbound
                    // (§7.2.1). Kalau dihitung berbeda di sini, dua batch
                    // identik bisa punya tanggal kedaluwarsa berbeda
                    // tergantung lewat pintu mana ia masuk.
                    'expiry_date' => InventoryStock::calculateExpiry(
                        $tanggal,
                        $produk->shelf_life_months
                    )->toDateString(),
                    'status' => InventoryStock::STATUS_ACTIVE,
                ]);
            }

            $stock->qty_available = $sebelum + $qty;
            $stock->verified_by = $request->user()?->id;
            $stock->verified_at = now();
            $stock->save();

            StockMovement::create([
                'product_id' => $stock->product_id,
                'location_id' => $stock->location_id,
                'warehouse_id' => $stock->warehouse_id,
                'movement_type' => StockMovement::TYPE_ADJUSTMENT,
                'qty_change' => $qty,
                'qty_before' => $sebelum,
                'qty_after' => $stock->qty_available,
                'reference_type' => StockMovement::REF_ADJUSTMENT,
                'reference_id' => $stock->id,
                'batch_no' => $stock->batch_no,
                'notes' => $request->validated('reason'),
                'user_id' => $request->user()?->id,
            ]);

            return [
                'baru' => $sebelum === 0,
                'total' => $stock->qty_available,
                'susulan' => $this->pengisi->fill($stock->product_id, $stock->warehouse_id, $request->user()?->id),
            ];
        });

        Activity::record(
            ActivityLog::STOCK_ADD,
            sprintf(
                'Menambahkan %d unit %s batch %s ke rak %s (gudang %s). Total batch di rak itu jadi %d.',
                $qty,
                $produk->sku,
                $request->validated('batch_no'),
                $lokasi->code,
                $lokasi->warehouse?->code ?? '—',
                $hasil['total'],
            ),
            $lokasi,
            $lokasi->warehouse_id,
            [
                'sku' => $produk->sku,
                'batch' => $request->validated('batch_no'),
                'qty' => $qty,
                'rak' => $lokasi->code,
                'baris_baru' => $hasil['baru'],
                'alasan' => $request->validated('reason'),
            ],
        );

        $pesan = sprintf(
            '%s batch %s di %s: %s %d unit (total sekarang %d).',
            $produk->sku,
            $request->validated('batch_no'),
            $lokasi->code,
            $hasil['baru'] ? 'ditambahkan' : 'ditambah',
            $qty,
            $hasil['total']
        );

        // Alokasi otomatis WAJIB dilaporkan. Tanpa kalimat ini Manager
        // mengira menambah 50, yang bebas ternyata 35, dan tidak ada apa pun
        // di layar yang menjelaskan ke mana 15 sisanya pergi.
        if ($ringkas = $this->pengisi->ringkasan($hasil['susulan'])) {
            return back()->with('warning', $pesan.' '.$ringkas);
        }

        return back()->with('success', $pesan);
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

        WarehouseScope::assert($stock->warehouse_id, $request->user());

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

        // Dibaca SEBELUM transaksi: location_id baris asal tidak berubah, tapi
        // relasinya belum tentu masih termuat setelahnya.
        $asal = $stock->location?->code ?? '—';

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

                    // SELURUH PENANDA BATCH IKUT PINDAH. Ketiganya melekat pada
                    // batch — pada apa yang terjadi saat produksi/pengujian —
                    // bukan pada rak tempat barangnya kebetulan duduk. Baris
                    // baru tanpa penanda akan membuat separuh batch dikarantina
                    // dan separuhnya bebas dijual, padahal barangnya sama.
                    //
                    // Metadata karantina WAJIB ikut, bukan cuma statusnya:
                    // CHECK inventory_stocks_karantina_lengkap menolak baris
                    // berstatus 'quarantine' yang tanggal & pemasangnya kosong,
                    // jadi tanpa ini memindahkan batch terkarantina gagal
                    // dengan galat constraint mentah.
                    'quarantine_days' => $stock->quarantine_days,
                    'quarantine_until' => $stock->quarantine_until?->toDateString(),
                    'quarantined_at' => $stock->quarantined_at,
                    'quarantined_by' => $stock->quarantined_by,
                    'quarantine_note' => $stock->quarantine_note,
                    'quarantine_released_at' => $stock->quarantine_released_at,

                    'has_quality_issue' => $stock->has_quality_issue,

                    'prioritize_out' => $stock->prioritize_out,
                    'prioritize_reason' => $stock->prioritize_reason,
                    'prioritized_at' => $stock->prioritized_at,
                    'prioritized_by' => $stock->prioritized_by,
                    'prioritize_released_at' => $stock->prioritize_released_at,
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

        Activity::record(
            ActivityLog::STOCK_TRANSFER,
            sprintf(
                'Memindahkan %d %s batch %s dari rak %s ke rak %s.',
                $qty,
                $stock->product?->sku ?? '—',
                $stock->batch_no ?? '—',
                $asal,
                $tujuan->code,
            ),
            $stock,
            $stock->warehouse_id,
            [
                'sku' => $stock->product?->sku,
                'batch' => $stock->batch_no,
                'qty' => $qty,
                'dari_rak' => $asal,
                'ke_rak' => $tujuan->code,
                'alasan' => $validated['reason'],
            ],
        );

        return back()->with('success', sprintf(
            '%d %s batch %s dipindahkan ke %s.',
            $qty,
            $stock->product?->sku ?? '—',
            $stock->batch_no,
            $tujuan->code
        ));
    }

    /**
     * Menahan satu batch sementara — permintaan pemilik produk (bukan PRD).
     *
     * SELURUH BARIS product+gudang+batch yang sama ikut ditahan, bukan cuma
     * baris yang dipilih di layar: karantina melekat pada hasil pemeriksaan
     * QC atas batch itu, bukan pada rak tempat sebagian isinya kebetulan
     * sekarang duduk. Lihat App\Support\Inventory\StockQuarantine::place().
     */
    public function quarantine(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'stock_id' => ['required', 'integer', 'exists:inventory_stocks,id'],
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], [
            'days' => 'lama karantina',
            'note' => 'catatan',
        ]);

        $stock = InventoryStock::with('product:id,sku')->findOrFail($validated['stock_id']);

        WarehouseScope::assert($stock->warehouse_id, $request->user());

        try {
            $jumlah = $this->karantina->place(
                $stock,
                (int) $validated['days'],
                $validated['note'] ?? null,
                $request->user()->id,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::QUARANTINE_PLACE,
            sprintf(
                'Mengarantina batch %s (%s) selama %d hari.',
                $stock->batch_no ?? '—',
                $stock->product?->sku ?? '—',
                $validated['days'],
            ),
            $stock,
            $stock->warehouse_id,
            [
                'sku' => $stock->product?->sku,
                'batch' => $stock->batch_no,
                'hari' => (int) $validated['days'],
                'sampai' => $stock->fresh()->quarantine_until?->toDateString(),
                'baris' => $jumlah,
                'catatan' => $validated['note'] ?? null,
            ],
        );

        return back()->with('warning', sprintf(
            '%d baris stok batch %s (%s) dikarantina %d hari. Otomatis kembali jadi Good Stock setelah %s '.
            '— tidak perlu tindakan manual.',
            $jumlah,
            $stock->batch_no,
            $stock->product?->sku ?? '—',
            $validated['days'],
            $stock->fresh()->quarantine_until->translatedFormat('d M Y'),
        ));
    }

    /** Melepas karantina lebih awal, mis. QC selesai sebelum jangka waktunya. */
    public function releaseQuarantine(Request $request, InventoryStock $stock): RedirectResponse
    {
        WarehouseScope::assert($stock->warehouse_id, $request->user());

        try {
            $jumlah = $this->karantina->release($stock, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::QUARANTINE_RELEASE,
            sprintf('Melepas karantina batch %s lebih awal.', $stock->batch_no ?? '—'),
            $stock,
            $stock->warehouse_id,
            ['batch' => $stock->batch_no, 'baris' => $jumlah],
        );

        return back()->with('success', sprintf(
            'Karantina dibatalkan untuk %d baris stok batch %s. Kembali jadi Good Stock.',
            $jumlah,
            $stock->batch_no,
        ));
    }

    /**
     * Menandai satu batch supaya KELUAR DULUAN, mendahului yang lebih tua.
     *
     * Kebalikan karantina, dan satu-satunya penanda yang benar-benar mengubah
     * urutan alokasi. Alasannya WAJIB — lihat StockQuarantine::prioritize().
     */
    public function prioritize(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'stock_id' => ['required', 'integer', 'exists:inventory_stocks,id'],
            'reason' => ['required', 'string', 'max:500'],
        ], [], [
            'reason' => 'alasan',
        ]);

        $stock = InventoryStock::with('product:id,sku')->findOrFail($validated['stock_id']);

        WarehouseScope::assert($stock->warehouse_id, $request->user());

        try {
            $jumlah = $this->karantina->prioritize($stock, $validated['reason'], $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::PRIORITIZE,
            sprintf(
                'Menandai batch %s (%s) DAHULUKAN KELUAR — mendahului batch yang lebih tua. Alasan: %s',
                $stock->batch_no ?? '—',
                $stock->product?->sku ?? '—',
                $validated['reason'],
            ),
            $stock,
            $stock->warehouse_id,
            [
                'sku' => $stock->product?->sku,
                'batch' => $stock->batch_no,
                'baris' => $jumlah,
                'alasan' => $validated['reason'],
            ],
        );

        return back()->with('warning', sprintf(
            '%d baris stok batch %s (%s) ditandai DAHULUKAN KELUAR — batch ini akan dialokasikan lebih dulu '.
            'walau ada batch yang lebih tua. Lepas penandanya begitu tidak diperlukan lagi supaya FIFO kembali normal.',
            $jumlah,
            $stock->batch_no,
            $stock->product?->sku ?? '—',
        ));
    }

    /** Melepas penanda Dahulukan Keluar — batch kembali mengantre menurut umurnya. */
    public function releasePriority(Request $request, InventoryStock $stock): RedirectResponse
    {
        WarehouseScope::assert($stock->warehouse_id, $request->user());

        try {
            $jumlah = $this->karantina->releasePriority($stock, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::PRIORITIZE_RELEASE,
            sprintf('Melepas penanda Dahulukan Keluar dari batch %s — urutan kembali FIFO.', $stock->batch_no ?? '—'),
            $stock,
            $stock->warehouse_id,
            ['batch' => $stock->batch_no, 'baris' => $jumlah],
        );

        return back()->with('success', sprintf(
            'Penanda Dahulukan Keluar dilepas dari %d baris stok batch %s. Urutan kembali FIFO.',
            $jumlah,
            $stock->batch_no,
        ));
    }

    /**
     * Menyalakan/mematikan penanda Masalah Kualitas untuk satu batch.
     *
     * MURNI INFORMASI — tidak menyentuh status maupun kelayakan jual. Lihat
     * App\Support\Inventory\StockQuarantine::toggleQualityIssue().
     */
    public function toggleQualityIssue(Request $request, InventoryStock $stock): RedirectResponse
    {
        WarehouseScope::assert($stock->warehouse_id, $request->user());

        $hasil = $this->karantina->toggleQualityIssue($stock);

        Activity::record(
            ActivityLog::QUALITY_ISSUE,
            sprintf(
                '%s penanda Masalah Kualitas pada batch %s (%s).',
                $hasil['nilai'] ? 'Memasang' : 'Melepas',
                $stock->batch_no ?? '—',
                $stock->product?->sku ?? '—',
            ),
            $stock,
            $stock->warehouse_id,
            ['batch' => $stock->batch_no, 'menyala' => $hasil['nilai'], 'baris' => $hasil['jumlah']],
        );

        // Kalimatnya sengaja menyebut ulang bahwa stoknya TIDAK ditahan.
        // Penanda bernama "Masalah Kualitas" mudah dikira sudah mengunci
        // batch dari penjualan; kalau dikira begitu, batchnya justru tetap
        // terjual tanpa ada yang sadar.
        return back()->with('success', $hasil['nilai']
            ? sprintf(
                '%d baris stok batch %s ditandai "Masalah Kualitas". Penanda ini tidak menahan stok — '.
                'pakai Karantina atau DDP kalau batch ini tidak boleh keluar gudang.',
                $hasil['jumlah'],
                $stock->batch_no,
            )
            : sprintf(
                'Penanda "Masalah Kualitas" dilepas dari %d baris stok batch %s.',
                $hasil['jumlah'],
                $stock->batch_no,
            ));
    }
}
