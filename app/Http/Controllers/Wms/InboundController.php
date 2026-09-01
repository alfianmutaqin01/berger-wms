<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\InboundDetail;
use App\Models\InboundHeader;
use App\Models\Location;
use App\Models\Warehouse;
use App\Support\DocumentNumber;
use App\Support\Inbound\BinAllocator;
use App\Support\Inbound\ProductionSheet;
use App\Support\Inventory\StockActivator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class InboundController extends Controller
{
    /** Berkas produksi sementara, disimpan di disk lokal di luar public. */
    private const TEMP_DIR = 'inbound';

    /**
     * F-INB-01: Riwayat Input Produksi.
     *
     * DATA CONTRACT (view: wms.inbound.history)
     * -----------------------------------------
     * $documents  : LengthAwarePaginator<InboundHeader> — sudah withCount('details')
     *               dan eager-load warehouse + kolom batch_no tiap detail
     * $warehouses : Collection<Warehouse>
     * $statuses   : array<string, string> — slug status => label
     * $stats      : array{total:int, putaway:int, verifikasi:int, selesai:int}
     * $filters    : array{search:?string, status:?string, warehouse_id:?string,
     *                     from:?string, to:?string}
     *
     * CATATAN: satu dokumen bisa memuat BEBERAPA batch, karena satu berkas
     * produksi berisi banyak baris. Karena itu kolom batch menampilkan daftar
     * unik, bukan satu nilai tunggal seperti pada rancangan mock lama.
     */
    public function historyIndex(Request $request): View
    {
        $filters = [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'warehouse_id' => $request->query('warehouse_id'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ];

        $base = InboundHeader::query()
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id));

        $documents = (clone $base)
            ->withCount('details')
            // Hanya kolom batch_no yang diambil dari detail; memuat seluruh
            // kolom untuk ratusan palet hanya untuk menampilkan daftar batch
            // adalah pemborosan.
            ->with(['warehouse:id,code,name', 'details:id,inbound_header_id,batch_no'])
            ->search($filters['search'])
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('production_date', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('production_date', '<=', $to))
            ->latest('production_date')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('wms.inbound.history', [
            'documents' => $documents,
            'warehouses' => Warehouse::orderBy('code')->get(),
            'statuses' => InboundHeader::STATUS_LABELS,
            'stats' => [
                'total' => (clone $base)->count(),
                'putaway' => (clone $base)->where('status', InboundHeader::STATUS_PUTAWAY_PENDING)->count(),
                'verifikasi' => (clone $base)->whereIn('status', [
                    InboundHeader::STATUS_VERIFICATION_PENDING,
                    InboundHeader::STATUS_PARTIAL_VERIFIED,
                ])->count(),
                'selesai' => (clone $base)->where('status', InboundHeader::STATUS_VERIFIED)->count(),
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * F-INB-01: Detail Riwayat Input Produksi.
     *
     * DATA CONTRACT (view: wms.inbound.history-detail)
     * ------------------------------------------------
     * $header  : InboundHeader — eager-load warehouse & creator
     * $details : Collection<InboundDetail> — eager-load product & location,
     *            dikelompokkan per nomor produksi
     * $totals  : array{palet:int, qty:int, produk:int, batch:int}
     *
     * Dicari berdasarkan `document_number`, bukan id, agar URL-nya terbaca
     * manusia dan cocok dengan nomor yang tercetak di dokumen fisik.
     */
    public function historyDetail(string $doc_no): View
    {
        $header = InboundHeader::with(['warehouse', 'creator'])
            ->where('document_number', $doc_no)
            ->firstOrFail();

        $details = $header->details()
            ->with(['product:id,sku,name,uom,max_qty_per_pallet', 'location:id,code'])
            ->orderBy('production_order_no')
            ->orderBy('pallet_no')
            ->get();

        return view('wms.inbound.history-detail', [
            'header' => $header,
            'details' => $details,
            'totals' => [
                'palet' => $details->count(),
                // Qty dijumlahkan dari pallet_qty, BUKAN total_qty: total_qty
                // berulang pada tiap palet yang berasal dari satu baris
                // produksi, sehingga menjumlahkannya akan berlipat ganda.
                'qty' => $details->sum('pallet_qty'),
                'produk' => $details->pluck('product_id')->unique()->count(),
                'batch' => $details->pluck('batch_no')->unique()->count(),
            ],
        ]);
    }

    /**
     * F-INB-01: Form Input Produksi.
     *
     * DATA CONTRACT (view: wms.inbound.create)
     * ----------------------------------------
     * $documentNumber : string    — nomor dokumen yang AKAN dipakai (pratinjau)
     * $productionDate : Carbon    — tanggal hari ini
     * $warehouses     : Collection<Warehouse>
     *
     * Nomor dokumen & tanggal dibangkitkan sistem, tidak diketik Tim Produksi.
     * Nomor di layar ini baru pratinjau; nomor final dikunci saat menyimpan,
     * karena bisa saja ada dokumen lain tersimpan lebih dulu di sela-selanya.
     */
    public function create(): View
    {
        return view('wms.inbound.create', [
            'documentNumber' => DocumentNumber::peek(DocumentNumber::PREFIX_INBOUND, 'inbound_headers'),
            'productionDate' => now(),
            'warehouses' => Warehouse::orderBy('code')->get(),
        ]);
    }

    /**
     * F-INB-01 langkah 4: baca berkas, pecah jadi palet, tampilkan pratinjau.
     *
     * TIDAK menyentuh basis data. Berkas disimpan sementara agar tahap simpan
     * bisa membacanya ulang; berkas itu dihapus begitu disimpan atau dibatalkan
     * sehingga tidak menumpuk di server.
     */
    public function previewExcel(Request $request): View|RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
        ], [], ['file' => 'berkas Excel']);

        // Nama berkas dibangkitkan sendiri, bukan memakai nama asli dari
        // pengguna, agar tidak ada jalur yang bisa diarahkan ke tempat lain.
        $token = Str::uuid()->toString();
        $extension = $request->file('file')->getClientOriginalExtension();
        $stored = self::TEMP_DIR.'/'.$token.'.'.$extension;

        $saved = Storage::disk('local')->putFileAs(
            self::TEMP_DIR,
            $request->file('file'),
            basename($stored)
        );

        // Kegagalan MENULIS diperiksa terpisah dari kegagalan MEMBACA, agar
        // folder yang tidak bisa ditulis tidak dilaporkan sebagai berkas rusak.
        if ($saved === false || ! Storage::disk('local')->exists($stored)) {
            return redirect()->route('wms.inbound.create')->with(
                'error',
                'Berkas gagal disimpan sementara di server. Periksa izin tulis pada folder storage/app/private/'.self::TEMP_DIR.'.'
            );
        }

        try {
            $plan = (new ProductionSheet)->plan(Storage::disk('local')->path($stored));
        } catch (RuntimeException $e) {
            Storage::disk('local')->delete($stored);

            return redirect()->route('wms.inbound.create')->with('error', $e->getMessage());
        }

        return view('wms.inbound.preview', [
            'token' => $token,
            'extension' => $extension,
            'originalName' => $request->file('file')->getClientOriginalName(),
            'warehouse' => Warehouse::find($request->integer('warehouse_id')),
            'documentNumber' => DocumentNumber::peek(DocumentNumber::PREFIX_INBOUND, 'inbound_headers'),
            'productionDate' => now(),
            'rows' => $plan['rows'],
            'summary' => $plan['summary'],
        ]);
    }

    /**
     * F-INB-01 langkah 6: simpan dokumen produksi.
     *
     * Berkas Excel DIHAPUS setelah disimpan — sistem tidak menyimpan berkas
     * mentah, hanya hasil pembacaannya. Berkas dibaca ulang di sini (bukan
     * mempercayai kiriman dari layar pratinjau) supaya angka yang tersimpan
     * benar-benar berasal dari berkas, bukan dari nilai yang bisa diubah
     * lewat peramban.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'uuid'],
            'extension' => ['required', 'in:xlsx,xls'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $stored = self::TEMP_DIR.'/'.$validated['token'].'.'.$validated['extension'];

        if (! Storage::disk('local')->exists($stored)) {
            return redirect()->route('wms.inbound.create')
                ->with('error', 'Berkas sementara sudah tidak tersedia. Silakan unggah ulang.');
        }

        try {
            $plan = (new ProductionSheet)->plan(Storage::disk('local')->path($stored));
        } catch (RuntimeException $e) {
            Storage::disk('local')->delete($stored);

            return redirect()->route('wms.inbound.create')->with('error', $e->getMessage());
        }

        $ready = collect($plan['rows'])->where('status', 'siap');

        if ($ready->isEmpty()) {
            Storage::disk('local')->delete($stored);

            return redirect()->route('wms.inbound.create')
                ->with('error', 'Tidak ada baris yang dapat disimpan. Perbaiki berkas lalu unggah ulang.');
        }

        $header = DB::transaction(function () use ($ready, $validated, $request) {
            $header = InboundHeader::create([
                'document_number' => DocumentNumber::reserve(DocumentNumber::PREFIX_INBOUND, 'inbound_headers'),
                'warehouse_id' => $validated['warehouse_id'],
                'production_date' => now()->toDateString(),
                'status' => InboundHeader::STATUS_PUTAWAY_PENDING,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            // Satu baris Excel menghasilkan satu baris per PALET, bukan satu
            // baris per produk — Operator menerima daftar palet fisik yang
            // siap ditempatkan (PRD §7.1).
            foreach ($ready as $row) {
                foreach ($row['pallets'] as $index => $palletQty) {
                    $header->details()->create([
                        'product_id' => $row['product_id'],
                        'production_order_no' => $row['production_order_no'],
                        'batch_no' => $row['batch_no'],
                        'total_qty' => $row['qty'],
                        'pallet_no' => $index + 1,
                        'pallet_qty' => $palletQty,
                    ]);
                }
            }

            return $header;
        });

        // Berkas mentah tidak disimpan agar tidak menumpuk di server.
        Storage::disk('local')->delete($stored);

        $message = sprintf(
            'Dokumen %s tersimpan: %d baris produksi menjadi %d palet, menunggu put-away.',
            $header->document_number,
            $ready->count(),
            $header->details()->count()
        );

        if ($plan['summary']['gagal'] > 0) {
            $message .= sprintf(' %d baris dilewati karena datanya bermasalah.', $plan['summary']['gagal']);
        }

        return redirect()->route('wms.inbound.history')->with('success', $message);
    }

    /** Membatalkan pratinjau: buang berkas sementara agar tidak menumpuk. */
    public function cancelPreview(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'uuid'],
            'extension' => ['required', 'in:xlsx,xls'],
        ]);

        Storage::disk('local')->delete(self::TEMP_DIR.'/'.$validated['token'].'.'.$validated['extension']);

        return redirect()->route('wms.inbound.create')->with('success', 'Input produksi dibatalkan.');
    }

    /**
     * F-INB-02: Daftar dokumen yang menunggu put-away.
     *
     * DATA CONTRACT (view: wms.inbound.putaway-list)
     * ----------------------------------------------
     * $documents  : LengthAwarePaginator<InboundHeader> — withCount details
     *               (total) & details_placed (sudah punya lokasi)
     * $warehouses : Collection<Warehouse>
     * $stats      : array{dokumen:int, palet:int, belum:int}
     * $filters    : array{search:?string, warehouse_id:?string}
     *
     * Hanya dokumen berstatus `putaway_pending` yang muncul. Dokumen yang
     * put-away-nya baru sebagian tetap di daftar ini — lihat kolom kemajuan —
     * karena pekerjaan fisik lazim terputus dan harus bisa dilanjutkan.
     */
    public function putawayIndex(Request $request): View
    {
        $filters = [
            'search' => $request->query('search'),
            'warehouse_id' => $request->query('warehouse_id'),
        ];

        $base = InboundHeader::query()
            ->awaitingPutaway()
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id));

        $documents = (clone $base)
            ->withCount(['details', 'details as details_placed_count' => fn ($q) => $q->placed()])
            ->with(['warehouse:id,code,name', 'details:id,inbound_header_id,batch_no'])
            ->search($filters['search'])
            // Dokumen terlama didahulukan: barang yang sudah lama menganggur di
            // area terima adalah yang paling mendesak dimasukkan ke rak.
            ->oldest('production_date')
            ->oldest('id')
            ->paginate(15)
            ->withQueryString();

        $paletBase = InboundDetail::whereIn('inbound_header_id', (clone $base)->select('id'));

        return view('wms.inbound.putaway-list', [
            'documents' => $documents,
            'warehouses' => Warehouse::orderBy('code')->get(),
            'stats' => [
                'dokumen' => (clone $base)->count(),
                'palet' => (clone $paletBase)->count(),
                'belum' => (clone $paletBase)->whereNull('location_id')->count(),
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * F-INB-02: Layar penempatan palet ke rak.
     *
     * DATA CONTRACT (view: wms.inbound.putaway-process)
     * -------------------------------------------------
     * $header    : InboundHeader
     * $details   : Collection<InboundDetail> — eager-load product & location
     * $locations : Collection<Location> — SELURUH bin aktif di gudang dokumen
     *              ini; ketersediaan per baris dihitung di sisi klien karena
     *              tergantung SKU baris itu (lihat $occupancy)
     * $occupancy : array<string, array{product_id:int, qty:int, capacity:?int,
     *              uom:?string}> — kode bin => isi bin saat ini
     * $totals    : array{palet:int, ditempatkan:int}
     *
     * Daftar bin dibatasi ke gudang dokumen. Tanpa itu, Operator bisa memilih
     * bin milik gudang lain — kode rak seperti "B-01-01" berulang antar gudang,
     * jadi kesalahannya tidak akan terlihat sampai barangnya dicari.
     */
    public function putawayProcess(string $doc_no): View
    {
        $header = InboundHeader::with('warehouse')
            ->awaitingPutaway()
            ->where('document_number', $doc_no)
            ->firstOrFail();

        $details = $header->details()
            ->with(['product:id,sku,name,uom,max_qty_per_pallet', 'location:id,code'])
            ->orderBy('production_order_no')
            ->orderBy('pallet_no')
            ->get();

        $locations = Location::where('warehouse_id', $header->warehouse_id)
            ->active()
            ->inStorageOrder()
            ->get(['id', 'code', 'zone']);

        return view('wms.inbound.putaway-process', [
            'header' => $header,
            'details' => $details,
            'locations' => $locations,
            'occupancy' => BinAllocator::occupancyByCode($locations),
            'totals' => [
                'palet' => $details->count(),
                'ditempatkan' => $details->whereNotNull('location_id')->count(),
            ],
        ]);
    }

    /**
     * F-INB-02: menyimpan penempatan palet.
     *
     * Aturan yang membentuk method ini:
     *
     * 1. PUT-AWAY BOLEH SEBAGIAN. Palet yang lokasinya dikosongkan hanya
     *    dilewati, tidak menggagalkan penyimpanan. Memaksa semua palet terisi
     *    sekaligus akan membuat Operator kehilangan pekerjaan setengah jalan
     *    setiap kali giliran kerjanya habis.
     * 2. STATUS NAIK HANYA BILA LENGKAP. Dokumen baru berpindah ke
     *    `verification_pending` setelah seluruh paletnya punya lokasi.
     * 3. SATU BIN = SATU SLOT PALET. Boleh memuat beberapa palet dari SKU
     *    yang SAMA sampai kapasitas palet SKU itu (Product::max_qty_per_
     *    pallet) — pallet split (PRD §7.1) boleh digabung kembali di bin
     *    yang sama. SKU yang berbeda TIDAK boleh berbagi bin.
     *
     * Qty Aktual boleh dikoreksi Operator (PRD §6.3 F-INB-02) — SKU dan batch
     * tidak, karena keduanya berasal dari dokumen produksi dan bukan wewenang
     * gudang untuk mengubahnya.
     */
    public function putawayStore(Request $request, string $doc_no): RedirectResponse
    {
        $header = InboundHeader::awaitingPutaway()
            ->where('document_number', $doc_no)
            ->firstOrFail();

        $validated = $request->validate([
            'pallets' => ['required', 'array'],
            'pallets.*.location_code' => ['nullable', 'string', 'max:20'],
            // Batas atas 100.000 mencegah salah ketik yang mustahil secara
            // fisik; palet terbesar di sistem ini memuat 720 pcs.
            'pallets.*.qty_actual' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $details = $header->details()->with('product:id,max_qty_per_pallet')->get()->keyBy('id');
        $allocator = BinAllocator::forWarehouse($header->warehouse_id, $header->warehouse?->code);

        $errors = [];

        // TAHAP 1 — kumpulkan kandidat & periksa isian dasarnya. Belum
        // menyentuh aturan kapasitas: seluruh kandidat harus diketahui lebih
        // dulu supaya bisa dilepas bersama-sama pada tahap 2.
        $kandidat = [];

        foreach ($validated['pallets'] as $detailId => $input) {
            $detail = $details->get((int) $detailId);

            // Palet dari dokumen lain diabaikan diam-diam: id-nya bisa saja
            // dikarang lewat peramban, dan tidak ada alasan sah untuk itu.
            if (! $detail) {
                continue;
            }

            $code = $allocator->normalize((string) ($input['location_code'] ?? ''));

            if ($code === '') {
                continue;
            }

            if (! $allocator->has($code)) {
                $errors["pallets.{$detailId}.location_code"] = $allocator->unknownCodeMessage($code);

                continue;
            }

            $qty = $input['qty_actual'] ?? null;

            if ($qty === null || $qty === '') {
                $errors["pallets.{$detailId}.qty_actual"] = 'Qty Aktual wajib diisi untuk palet yang ditempatkan.';

                continue;
            }

            $kandidat[$detail->id] = ['detail' => $detail, 'code' => $code, 'qty' => (int) $qty];
        }

        // TAHAP 2 — lepas seluruh kandidat dari isi bin lama, lalu tempatkan.
        // Tanpa pelepasan ini, palet yang disimpan ulang terhitung dua kali.
        $allocator->release(array_keys($kandidat));

        $penempatan = [];

        foreach ($kandidat as $detailId => $calon) {
            $hasil = $allocator->place($calon['detail'], $calon['code'], $calon['qty']);

            if (isset($hasil['error'])) {
                $errors["pallets.{$detailId}.location_code"] = $hasil['error'];

                continue;
            }

            $penempatan[$detailId] = [
                'location_id' => $hasil['location_id'],
                'qty_actual' => $calon['qty'],
            ];
        }

        if ($errors !== []) {
            return back()->withErrors($errors)->withInput();
        }

        if ($penempatan === []) {
            return back()->with('error', 'Belum ada palet yang diberi lokasi rak.');
        }

        DB::transaction(function () use ($header, $penempatan, $request) {
            foreach ($penempatan as $detailId => $nilai) {
                $header->details()->whereKey($detailId)->update($nilai + [
                    'putaway_by' => $request->user()?->id,
                    'putaway_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($header->isFullyPlaced()) {
                $header->update(['status' => InboundHeader::STATUS_VERIFICATION_PENDING]);
            }
        });

        $header->refresh();
        $tersisa = $header->details()->whereNull('location_id')->count();

        if ($tersisa > 0) {
            return redirect()->route('wms.inbound.putaway.process', $header->document_number)->with(
                'success',
                sprintf(
                    '%d palet tersimpan. Masih ada %d palet yang belum ditempatkan — dokumen tetap di daftar put-away.',
                    count($penempatan),
                    $tersisa
                )
            );
        }

        return redirect()->route('wms.inbound.putaway')->with('success', sprintf(
            'Put-away dokumen %s selesai: %d palet ditempatkan, kini menunggu verifikasi Logistik.',
            $header->document_number,
            $header->details()->count()
        ));
    }

    /**
     * F-INB-03: Daftar dokumen yang menunggu verifikasi Logistik.
     *
     * DATA CONTRACT (view: wms.inbound.verify-list)
     * ---------------------------------------------
     * $documents  : LengthAwarePaginator<InboundHeader> — withCount details
     *               (total) & details_verified_count
     * $warehouses : Collection<Warehouse>
     * $stats      : array{dokumen:int, palet:int, belum:int, selisih:int}
     * $filters    : array{search:?string, warehouse_id:?string}
     *
     * `selisih` menghitung palet yang qty fisiknya BERBEDA dari qty sistem —
     * itulah yang paling perlu perhatian Logistik, karena di situlah angka
     * final stok diputuskan (PRD §6.3 catatan Maker-Checker).
     */
    public function verifyIndex(Request $request): View
    {
        $filters = [
            'search' => $request->query('search'),
            'warehouse_id' => $request->query('warehouse_id'),
        ];

        $base = InboundHeader::query()
            ->awaitingVerification()
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id));

        $documents = (clone $base)
            ->withCount([
                'details',
                'details as details_verified_count' => fn ($q) => $q->where('is_verified', true),
            ])
            ->with(['warehouse:id,code,name', 'details:id,inbound_header_id,batch_no'])
            ->search($filters['search'])
            // Dokumen terlama didahulukan: barang yang sudah lama menunggu
            // verifikasi adalah stok yang belum bisa dijual sama sekali.
            ->oldest('production_date')
            ->oldest('id')
            ->paginate(15)
            ->withQueryString();

        $paletBase = InboundDetail::whereIn('inbound_header_id', (clone $base)->select('id'));

        return view('wms.inbound.verify-list', [
            'documents' => $documents,
            'warehouses' => Warehouse::orderBy('code')->get(),
            'stats' => [
                'dokumen' => (clone $base)->count(),
                'palet' => (clone $paletBase)->count(),
                'belum' => (clone $paletBase)->where('is_verified', false)->count(),
                'selisih' => (clone $paletBase)
                    ->where('is_verified', false)
                    ->whereNotNull('qty_actual')
                    ->whereColumn('qty_actual', '!=', 'pallet_qty')
                    ->count(),
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * F-INB-03: Layar verifikasi fisik oleh Logistik.
     *
     * DATA CONTRACT (view: wms.inbound.verify-process)
     * ------------------------------------------------
     * $header    : InboundHeader
     * $details   : Collection<InboundDetail> — eager-load product, location,
     *              putawayBy, verifiedBy
     * $locations : Collection<Location> — bin aktif di gudang dokumen ini
     * $occupancy : array<string, array{...}> — isi tiap bin, format sama
     *              dengan layar put-away
     * $totals    : array{palet:int, terverifikasi:int, selisih:int}
     *
     * Logistik boleh mengoreksi Qty dan Lokasi (PRD §6.3 F-INB-03 langkah 8),
     * TAPI TIDAK batch/SKU — lihat catatan panjang di verifyStore().
     */
    public function verifyProcess(string $doc_no): View
    {
        $header = InboundHeader::with('warehouse')
            ->awaitingVerification()
            ->where('document_number', $doc_no)
            ->firstOrFail();

        $details = $header->details()
            ->with([
                'product:id,sku,name,uom,max_qty_per_pallet',
                'location:id,code',
                'putawayBy:id,full_name',
                'verifiedBy:id,full_name',
            ])
            ->orderBy('production_order_no')
            ->orderBy('pallet_no')
            ->get();

        $locations = Location::where('warehouse_id', $header->warehouse_id)
            ->active()
            ->inStorageOrder()
            ->get(['id', 'code', 'zone']);

        return view('wms.inbound.verify-process', [
            'header' => $header,
            'details' => $details,
            'locations' => $locations,
            'occupancy' => BinAllocator::occupancyByCode($locations),
            'totals' => [
                'palet' => $details->count(),
                'terverifikasi' => $details->where('is_verified', true)->count(),
                'selisih' => $details->filter(fn (InboundDetail $d) => $d->qty_variance !== null && $d->qty_variance !== 0)->count(),
            ],
        ]);
    }

    /**
     * F-INB-03: menyimpan hasil verifikasi Logistik.
     *
     * Aturan yang membentuk method ini:
     *
     * 1. VERIFIKASI BOLEH SEBAGIAN (PRD §6.3 F-INB-03 langkah 8: Logistik
     *    boleh MENUNDA). Palet yang belum dicentang tidak menggagalkan
     *    penyimpanan palet yang sudah; dokumen turun ke `partial_verified`
     *    dan tetap muncul di daftar sampai seluruh paletnya selesai.
     * 2. VERIFIKASI TIDAK BISA DIBATALKAN LEWAT LAYAR INI. Palet yang sudah
     *    `is_verified` diabaikan dari perubahan apa pun — PRD §6.3 F-INB-04
     *    menegaskan koreksi pasca-verifikasi HANYA lewat Menu Stok oleh
     *    Manager/Super Admin, karena begitu terverifikasi angkanya sudah
     *    menjadi stok resmi yang mungkin sudah ikut teralokasi ke order.
     * 3. QTY & LOKASI boleh dikoreksi, BATCH & SKU TIDAK. PRD langkah 8
     *    menyebut "qty, lokasi, batch", tapi batch adalah nomor QC yang
     *    menjadi jejak telusur balik ke dokumen produksi — mengubahnya di
     *    gudang memutus rantai itu tanpa jejak. Dikunci mengikuti rancangan
     *    layar (mock) dan konsisten dengan put-away; lihat catatan Fase 3c
     *    di rencana pembangunan.
     * 4. Perpindahan lokasi tetap tunduk aturan kapasitas bin yang SAMA
     *    dengan put-away — lewat App\Support\Inbound\BinAllocator.
     *
     * 5. STOK RESMI AKTIF di sini (PRD langkah 9-10): tiap palet yang
     *    diverifikasi menghasilkan baris `inventory_stocks` + entri `IN` di
     *    `stock_movements`, lewat App\Support\Inventory\StockActivator.
     *    Keduanya berada di dalam transaksi yang sama dengan penandaan
     *    paletnya — stok aktif tanpa palet terverifikasi (atau sebaliknya)
     *    adalah keadaan yang tidak bisa dibetulkan sendiri oleh sistem.
     */
    public function verifyStore(Request $request, string $doc_no): RedirectResponse
    {
        $header = InboundHeader::with('warehouse')
            ->awaitingVerification()
            ->where('document_number', $doc_no)
            ->firstOrFail();

        $validated = $request->validate([
            'pallets' => ['required', 'array'],
            'pallets.*.verified' => ['nullable', 'boolean'],
            'pallets.*.location_code' => ['nullable', 'string', 'max:20'],
            'pallets.*.qty_actual' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $details = $header->details()->with('product:id,max_qty_per_pallet')->get()->keyBy('id');
        $allocator = BinAllocator::forWarehouse($header->warehouse_id, $header->warehouse?->code);

        $errors = [];

        // TAHAP 1 — kumpulkan kandidat & periksa isian dasarnya. Aturan
        // kapasitas belum disentuh: seluruh kandidat harus diketahui lebih
        // dulu supaya bisa dilepas bersama-sama pada tahap 2.
        $kandidat = [];

        foreach ($validated['pallets'] as $detailId => $input) {
            $detail = $details->get((int) $detailId);

            // Palet dari dokumen lain diabaikan diam-diam: id-nya bisa saja
            // dikarang lewat peramban, dan tidak ada alasan sah untuk itu.
            if (! $detail) {
                continue;
            }

            // Sudah terverifikasi -> terkunci (aturan 2 di atas).
            if ($detail->is_verified) {
                continue;
            }

            if (! filter_var($input['verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $code = $allocator->normalize((string) ($input['location_code'] ?? ''));

            if ($code === '') {
                $errors["pallets.{$detailId}.location_code"] = 'Lokasi rak wajib diisi untuk palet yang diverifikasi.';

                continue;
            }

            if (! $allocator->has($code)) {
                $errors["pallets.{$detailId}.location_code"] = $allocator->unknownCodeMessage($code);

                continue;
            }

            $qty = $input['qty_actual'] ?? null;

            if ($qty === null || $qty === '') {
                $errors["pallets.{$detailId}.qty_actual"] = 'Qty wajib diisi untuk palet yang diverifikasi.';

                continue;
            }

            $kandidat[$detail->id] = ['detail' => $detail, 'code' => $code, 'qty' => (int) $qty];
        }

        // TAHAP 2 — palet yang diverifikasi SUDAH menghuni bin sejak put-away;
        // lepas dulu supaya jumlahnya tidak terhitung dua kali (dari database
        // dan dari kiriman formulir).
        $allocator->release(array_keys($kandidat));

        $perubahan = [];

        foreach ($kandidat as $detailId => $calon) {
            $hasil = $allocator->place($calon['detail'], $calon['code'], $calon['qty']);

            if (isset($hasil['error'])) {
                $errors["pallets.{$detailId}.location_code"] = $hasil['error'];

                continue;
            }

            $perubahan[$detailId] = [
                'location_id' => $hasil['location_id'],
                'qty_actual' => $calon['qty'],
                'is_verified' => true,
            ];
        }

        if ($errors !== []) {
            return back()->withErrors($errors)->withInput();
        }

        if ($perubahan === []) {
            return back()->with('error', 'Belum ada palet yang dicentang untuk diverifikasi.');
        }

        DB::transaction(function () use ($header, $perubahan, $request) {
            $activator = new StockActivator;
            $userId = $request->user()?->id;

            foreach ($perubahan as $detailId => $nilai) {
                $header->details()->whereKey($detailId)->update($nilai + [
                    'verified_by' => $userId,
                    'verified_at' => now(),
                    'updated_at' => now(),
                ]);

                // Stok RESMI AKTIF di sini (PRD §6.3 F-INB-03 langkah 9-10).
                // Dibaca ulang dari basis data supaya memakai qty & lokasi
                // yang baru saja disimpan, bukan nilai model yang basi.
                $activator->activate(
                    $header->details()->with(['product:id,shelf_life_months', 'header'])->findOrFail($detailId),
                    $userId
                );
            }

            $header->update(['status' => $header->resolveVerificationStatus()]);
        });

        $header->refresh();
        $tersisa = $header->details()->where('is_verified', false)->count();

        if ($tersisa > 0) {
            return redirect()->route('wms.inbound.verify.process', $header->document_number)->with(
                'success',
                sprintf(
                    '%d palet terverifikasi. Masih ada %d palet yang belum diverifikasi — dokumen tetap di daftar verifikasi.',
                    count($perubahan),
                    $tersisa
                )
            );
        }

        return redirect()->route('wms.inbound.verify')->with('success', sprintf(
            'Verifikasi dokumen %s selesai: %d palet terverifikasi dan stoknya kini aktif.',
            $header->document_number,
            $header->details()->count()
        ));
    }

    public function returnsIndex()
    {
        $pendingReturns = session('pending_returns', []);

        return view('wms.inbound.returns', compact('pendingReturns'));
    }

    public function processReturn($id, Request $request)
    {
        $pending = session('pending_returns', []);
        $newPending = [];
        foreach ($pending as $retur) {
            if ($retur['id'] !== $id) {
                $newPending[] = $retur;
            }
        }
        session(['pending_returns' => $newPending]);
        session()->put('processed_return_'.$id, $request->alokasi);

        return redirect()->back()->with('success', 'Barang retur berhasil dialokasikan ke '.$request->alokasi.'.');
    }
}
