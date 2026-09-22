<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\InventoryStock;
use App\Models\ProductCategory;
use App\Support\FilterTanggal;
use App\Support\ShelfLife;
use App\Support\WarehouseScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Modul Inventory / Stok — PRD §6.4: layar Data Stok.
 *
 * Stok TIDAK disimpan sebagai satu angka per produk. Tiap kombinasi
 * produk × lokasi × batch adalah barisnya sendiri, karena FIFO (§7.2) dan
 * aturan kedaluwarsa (§7.2.1) menuntut batch tetap terpisah.
 *
 * Tindakan yang mengubah stok dari layar ini ada di controller sendiri:
 * StockAdjustmentController (koreksi & tambah stok), StockRelocationController
 * (pindah rak), dan BatchFlagController (karantina, Dahulukan Keluar,
 * Quality Issue).
 */
class InventoryController extends Controller
{
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

        ['filters' => $filters, 'base' => $base, 'terpilih' => $terpilih] = $this->saringan($request);

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
                    // RINCIAN PER GUDANG.
                    //
                    // Satu baris = satu SKU, dan bagi akun lintas gudang itu
                    // berarti angkanya MENJUMLAHKAN GUDANG YANG BERBEDA tanpa
                    // mengatakannya. Pernah terbaca sebagai selisih stocktake:
                    // layar ini menunjukkan 234 sementara laporan stocktake
                    // Karawang menyebut 55 — padahal 180 di antaranya sudah
                    // dipindah ke Pekanbaru berbulan-bulan sebelumnya dan
                    // stocktake-nya benar.
                    //
                    // Kode gudangnya sendiri nyaris kembar (ID11_1001 vs
                    // ID1I_1001), jadi menyandarkan pembacanya pada label kecil
                    // di tiap baris batch tidak cukup — yang dibaca lebih dulu
                    // adalah angka besar di kepala baris.
                    'per_gudang' => $isi
                        ->groupBy(fn ($s) => $s->warehouse?->code ?? '—')
                        ->map(fn ($g) => (int) $g->sum('qty_available'))
                        ->sortKeys(),
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
                    ->whereDate('expiry_date', '<=', now()->addDays(ShelfLife::warningDays())->toDateString())
                    ->count(),
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * Penyaring halaman Data Stok — daftar SKU maupun batch di dalamnya.
     *
     * Dipisah dari index() supaya kriterianya berdiri sebagai satu blok yang
     * terbaca sekaligus. Unduhan Excel TIDAK memakainya: tombol Export di
     * halaman ini mengarah ke pratinjau laporan Posisi Stok / Pergerakan
     * Stok, yang punya query-nya sendiri di ReportRunner. Menyalin logika
     * penyaring ke sana hanya akan melahirkan dua definisi "stok" yang suatu
     * hari berbeda.
     *
     * @return array{filters: array<string, mixed>, base: Builder, terpilih: \Closure}
     */
    private function saringan(Request $request): array
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
            'production_date' => FilterTanggal::bersih($request->query('production_date')),
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
            // BARIS KOSONG TIDAK DITAMPILKAN. Baris dengan tersedia DAN
            // teralokasi sama-sama nol bukan stok — ia sisa dari batch yang
            // sudah habis atau seluruhnya dipindah ke rak lain. Menampilkannya
            // membuat orang membaca "batch ini ada di rak ZB-01-01" padahal
            // raknya kosong, dan pada gudang yang sudah lama berjalan baris
            // semacam ini menumpuk sampai menenggelamkan stok yang sungguhan.
            //
            // TIDAK DIHAPUS, hanya disembunyikan: barisnya masih dirujuk
            // stock_movements sebagai reference_id, dan riwayat "batch ini
            // pernah di rak itu" tetap terbaca di buku besar.
            //
            // teralokasi ikut diperiksa, bukan cuma tersedia: batch yang
            // habis dicadangkan untuk pesanan masih berdiri di rak dan WAJIB
            // terlihat — kalau tidak, operator picking mencari barang yang
            // menurut layar tidak ada.
            ->whereRaw('(qty_available + qty_allocated) > 0')
            ->search($filters['search'])
            ->when($filters['location_id'], fn ($q, $id) => $q->where('location_id', $id))
            ->when($filters['batch'], fn ($q, $b) => $q->where('batch_no', 'ILIKE', '%'.$b.'%'))
            ->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
            ->when($filters['production_date'], fn ($q, $d) => $q->whereDate('production_date', $d))
            ->when($filters['expiring'], fn ($q) => $q
                ->where('status', InventoryStock::STATUS_ACTIVE)
                ->whereDate('expiry_date', '<=', now()->addDays(ShelfLife::warningDays())->toDateString()));

        return ['filters' => $filters, 'base' => $base, 'terpilih' => $terpilih];
    }
}
