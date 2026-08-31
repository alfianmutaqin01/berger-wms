<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\InboundDetail;
use App\Models\InboundHeader;
use App\Models\Location;
use App\Models\Warehouse;
use App\Support\DocumentNumber;
use App\Support\Inbound\ProductionSheet;
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
     * $locations : Collection<Location> — bin aktif di GUDANG DOKUMEN INI saja
     * $occupancy : array<string, int> — kode bin => jumlah palet yang sudah ada
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

        // SATU BIN = SATU PALET. Isian di sini dipakai untuk dua hal:
        //   1. Menyingkirkan bin yang sudah terisi dari daftar rekomendasi.
        //   2. Menampilkan isinya bila Operator tetap mengetik kode itu
        //      manual, supaya jelas KENAPA kode tersebut ditolak saat simpan.
        $perId = InboundDetail::query()
            ->whereIn('location_id', $locations->pluck('id'))
            ->get(['location_id', 'qty_actual', 'pallet_qty'])
            ->keyBy('location_id');

        // Dikunci dengan KODE, bukan id, karena Operator mengetik kode rak —
        // pencocokan di layar jadi langsung tanpa tabel penerjemah kedua.
        $occupancy = $locations
            ->filter(fn (Location $l) => $perId->has($l->id))
            ->mapWithKeys(fn (Location $l) => [$l->code => $perId[$l->id]->effective_qty])
            ->all();

        $availableLocations = $locations->reject(fn (Location $l) => isset($occupancy[$l->code]))->values();

        return view('wms.inbound.putaway-process', [
            'header' => $header,
            'details' => $details,
            'locations' => $availableLocations,
            'occupancy' => $occupancy,
            'totals' => [
                'palet' => $details->count(),
                'ditempatkan' => $details->whereNotNull('location_id')->count(),
            ],
        ]);
    }

    /**
     * F-INB-02: menyimpan penempatan palet.
     *
     * Dua aturan yang membentuk method ini:
     *
     * 1. PUT-AWAY BOLEH SEBAGIAN. Palet yang lokasinya dikosongkan hanya
     *    dilewati, tidak menggagalkan penyimpanan. Memaksa semua palet terisi
     *    sekaligus akan membuat Operator kehilangan pekerjaan setengah jalan
     *    setiap kali giliran kerjanya habis.
     * 2. STATUS NAIK HANYA BILA LENGKAP. Dokumen baru berpindah ke
     *    `verification_pending` setelah seluruh paletnya punya lokasi.
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

        $details = $header->details()->get()->keyBy('id');

        // Kode bin dipetakan sekali di muka, bukan satu query per palet.
        $bins = Location::where('warehouse_id', $header->warehouse_id)
            ->active()
            ->get(['id', 'code'])
            ->keyBy(fn (Location $l) => strtoupper($l->code));

        // SATU BIN = SATU PALET. Bin yang sudah dipakai palet LAIN (di dokumen
        // manapun) diperiksa di sini; bin milik palet ini sendiri (put-away
        // ulang/koreksi) dikecualikan agar tidak menolak dirinya sendiri.
        $sudahDipakai = InboundDetail::whereNotNull('location_id')
            ->whereNotIn('id', $details->keys())
            ->pluck('location_id', 'location_id');

        $penempatan = [];
        $errors = [];
        $dipakaiDalamPengiriman = [];

        foreach ($validated['pallets'] as $detailId => $input) {
            $detail = $details->get((int) $detailId);

            // Palet dari dokumen lain diabaikan diam-diam: id-nya bisa saja
            // dikarang lewat peramban, dan tidak ada alasan sah untuk itu.
            if (! $detail) {
                continue;
            }

            $code = strtoupper(trim((string) ($input['location_code'] ?? '')));

            if ($code === '') {
                continue;
            }

            if (! $bins->has($code)) {
                $errors["pallets.{$detailId}.location_code"] =
                    "Kode lokasi \"{$code}\" tidak ada atau tidak aktif di gudang {$header->warehouse?->code}.";

                continue;
            }

            $locationId = $bins->get($code)->id;

            if ($sudahDipakai->has($locationId)) {
                $errors["pallets.{$detailId}.location_code"] = "Rak \"{$code}\" sudah terisi palet lain.";

                continue;
            }

            if (isset($dipakaiDalamPengiriman[$locationId])) {
                $errors["pallets.{$detailId}.location_code"] = "Rak \"{$code}\" juga dipilih untuk palet lain pada pengiriman ini.";

                continue;
            }

            $qty = $input['qty_actual'] ?? null;

            if ($qty === null || $qty === '') {
                $errors["pallets.{$detailId}.qty_actual"] = 'Qty Aktual wajib diisi untuk palet yang ditempatkan.';

                continue;
            }

            $dipakaiDalamPengiriman[$locationId] = true;

            $penempatan[$detail->id] = [
                'location_id' => $locationId,
                'qty_actual' => (int) $qty,
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
     * F-INB-03: List Verifikasi Logistik
     */
    public function verifyIndex()
    {
        $dummyVerifications = [
            [
                'batch_no' => 'BCH-202608-01',
                'doc_no' => 'PROD-8821',
                'date' => '18 Aug 2026',
                'total_pallets' => 3,
                'status' => 'Menunggu Verifikasi',
            ],
        ];

        return view('wms.inbound.verify-list', compact('dummyVerifications'));
    }

    /**
     * F-INB-03: Detail Verifikasi Logistik
     */
    public function verifyProcess($doc_no)
    {
        $inbound = ['doc_no' => $doc_no, 'batch_no' => 'BCH-202608-01',
            'date' => '18 Aug 2026',
        ];

        // This simulates pallets that ALREADY have locations set by Operator Gudang
        $pallets = [
            [
                'id' => 1,
                'pallet_no' => 'PLT-001',
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'batch' => 'BCH-202608-01',
                'qty' => 180,
                'location' => 'G-03-01',
            ],
            [
                'id' => 2,
                'pallet_no' => 'PLT-002',
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'batch' => 'BCH-202608-01',
                'qty' => 180,
                'location' => 'G-03-02',
            ],
            [
                'id' => 3,
                'pallet_no' => 'PLT-003',
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'batch' => 'BCH-202608-01',
                'qty' => 140,
                'location' => 'G-03-03',
            ],
        ];

        $availableLocations = ['G-03-01 (Kosong)', 'G-03-02 (Kosong)', 'G-03-03 (Kosong)', 'G-03-04 (Kosong)', 'G-03-05 (Kosong)'];

        return view('wms.inbound.verify-process', compact('inbound', 'pallets', 'availableLocations'));
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
