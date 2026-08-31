<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\InboundHeader;
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
     * F-INB-02: List Put-away
     */
    public function putawayIndex()
    {
        $dummyInbounds = [
            [
                'batch_no' => 'BCH-202608-01',
                'doc_no' => 'PROD-8821',
                'date' => '18 Aug 2026',
                'total_pallets' => 3,
                'status' => 'Menunggu Put-away',
            ],
            [
                'batch_no' => 'BCH-202608-02',
                'doc_no' => 'PROD-8822',
                'date' => '18 Aug 2026',
                'total_pallets' => 5,
                'status' => 'Menunggu Put-away',
            ],
        ];

        return view('wms.inbound.putaway-list', compact('dummyInbounds'));
    }

    /**
     * F-INB-02: Detail Put-away
     */
    public function putawayProcess($doc_no)
    {
        $inbound = ['doc_no' => $doc_no, 'batch_no' => 'BCH-202608-01',
            'date' => '18 Aug 2026',
        ];

        $pallets = [
            [
                'id' => 1,
                'pallet_no' => 'PLT-001',
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'batch' => 'BCH-202608-01',
                'qty' => 180,
                'location' => '',
            ],
            [
                'id' => 2,
                'pallet_no' => 'PLT-002',
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'batch' => 'BCH-202608-01',
                'qty' => 180,
                'location' => '',
            ],
            [
                'id' => 3,
                'pallet_no' => 'PLT-003',
                'sku' => 'BP-5KG-WHT',
                'description' => 'Cat Tembok Berger White 5Kg',
                'batch' => 'BCH-202608-01',
                'qty' => 140,
                'location' => '',
            ],
        ];

        $availableLocations = ['G-03-01 (Kosong)', 'G-03-02 (Kosong)', 'G-03-03 (Kosong)', 'G-03-04 (Kosong)', 'G-03-05 (Kosong)'];

        return view('wms.inbound.putaway-process', compact('inbound', 'pallets', 'availableLocations'));
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
