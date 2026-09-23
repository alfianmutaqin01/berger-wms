<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\InboundDetail;
use App\Models\InboundHeader;
use App\Models\Location;
use App\Models\Warehouse;
use App\Support\Activity;
use App\Support\Inbound\BinAllocator;
use App\Support\Inventory\StockActivator;
use App\Support\Outbound\PendingAllocationFiller;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Verifikasi Maker-Checker — PRD §6.3 F-INB-03: Logistik mengesahkan hasil
 * put-away, dan saat itulah stok resmi aktif.
 */
class InboundVerificationController extends Controller
{
    public function __construct(private readonly PendingAllocationFiller $pengisi) {}

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
            'warehouse_id' => WarehouseScope::resolveFilter($request, $request->user()),
        ];

        $base = WarehouseScope::apply(InboundHeader::query(), $request->user())
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
            'warehouses' => WarehouseScope::options($request->user()),
            'stats' => [
                'dokumen' => (clone $base)->count(),
                'palet' => (clone $paletBase)->count(),
                'belum' => (clone $paletBase)->where('is_verified', false)->count(),
                // berselisih() membandingkan ke angka SEMULA, bukan ke
                // pallet_qty — penyesuaian Tim Produksi tidak boleh membuat
                // palet ini menghilang dari perhatian Logistik.
                'selisih' => (clone $paletBase)
                    ->where('is_verified', false)
                    ->berselisih()
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
    public function verifyProcess(Request $request, string $doc_no): View
    {
        $header = InboundHeader::with('warehouse')
            ->awaitingVerification()
            ->where('document_number', $doc_no)
            ->firstOrFail();

        WarehouseScope::assert($header->warehouse_id, $request->user());

        $details = $header->details()
            ->with([
                'product:id,sku,name,uom,pack_unit,pack_size,max_qty_per_pallet',
                'location:id,code',
                'putawayBy:id,full_name',
                'verifiedBy:id,full_name',
            ])
            ->orderBy('production_order_no')
            ->orderBy('pallet_no')
            ->get();

        $locations = Location::where('warehouse_id', $header->warehouse_id)
            ->active()
            ->penyimpanan()
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
     *    layar (mock) dan konsisten dengan put-away.
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

        WarehouseScope::assert($header->warehouse_id, $request->user());

        $validated = $request->validate([
            'pallets' => ['required', 'array'],
            'pallets.*.verified' => ['nullable', 'boolean'],
            'pallets.*.location_code' => ['nullable', 'string', 'max:20'],
            'pallets.*.qty_actual' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $details = $header->details()->with('product:id,uom,pack_unit,pack_size,max_qty_per_pallet')->get()->keyBy('id');
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

        $susulan = DB::transaction(function () use ($header, $perubahan, $request) {
            $activator = new StockActivator;
            $userId = $request->user()?->id;
            $produkTersentuh = [];

            foreach ($perubahan as $detailId => $nilai) {
                $header->details()->whereKey($detailId)->update($nilai + [
                    'verified_by' => $userId,
                    'verified_at' => now(),
                    'updated_at' => now(),
                ]);

                // Stok RESMI AKTIF di sini (PRD §6.3 F-INB-03 langkah 9-10).
                // Dibaca ulang dari basis data supaya memakai qty & lokasi
                // yang baru saja disimpan, bukan nilai model yang basi.
                $detail = $header->details()->with(['product:id,shelf_life_months', 'header'])->findOrFail($detailId);
                $activator->activate($detail, $userId);

                $produkTersentuh[$detail->product_id] = true;
            }

            $header->update(['status' => $header->resolveVerificationStatus()]);

            /*
             * JANJI YANG SUDAH ADA DILAYANI DI SINI — bukan menunggu ada yang
             * ingat. Inilah jalur yang paling sering dilewati barang di Berger
             * (produksi -> cek operator -> naik rak), dan dulu justru
             * satu-satunya jalur masuk stok yang TIDAK melayani janji yang
             * sudah menumpuk. Akibatnya barang mendarat dalam keadaan bebas,
             * lalu pesanan lain yang kebetulan diproses lebih dulu
             * menyambarnya lewat FIFO — sementara jatah yang sudah dijanjikan
             * berminggu-minggu sebelumnya hilang tanpa ada yang sadar.
             */
            $hasil = ['terisi' => 0, 'pesanan' => [], 'booking' => []];

            foreach (array_keys($produkTersentuh) as $productId) {
                $bagian = $this->pengisi->fill($productId, $header->warehouse_id, $userId);

                $hasil['terisi'] += $bagian['terisi'];
                $hasil['pesanan'] = array_merge($hasil['pesanan'], $bagian['pesanan']);
                $hasil['booking'] = array_merge($hasil['booking'], $bagian['booking']);
            }

            return $hasil;
        });

        $header->refresh();
        $tersisa = $header->details()->where('is_verified', false)->count();

        // DILAPORKAN, bukan dikerjakan diam-diam. Barang baru yang sebagian
        // langsung punya pemilik adalah hal pertama yang perlu diketahui
        // operator: kalau tidak, ia melihat 10 unit naik rak lalu heran
        // kenapa yang bisa dijual cuma 5.
        $catatan = $this->pengisi->ringkasan($susulan);

        /*
         * Dicatat SEKALI PER PENEKANAN, bukan per palet. Verifikasi bisa
         * dicicil, dan satu baris log per palet akan menenggelamkan seluruh
         * log hari itu oleh satu dokumen berisi ratusan palet.
         *
         * Yang berselisih disebut terpisah: di situlah angka stok final
         * diputuskan, dan itu justru bagian yang paling perlu bisa
         * ditelusuri kembali.
         */
        Activity::record(
            ActivityLog::INBOUND_VERIFY,
            sprintf(
                'Verifikasi dokumen %s — %d palet disahkan, %d belum.',
                $header->document_number,
                count($perubahan),
                $tersisa,
            ),
            $header,
            $header->warehouse_id,
            [
                'dokumen' => $header->document_number,
                'disahkan' => count($perubahan),
                'tersisa' => $tersisa,
                // Dibaca dari baris yang barusan disahkan, bukan dari
                // $perubahan — larik itu tidak memuat pallet_qty, jadi
                // membandingkannya di sana akan menghitung SEMUANYA sebagai
                // selisih tanpa ada yang menyadarinya.
                'berselisih' => InboundDetail::query()
                    ->whereIn('id', array_keys($perubahan))
                    ->berselisih()
                    ->count(),
            ],
        );

        if ($tersisa > 0) {
            return redirect()->route('wms.inbound.verify.process', $header->document_number)->with(
                'success',
                sprintf(
                    '%d palet terverifikasi. Masih ada %d palet yang belum diverifikasi — dokumen tetap di daftar verifikasi.',
                    count($perubahan),
                    $tersisa
                ).($catatan ? ' '.$catatan : '')
            );
        }

        return redirect()->route('wms.inbound.verify')->with('success', sprintf(
            'Verifikasi dokumen %s selesai: %d palet terverifikasi dan stoknya kini aktif.',
            $header->document_number,
            $header->details()->count()
        ).($catatan ? ' '.$catatan : ''));
    }
}
