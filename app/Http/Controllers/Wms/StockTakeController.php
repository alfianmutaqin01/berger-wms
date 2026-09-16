<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\Warehouse;
use App\Support\Activity;
use App\Support\Export\XlsxWriter;
use App\Support\Inventory\BatchProduksi;
use App\Support\Inventory\StockTakeRun;
use App\Support\WarehouseScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stocktake — mencocokkan angka sistem dengan barang yang benar-benar ada
 * di rak, sebulan atau tiga bulan sekali (permintaan pemilik produk).
 *
 * MENGAPA MENU SENDIRI, BUKAN MENUMPANG DENAH
 * -------------------------------------------
 * Denah menjawab "di mana barangnya" dan boleh dibuka kapan saja. Stocktake
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
    /** Huruf minimal sebelum pencarian produk dijalankan. */
    private const MIN_CARI = 2;

    /** Batas saran yang dikirim ke layar. */
    private const MAKS_SARAN = 10;

    public function __construct(private readonly StockTakeRun $stocktake) {}

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
            $sesi = $this->stocktake->open(
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
            'Sesi stocktake %s dibuka. %d baris stok dibekukan angkanya — stok belum berubah sama sekali '.
            'sampai laporannya disahkan.',
            $sesi->reference,
            $sesi->items()->count(),
        ));
    }

    /**
     * Layar penghitungan, disusun deret -> rak seperti denah.
     *
     * KENAPA ADA PENYARING DERET
     * --------------------------
     * Stocktake lazim dikerjakan beberapa orang sekaligus dengan pembagian
     * deret. Tanpa penyaring, orang yang kebagian deret C harus menggulir
     * melewati deret A dan B yang sedang dikerjakan orang lain — dan di situ
     * baris orang lain gampang terisi tanpa sengaja. Menyaring ke deretnya
     * sendiri membuat layarnya hanya memuat pekerjaan miliknya.
     *
     * Penyaringnya hanya MENYEMBUNYIKAN, tidak membagi kepemilikan: baris yang
     * tersaring tetap milik sesi yang sama dan tetap ikut ke laporan. Sistem
     * ini tidak menugaskan deret kepada orang tertentu, dan layar ini tidak
     * berpura-pura melakukannya.
     *
     * Pencarian SKU untuk kebalikannya: satu SKU bisa tersebar di banyak palet
     * dan banyak rak, dan kadang yang dicari justru "di mana saja barang ini".
     */
    public function show(Request $request, StockTake $stocktake): View
    {
        WarehouseScope::assert($stocktake->warehouse_id, $request->user());

        $filter = [
            'rak' => trim((string) $request->query('rak')),
            'q' => trim((string) $request->query('q')),
        ];

        $items = $stocktake->items()
            ->with(['location:id,code,rack,level,cell', 'product:id,sku,name,uom', 'countedBy:id,full_name'])
            ->when($filter['rak'] !== '', fn ($q, $ada) => $q->whereHas(
                'location', fn ($l) => $l->where('rack', $filter['rak']),
            ))
            ->when($filter['q'] !== '', fn ($q) => $q->whereHas(
                'product',
                fn ($p) => $p->where('sku', 'ILIKE', '%'.$filter['q'].'%')
                    ->orWhere('name', 'ILIKE', '%'.$filter['q'].'%'),
            ))
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
            // Daftar deret diambil dari SELURUH sesi, bukan dari hasil yang
            // sudah tersaring — kalau tidak, memilih satu deret akan membuang
            // pilihan deret lainnya dan tidak ada jalan kembali selain
            // menyunting URL.
            'daftarDeret' => $stocktake->items()
                ->join('locations', 'locations.id', '=', 'stock_take_items.location_id')
                ->distinct()->orderBy('locations.rack')->pluck('locations.rack'),
            'filter' => $filter,
            // Angka ringkas SELALU untuk seluruh sesi, tidak ikut tersaring.
            // "3 dari 5 baris dihitung" yang diam-diam berarti "3 dari 5 di
            // deret B" akan membuat orang menutup sesi yang belum selesai.
            'ringkasan' => $this->ringkasan($stocktake),
            'rakPilihan' => $stocktake->sedangDihitung()
                ? Location::where('warehouse_id', $stocktake->warehouse_id)
                    ->where('is_active', true)
                    ->orderBy('code')->get(['id', 'code'])
                : collect(),
        ]);
    }

    /**
     * Mencatat barang yang ditemukan di rak tetapi tidak ada di sistem.
     *
     * Kebalikan dari menghitung 0, dan sampai sekarang satu-satunya arah yang
     * tidak punya jalur sama sekali di layar mana pun.
     *
     * Izinnya STOCKTAKE_COUNT, sama dengan mengisi hitungan biasa. Terlihat
     * lebih berbahaya karena "menciptakan stok" — padahal menghitung 50 pada
     * baris yang sistemnya 0 melakukan hal yang persis sama, dan itu sudah
     * boleh sejak awal. Keduanya sama-sama baru menyentuh stok saat laporan
     * disahkan Manager.
     */
    public function found(Request $request, StockTake $stocktake): RedirectResponse
    {
        WarehouseScope::assert($stocktake->warehouse_id, $request->user());

        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'batch_no' => ['required', 'string', 'max:50'],
            // TIDAK LAGI WAJIB: tanggal produksi dibaca dari nomor batchnya
            // sendiri (lihat BatchProduksi). Isian ini hanya jalan cadangan
            // untuk batch lama yang tidak mengikuti pola itu.
            //
            // Tidak boleh di masa depan: kedaluwarsa dihitung dari tanggal
            // ini, dan tanggal maju memberi umur simpan yang tidak pernah
            // dimiliki palet itu.
            'production_date' => ['nullable', 'date', 'before_or_equal:'.now()->toDateString()],
            'qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], [
            'location_id' => 'rak',
            'product_id' => 'produk',
            'batch_no' => 'batch',
            'production_date' => 'tanggal produksi',
            'qty' => 'jumlah',
        ]);

        // Nomor batchnya yang menentukan. Isian manual hanya dipakai kalau
        // nomor itu memang tidak memuat tanggal — bukan untuk menimpanya,
        // supaya tidak ada dua keterangan yang saling bertentangan tentang
        // palet yang sama.
        $tanggal = BatchProduksi::tanggal($data['batch_no']) ?? ($data['production_date'] ?? null);

        if ($tanggal === null) {
            return back()->withInput()->with('error', sprintf(
                'Nomor batch %s tidak memuat tahun dan bulan produksi, jadi tanggalnya tidak bisa dibaca '.
                'otomatis. Isi tanggal produksinya dari label palet.',
                trim($data['batch_no']),
            ));
        }

        try {
            $item = $this->stocktake->catatTemuan($stocktake, [
                'location_id' => (int) $data['location_id'],
                'product_id' => (int) $data['product_id'],
                'batch_no' => $data['batch_no'],
                'production_date' => $tanggal,
                'qty' => (int) $data['qty'],
                'note' => $data['note'] ?? null,
            ], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        Activity::record(
            ActivityLog::STOCKTAKE_FOUND,
            sprintf(
                'Temuan stocktake %s: %d unit %s batch %s di rak %s.',
                $stocktake->reference,
                $item->qty_physical,
                $item->product?->sku ?? '—',
                $item->batch_no,
                $item->location?->code ?? '—',
            ),
            $stocktake,
            $stocktake->warehouse_id,
            [
                'referensi' => $stocktake->reference,
                'rak' => $item->location?->code,
                'sku' => $item->product?->sku,
                'batch' => $item->batch_no,
                'qty' => $item->qty_physical,
            ],
        );

        // Tanggal produksinya ikut disebut. Ia dibaca otomatis dari nomor
        // batch, dan pembacaan yang tidak pernah diperlihatkan adalah
        // pembacaan yang tidak pernah dikoreksi kalau salah.
        return back()->with('success', sprintf(
            'Temuan tercatat: %d unit batch %s di rak %s, produksi %s. Stok BELUM bertambah — barang ini baru '.
            'masuk sistem saat laporan sesi ini disahkan, sama seperti hitungan lainnya.',
            $item->qty_physical,
            $item->batch_no,
            $item->location?->code ?? '—',
            $item->found_production_date?->translatedFormat('F Y') ?? '—',
        ));
    }

    /**
     * Pencarian produk sambil mengetik untuk formulir temuan.
     *
     * Master produk berisi ribuan baris. Menyodorkan seluruhnya sebagai
     * dropdown berarti operator yang berdiri di depan rak dengan HP harus
     * menggulir ribuan pilihan.
     */
    public function lookupProducts(Request $request): JsonResponse
    {
        $cari = trim((string) $request->query('q'));

        if (mb_strlen($cari) < self::MIN_CARI) {
            return response()->json([]);
        }

        return response()->json(
            Product::query()
                ->where('is_active', true)
                ->where(fn ($q) => $q->where('sku', 'ILIKE', '%'.$cari.'%')
                    ->orWhere('name', 'ILIKE', '%'.$cari.'%'))
                ->orderBy('sku')
                ->limit(self::MAKS_SARAN)
                ->get(['id', 'sku', 'name', 'uom'])
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'teks' => $p->sku.' — '.$p->name,
                    'ket' => $p->uom,
                ]),
        );
    }

    /**
     * Menyimpan hasil hitungan satu baris.
     *
     * MENJAWAB DUA PEMANGGIL, dan itu disengaja. Layar penghitungan
     * mengirimnya lewat fetch() dan menerima JSON, sehingga halaman TIDAK
     * dimuat ulang: sesi stocktake bisa berisi ribuan baris, dan memuat ulang
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
            $this->stocktake->count(
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
     * Sesudahnya pengguna dibawa kembali ke halaman laporan; stok terbaru
     * berlaku bersamaan dengan disahkannya laporan itu.
     */
    public function finalize(Request $request, StockTake $stocktake): RedirectResponse
    {
        WarehouseScope::assert($stocktake->warehouse_id, $request->user());

        try {
            $hasil = $this->stocktake->finalize($stocktake, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::STOCKTAKE_FINALIZE,
            sprintf(
                'Mengesahkan laporan stocktake %s: %d baris disesuaikan (+%d / -%d unit), %d baris belum dihitung.',
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
            'Laporan stocktake %s disahkan. %d baris disesuaikan (+%d / -%d unit).',
            $stocktake->reference,
            $hasil['disesuaikan'],
            $hasil['naik'],
            $hasil['turun'],
        );

        if ($hasil['belum'] > 0) {
            // Dikatakan apa adanya. Laporan yang menyembunyikan bagian yang
            // belum dihitung akan dibaca sebagai "seluruh gudang sudah cocok".
            $pesan .= sprintf(
                ' %d baris TIDAK sempat dihitung dan sengaja tidak disentuh — cakupan stocktake ini belum penuh.',
                $hasil['belum'],
            );
        }

        return redirect()->route('wms.stocktake.report', $stocktake)
            ->with($hasil['belum'] > 0 ? 'warning' : 'success', $pesan);
    }

    public function cancel(Request $request, StockTake $stocktake): RedirectResponse
    {
        WarehouseScope::assert($stocktake->warehouse_id, $request->user());

        try {
            $this->stocktake->cancel($stocktake, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('wms.stocktake.index')->with('success', sprintf(
            'Sesi stocktake %s dibatalkan. Tidak ada angka stok yang berubah.',
            $stocktake->reference,
        ));
    }

    /**
     * Laporan stok global hasil stocktake — per SKU, bukan per rak.
     *
     * Yang ditanyakan pembacanya adalah "SKU ini sekarang berapa", dan
     * jawabannya tidak boleh berupa daftar rak yang harus dijumlahkan sendiri.
     * Rincian per raknya tetap ada di layar penghitungan.
     */
    public function report(Request $request, StockTake $stocktake): View
    {
        WarehouseScope::assert($stocktake->warehouse_id, $request->user());

        return view('wms.inventory.stocktake-report', [
            'sesi' => $stocktake->load([
                'warehouse', 'openedBy:id,full_name', 'finalizedBy:id,full_name',
            ]),
            'baris' => $this->barisLaporan($stocktake),
            'ringkasan' => $this->ringkasan($stocktake),
        ]);
    }

    /**
     * Isi laporan, dipakai bersama oleh layar dan berkas Excel.
     *
     * Ditulis sekali karena keduanya harus menjawab pertanyaan yang sama
     * dengan angka yang sama. Kalau disalin, suatu hari salah satunya diberi
     * penyesuaian dan yang lain tidak — dan tidak ada yang menyadarinya,
     * karena keduanya tetap menghasilkan angka yang masuk akal.
     *
     * @return list<array<string, mixed>>
     */
    private function barisLaporan(StockTake $stocktake): array
    {
        return $stocktake->items()
            ->with('product:id,sku,name,uom')
            ->get()
            ->groupBy('product_id')
            ->map(function ($isi) use ($stocktake) {
                $pertama = $isi->first();

                // SEBELUM DISAHKAN, angka "sesudah" adalah RAMALAN dari hasil
                // hitungan; sesudah disahkan, ia angka yang benar-benar
                // diterapkan (qty_after).
                //
                // Dulu keduanya sama-sama membaca qty_after — padahal kolom itu
                // baru terisi saat pengesahan. Akibatnya pratinjau SELALU
                // menunjukkan sesudah = sebelum, selisih 0, dan +0/-0, bahkan
                // ketika layar penghitungan sudah menemukan selisih. Justru di
                // pratinjau itulah orang memeriksa sebelum menekan "Sahkan",
                // jadi laporan yang selalu bersih membuat pemeriksaannya sia-sia.
                //
                // Baris yang belum dihitung tetap menyumbang qty_system: stoknya
                // memang tidak akan disentuh, jadi selisihnya nol dan totalnya
                // tetap jujur.
                $sesudah = $isi->sum(fn (StockTakeItem $i) => $stocktake->sudahDisahkan()
                    ? ($i->qty_after ?? $i->qty_system)
                    : ($i->qty_physical ?? $i->qty_system));
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
    }

    /**
     * Laporan stocktake sebagai berkas .xlsx.
     *
     * PRATINJAU BOLEH DIUNDUH, TETAPI MENGAKU DI DALAM BERKASNYA
     * ----------------------------------------------------------
     * Sesi yang belum disahkan sengaja tetap bisa diunduh — justru di situlah
     * gunanya, karena pemeriksaannya dikerjakan beberapa orang dan lebih mudah
     * dibagi sebagai berkas. Tetapi berkas Excel hidup lebih lama daripada
     * layar yang melahirkannya: ia di-forward dan dibuka lagi berbulan-bulan
     * kemudian oleh orang yang tidak pernah melihat layarnya. Karena itu
     * keterangan "BELUM DISAHKAN" ikut tertulis di kepala berkasnya sendiri,
     * bukan hanya di layar.
     */
    public function download(Request $request, StockTake $stocktake): StreamedResponse
    {
        WarehouseScope::assert($stocktake->warehouse_id, $request->user());

        $tabel = $this->barisLaporan($stocktake);
        $ringkasan = $this->ringkasan($stocktake);

        $keterangan = [
            'Referensi' => $stocktake->reference,
            'Gudang' => trim(($stocktake->warehouse?->code ?? '—').' — '.($stocktake->warehouse?->name ?? '')),
            'Cakupan' => $stocktake->scope_label,
            'Dibuka' => $stocktake->opened_at?->format('d/m/Y H:i').' oleh '.($stocktake->openedBy?->full_name ?? '—'),
            'Status' => $stocktake->sudahDisahkan()
                ? 'DISAHKAN '.$stocktake->finalized_at?->format('d/m/Y H:i').' oleh '.($stocktake->finalizedBy?->full_name ?? '—')
                : 'BELUM DISAHKAN — angka "sesudah" di berkas ini BELUM berlaku di gudang',
            'Baris dihitung' => $ringkasan['dihitung'].' dari '.$ringkasan['baris'],
            'Diunduh' => now()->format('d/m/Y H:i').' WIB',
        ];

        // Baris yang tidak sempat dihitung HARUS mengaku di dalam berkasnya
        // sendiri. Peringatan yang hanya ada di layar hilang begitu berkasnya
        // diteruskan, dan penerimanya membaca laporan ini sebagai "seluruh
        // gudang sudah cocok".
        if ($ringkasan['belum'] > 0) {
            $keterangan['PERHATIAN'] = number_format($ringkasan['belum']).' baris tidak dihitung dan '
                .'tidak disentuh sama sekali — cakupan stocktake ini belum penuh.';
        }

        Activity::record(
            ActivityLog::REPORT_EXPORT,
            sprintf('Mengunduh laporan stocktake %s — %d SKU.', $stocktake->reference, count($tabel)),
            $stocktake,
            $stocktake->warehouse_id,
            [
                'laporan' => 'stocktake',
                'referensi' => $stocktake->reference,
                'sku' => count($tabel),
                'disahkan' => $stocktake->sudahDisahkan(),
            ],
        );

        return XlsxWriter::unduh(
            'stocktake-'.$stocktake->reference.'-'.now()->format('Ymd-Hi').'.xlsx',
            'Laporan Stocktake '.$stocktake->reference,
            ['SKU', 'Deskripsi', 'Satuan', 'Stok Sebelum', 'Stok Sesudah', 'Selisih', 'Baris Rak', 'Belum Dihitung'],
            array_map(fn (array $b) => [
                $b['sku'], $b['nama'], $b['uom'],
                $b['sebelum'], $b['sesudah'], $b['selisih'], $b['baris'], $b['belum'],
            ], $tabel),
            [3, 4, 5, 6, 7],
            $keterangan,
        );
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
