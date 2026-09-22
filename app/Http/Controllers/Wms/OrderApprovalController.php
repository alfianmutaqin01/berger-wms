<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\AcceptSalesOrderRequest;
use App\Http\Requests\Wms\RejectSalesOrderRequest;
use App\Models\ActivityLog;
use App\Models\CustomerBilling;
use App\Models\Notification;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\SalesOrderEmail;
use App\Models\SalesOrderOutstanding;
use App\Models\SalesOrderRejection;
use App\Support\Activity;
use App\Support\Messaging\EmailSales;
use App\Support\Notifier;
use App\Support\Outbound\FifoAllocator;
use App\Support\Outbound\OutstandingRecorder;
use App\Support\Outbound\ProductBooking;
use App\Support\Permission;
use App\Support\WarehouseScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Penerimaan pesanan oleh Logistik — Fase 6 tahap 1, PRD §6.5 F-OUT-02,
 * docs/4 §4.3.3.
 *
 * DUA BENTUK PESANAN, SATU LAYAR
 * ------------------------------
 * 1. Metode RINCIAN — Sales sudah mengisi item. Nomor PO dari sistem,
 *    rinciannya langsung tampil di kisi mirip Excel.
 * 2. Metode DOKUMEN — Sales melampirkan PO customer dan mengisi nomor PO
 *    miliknya sendiri. Kisinya KOSONG: Logistik mengunduh berkasnya,
 *    memasukkannya ke sistem BC, lalu menempelkan hasilnya (SKU dan qty) ke
 *    kisi itu. Deskripsi produk diisi sistem dari SKU, bukan dari tempelan,
 *    supaya nama versi BC yang berbeda tidak diam-diam masuk basis data.
 *
 * JANJI vs CADANGAN — perbedaan yang paling mudah tertukar
 * --------------------------------------------------------
 * `qty_approved` = yang DIJANJIKAN ke customer.
 * `sales_order_allocations` = yang BENAR-BENAR dicadangkan dari stok.
 * Keduanya boleh berbeda: Logistik berwenang menyetujui melebihi stok
 * tercatat karena barang bisa sudah ada di gudang tetapi belum di-putaway.
 * Selisihnya tidak disembunyikan — ditampilkan sebagai "menunggu stok" dan
 * belum bisa dipicking.
 *
 * DATA CONTRACT
 * -------------
 * index()   : $orders LengthAwarePaginator<SalesOrder>, $warehouses,
 *             $filters{search,warehouse}, $stats{menunggu,dokumen}
 * show()    : $order SalesOrder, $baris list<array>
 *
 * Yang terjadi sesudah keputusan ada di controller sendiri: nomor SO
 * (SoNumberController), pembatalan pesanan yang sudah diterima
 * (OrderCancellationController), dan riwayat (OrderApprovalHistoryController).
 */
class OrderApprovalController extends Controller
{
    public function __construct(
        private readonly FifoAllocator $allocator,
        private readonly OutstandingRecorder $outstanding,
        private readonly ProductBooking $booking,
    ) {}

    /** Antrean pesanan yang menunggu diterima (F-OUT-02 langkah 1). */
    public function index(Request $request): View
    {
        $user = $request->user();

        $filters = [
            'search' => $request->query('search'),
            // Bukan lagi isian bebas dari URL. Bagi Logistik yang terikat satu
            // gudang, nilainya SELALU gudangnya sendiri berapa pun yang
            // diketik di alamat — lihat App\Support\WarehouseScope.
            'warehouse' => WarehouseScope::resolveFilter($request, $user, 'warehouse'),
        ];

        $orders = SalesOrder::query()
            ->where('status', SalesOrder::STATUS_PENDING)
            ->search($filters['search'])
            ->when($filters['warehouse'], fn ($q, $w) => $q->where('warehouse_id', $w))
            ->with(['customer:id,code,name', 'user:id,full_name', 'warehouse:id,code,name'])
            // Pengajuan ULANG harus terbaca dari antrean, bukan baru ketahuan
            // setelah layar penerimaannya dibuka: pesanan yang pernah ditolak
            // menuntut perhatian yang berbeda dari pesanan yang baru pertama
            // kali masuk.
            ->withCount(['details', 'rejections'])
            // Terlama di atas: ini antrean, bukan kabar terbaru. Pesanan yang
            // sudah menunggu paling lama justru yang paling mendesak.
            ->orderBy('submitted_at')
            ->paginate(15)
            ->withQueryString();

        // Angka ringkas ikut dibatasi. Kalau tidak, Logistik Pekanbaru melihat
        // "12 menunggu" padahal antreannya hanya berisi 3 — sisanya milik
        // gudang lain dan tidak akan pernah muncul untuknya.
        $antrean = fn () => WarehouseScope::apply(
            SalesOrder::where('status', SalesOrder::STATUS_PENDING),
            $user
        );

        return view('wms.outbound.approval', [
            'orders' => $orders,
            'warehouses' => WarehouseScope::options($user),
            'filters' => $filters,
            'stats' => [
                'menunggu' => $antrean()->count(),
                'dokumen' => $antrean()->where('order_source', SalesOrder::SOURCE_DOCUMENT)->count(),
            ],
        ]);
    }

    /** Layar penerimaan satu pesanan. */
    public function show(Request $request, SalesOrder $order): View|RedirectResponse
    {
        // Dipanggil SEBELUM apa pun yang lain. Menyaring daftar tidak menutup
        // apa-apa selama URL detailnya masih bisa dibuka langsung.
        WarehouseScope::assert($order->warehouse_id, $request->user());

        if ($order->status !== SalesOrder::STATUS_PENDING) {
            return redirect()->route('wms.approval.index')->with(
                'error',
                "Pesanan {$order->order_number} sudah {$order->status_label} dan tidak bisa dinilai lagi."
            );
        }

        $order->load([
            'customer', 'user:id,full_name', 'warehouse', 'paymentTerm',
            'details.product:id,sku,name,uom',
            // Alasan penolakan sebelumnya ikut dibawa: yang menilai pengajuan
            // kedua perlu tahu apa yang dulu salah, kalau tidak koreksi Sales
            // dinilai tanpa tahu ia sedang mengoreksi apa.
            'rejections' => fn ($q) => $q->with('rejectedBy:id,full_name')->orderByDesc('attempt_no'),
        ]);

        $tersedia = $this->allocator->availableFor(
            $order->details->pluck('product_id')->all(),
            $order->warehouse_id
        );

        // Stok produk yang sama di gudang LAIN. Tidak bisa dipakai pesanan ini,
        // tetapi "stok nol" dan "stoknya ada, cuma di gudang sebelah" adalah
        // dua keadaan yang sangat berbeda — dan yang kedua sering berarti
        // barangnya salah gudang, bukan benar-benar habis.
        $diGudangLain = $this->allocator->elsewhereFor(
            $order->details->pluck('product_id')->all(),
            $order->warehouse_id
        );

        $baris = $order->details->map(function (SalesOrderDetail $detail) use ($tersedia, $diGudangLain) {
            $stok = $tersedia[$detail->product_id] ?? 0;

            return [
                'product_id' => $detail->product_id,
                'sku' => $detail->product?->sku,
                'nama' => $detail->product?->name,
                'uom' => $detail->product?->uom,
                'qty_ordered' => $detail->qty_ordered,
                'stok' => $stok,
                // Hanya ditampilkan saat gudang ini kurang — kalau stoknya
                // cukup, keberadaan barang di gudang lain tidak relevan dan
                // hanya menambah bacaan.
                'gudang_lain' => $stok < $detail->qty_ordered
                    ? ($diGudangLain[$detail->product_id] ?? [])
                    : [],
                // Usulan = min(pesan, stok), sesuai F-OUT-02 langkah 3.
                // Hanya USULAN: Logistik boleh menaikkannya sampai qty pesan.
                'usul' => min($detail->qty_ordered, $stok),
            ];
        })->values()->all();

        return view('wms.outbound.approval-detail', [
            'order' => $order,
            'baris' => $baris,
            // F-BILL-03: informasi untuk yang memutuskan, bukan pemblokir.
            'piutang' => CustomerBilling::penandaCustomer([$order->customer_id])[$order->customer_id] ?? null,
        ]);
    }

    /**
     * Menerjemahkan SKU yang ditempel Logistik menjadi baris kisi.
     *
     * Dipanggil dari layar penerimaan lewat fetch(), bukan saat submit: SKU
     * yang tidak dikenal harus ketahuan SEBELUM Logistik menekan Terima,
     * bukan sesudahnya lewat pesan validasi yang mengosongkan isian.
     */
    public function resolve(Request $request, SalesOrder $order): JsonResponse
    {
        // Titik ini mengembalikan angka stok gudang pesanan. Tanpa penjagaan
        // di sini, pesanan gudang lain jadi celah untuk membaca stoknya.
        WarehouseScope::assert($order->warehouse_id, $request->user());

        $data = $request->validate([
            'sku' => ['required', 'array', 'max:500'],
            'sku.*' => ['required', 'string', 'max:50'],
        ]);

        $diminta = array_values(array_unique(array_map(
            fn (string $sku) => strtoupper(trim($sku)),
            $data['sku']
        )));

        $produk = Product::query()
            ->whereIn(DB::raw('UPPER(sku)'), $diminta)
            ->where('is_active', true)
            ->get(['id', 'sku', 'name', 'uom'])
            ->keyBy(fn (Product $p) => strtoupper($p->sku));

        $tersedia = $this->allocator->availableFor(
            $produk->pluck('id')->all(),
            $order->warehouse_id
        );

        $hasil = [];

        foreach ($diminta as $sku) {
            $p = $produk->get($sku);

            $hasil[$sku] = $p === null
                ? ['ditemukan' => false]
                : [
                    'ditemukan' => true,
                    'product_id' => $p->id,
                    'sku' => $p->sku,
                    'nama' => $p->name,
                    'uom' => $p->uom,
                    'stok' => $tersedia[$p->id] ?? 0,
                ];
        }

        return response()->json(['produk' => $hasil]);
    }

    /** Mengunduh lampiran PO customer (pesanan bermetode dokumen). */
    public function document(Request $request, SalesOrder $order): StreamedResponse
    {
        WarehouseScope::assert($order->warehouse_id, $request->user());

        abort_if($order->document_path === null, 404, 'Pesanan ini tidak punya lampiran.');
        abort_unless(Storage::disk('local')->exists($order->document_path), 404, 'Berkas lampiran tidak ditemukan.');

        return Storage::disk('local')->download(
            $order->document_path,
            $order->document_name ?? basename($order->document_path)
        );
    }

    /**
     * Menerima pesanan: menyimpan qty final, mencadangkan stok FIFO, dan
     * mencatat nomor SO dari sistem BC.
     *
     * SELURUHNYA dalam satu transaksi dengan baris pesanan yang dikunci.
     * Angka stok yang dilihat Logistik di layar BISA SUDAH BASI saat tombol
     * ditekan — pesanan lain mungkin mengambil batch yang sama di sela itu —
     * jadi alokasinya dihitung ulang di sini, bukan dipercaya dari form.
     */
    public function accept(AcceptSalesOrderRequest $request, SalesOrder $order): RedirectResponse
    {
        WarehouseScope::assert($order->warehouse_id, $request->user());

        $userId = $request->user()?->id;

        try {
            $ringkasan = DB::transaction(function () use ($request, $order, $userId) {
                $terkunci = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);

                // Diperiksa ULANG di dalam kunci. Dua Logistik yang membuka
                // layar yang sama sama-sama lolos pemeriksaan di show().
                $this->pastikanMasihMenunggu($terkunci);

                $this->tulisRincian($terkunci, $request->itemData(), $userId);
                $terkunci->load('details');

                $dialokasikan = 0;
                $menunggu = 0;
                $dariBooking = 0;
                $nomorBooking = [];

                foreach ($terkunci->details as $detail) {
                    /*
                     * BOOKING MILIK CUSTOMER INI DIPAKAI LEBIH DULU, sebelum
                     * FIFO menyentuh stok bebas. Bukan sekadar urutan: tanpa
                     * langkah ini booking dan pesanan akan sama-sama memegang
                     * unit yang sama, dan gudang terlihat menjanjikan dua kali
                     * lipat dari barang yang benar-benar ada.
                     *
                     * Yang berpindah bukan cuma jatah yang sudah tercadang.
                     * Porsi booking yang masih MENUNGGU stok pun ditutup di
                     * sini, karena mulai sekarang pesanan inilah yang memikul
                     * janjinya — kalau tidak, satu unit yang sama akan antre
                     * dua kali begitu barang baru masuk.
                     */
                    $booking = $this->booking->consume($detail, $detail->qty_approved, $userId);

                    $dariBooking += $booking['dari_cadangan'];
                    $nomorBooking = array_merge($nomorBooking, $booking['booking']);

                    $sisa = $detail->qty_approved - $booking['dari_cadangan'];
                    $dapat = $booking['dari_cadangan'] + $this->allocator->allocate($detail, $sisa, $userId);

                    $dialokasikan += $dapat;
                    $menunggu += $detail->qty_approved - $dapat;
                }

                $terkunci->fill([
                    'status' => SalesOrder::STATUS_APPROVED,
                    'bc_so_number' => $request->validated('bc_so_number'),
                    // Terisi hanya pada pesanan tambahan yang sengaja berbagi
                    // nomor SO dengan pesanan lain (satu invoice). Indeks unik
                    // mengecualikan baris yang kolom ini terisi, sehingga
                    // hanya induknya yang memegang nomor secara eksklusif.
                    'so_merged_into_id' => $request->boolean('gabung_invoice')
                        ? (int) $request->validated('merge_with_order_id')
                        : null,
                    'approval_note' => $request->validated('approval_note'),
                    'approved_at' => now(),
                    'approved_by' => $userId,
                    // Pesanan yang pernah dibatalkan lalu diterima lagi:
                    // penanda pembatalannya dibersihkan supaya keadaan
                    // SEKARANG-nya jujur. Riwayatnya tetap utuh di tabel
                    // sales_order_cancellations.
                    'cancelled_at' => null,
                    'cancelled_by' => null,
                    'cancellation_source' => null,
                    'cancellation_reason' => null,
                ])->save();

                return [
                    'dialokasikan' => $dialokasikan,
                    'menunggu' => $menunggu,
                    'dari_booking' => $dariBooking,
                    'nomor_booking' => array_values(array_unique($nomorBooking)),
                ];
            });
        } catch (RuntimeException $e) {
            return redirect()->route('wms.approval.index')->with('error', $e->getMessage());
        }

        // PRD §7.4: menyetujui pesanan customer yang menunggak adalah keputusan
        // yang harus meninggalkan jejak beserta penyetujunya. Tidak memblokir.
        $piutang = CustomerBilling::penandaCustomer([$order->customer_id])[$order->customer_id] ?? null;
        $menunggak = ($piutang['menunggak'] ?? 0) > 0;

        Activity::record(
            ActivityLog::ORDER_APPROVE,
            sprintf(
                'Menerima pesanan %s dari %s — %d unit dicadangkan, %d menunggu stok.%s',
                $order->order_number,
                $order->customer?->name ?? 'pelanggan',
                $ringkasan['dialokasikan'],
                $ringkasan['menunggu'],
                $menunggak
                    ? sprintf(' Customer MENUNGGAK: %d invoice lewat jatuh tempo (terlama %d hari).', $piutang['menunggak'], $piutang['lewat_terlama'])
                    : '',
            ),
            $order,
            $order->warehouse_id,
            array_filter([
                'nomor_so_bc' => $order->fresh()->bc_so_number,
                'dialokasikan' => $ringkasan['dialokasikan'],
                'menunggu_stok' => $ringkasan['menunggu'],
                'dari_booking' => $ringkasan['dari_booking'],
                'customer_menunggak' => $menunggak ? $piutang : null,
            ], fn ($nilai) => $nilai !== null),
        );

        /*
         * DUA LONCENG, DUA PENERIMA, karena yang harus bergerak berikutnya
         * memang dua orang berbeda: Sales perlu tahu pesanannya lolos, dan
         * gudang perlu tahu ada yang siap dipicking. Menggabungkannya jadi
         * satu berarti salah satunya tidak pernah diberi tahu.
         */
        Notifier::toUser(
            $order->user_id,
            Notification::ORDER_APPROVED,
            'Pesanan Anda diterima',
            sprintf(
                '%s untuk %s sudah diterima Logistik dan masuk antrean gudang.',
                $order->order_number,
                $order->customer?->name ?? 'pelanggan',
            ),
            url('/sales/orders/'.$order->id),
            $order->warehouse_id,
            $order,
        );

        Notifier::toPermission(
            Permission::OUTBOUND_PICKING_LIST,
            $order->warehouse_id,
            Notification::PICKING_READY,
            'Pesanan siap dipicking',
            sprintf(
                '%s untuk %s — %d unit sudah dicadangkan.',
                $order->order_number,
                $order->customer?->name ?? 'pelanggan',
                $ringkasan['dialokasikan'],
            ),
            route('wms.picking.queue'),
            $order,
        );

        // Email ke Sales pemilik pesanan: cadangan lonceng, dengan qty yang
        // diterima per item. Porsi yang menunggu stok dibekukan sekarang —
        // sesudah ini angkanya bergerak mengikuti picking.
        EmailSales::antrekan($order->id, SalesOrderEmail::TYPE_APPROVED, data: [
            'dicadangkan' => $ringkasan['dialokasikan'],
            'menunggu_stok' => $ringkasan['menunggu'],
        ]);

        $pesan = "Pesanan {$order->order_number} diterima. {$ringkasan['dialokasikan']} unit dicadangkan dari stok.";

        // Disebut TERPISAH. Jatah yang datang dari booking bukan stok yang
        // baru saja direbut dari pasaran — ia memang sudah disisihkan untuk
        // customer ini sejak lama, dan yang menerima pesanan perlu tahu
        // booking mana yang barusan ditutup.
        if ($ringkasan['dari_booking'] > 0) {
            $pesan .= sprintf(
                ' %d unit di antaranya diambil dari booking %s.',
                $ringkasan['dari_booking'],
                implode(', ', $ringkasan['nomor_booking']),
            );
        }

        if ($ringkasan['menunggu'] > 0) {
            return redirect()->route('wms.approval.index')->with('warning', $pesan.sprintf(
                ' %d unit MENUNGGU STOK dan belum bisa dipicking — stoknya perlu ditambahkan lebih dulu.',
                $ringkasan['menunggu']
            ));
        }

        return redirect()->route('wms.approval.index')->with('success', $pesan);
    }

    /** Menolak pesanan. Nomor SO tidak diminta — pesanan ini tidak masuk BC. */
    public function reject(RejectSalesOrderRequest $request, SalesOrder $order): RedirectResponse
    {
        WarehouseScope::assert($order->warehouse_id, $request->user());

        try {
            DB::transaction(function () use ($request, $order) {
                $terkunci = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);

                $this->pastikanMasihMenunggu($terkunci);

                // Riwayatnya ditulis SEBELUM kolom pesanannya diisi, memakai
                // submitted_at yang masih menunjuk pengajuan yang sedang
                // dinilai. Pesanan ini boleh diperbaiki lalu diajukan lagi,
                // dan begitu itu terjadi kolom penolakan di pesanannya
                // dikosongkan — hanya tabel inilah yang tetap mengingatnya.
                SalesOrderRejection::create([
                    'sales_order_id' => $terkunci->id,
                    'reason' => $request->validated('rejection_reason'),
                    'attempt_no' => $terkunci->rejections()->count() + 1,
                    'submitted_at' => $terkunci->submitted_at,
                    'rejected_at' => now(),
                    'rejected_by' => $request->user()?->id,
                ]);

                $terkunci->fill([
                    'status' => SalesOrder::STATUS_REJECTED,
                    'rejection_reason' => $request->validated('rejection_reason'),
                    'rejected_at' => now(),
                    'rejected_by' => $request->user()?->id,
                ])->save();
            });
        } catch (RuntimeException $e) {
            return redirect()->route('wms.approval.index')->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::ORDER_REJECT,
            sprintf(
                'Menolak pesanan %s dari %s — %s',
                $order->order_number,
                $order->customer?->name ?? 'pelanggan',
                $request->validated('rejection_reason'),
            ),
            $order,
            $order->warehouse_id,
            ['alasan' => $request->validated('rejection_reason')],
        );

        Notifier::toUser(
            $order->user_id,
            Notification::ORDER_REJECTED,
            'Pesanan Anda ditolak',
            sprintf(
                '%s ditolak Logistik — %s Pesanan ini masih bisa diperbaiki lalu diajukan ulang.',
                $order->order_number,
                $request->validated('rejection_reason'),
            ),
            url('/sales/orders/'.$order->id),
            $order->warehouse_id,
            $order,
        );

        EmailSales::antrekan($order->id, SalesOrderEmail::TYPE_REJECTED, data: [
            'alasan' => $request->validated('rejection_reason'),
        ]);

        return redirect()->route('wms.approval.index')
            ->with('success', "Pesanan {$order->order_number} ditolak.");
    }

    /**
     * Menyimpan rincian item hasil keputusan Logistik.
     *
     * Untuk pesanan bermetode DOKUMEN baris-barisnya belum ada — inilah yang
     * membuatnya ada, dengan qty_ordered = qty yang ditempel dari BC.
     * Untuk metode RINCIAN, qty_ordered milik Sales TIDAK diubah; yang
     * disimpan hanya keputusan Logistik pada qty_approved.
     *
     * @param  list<array{product_id:int, qty_approved:int, qty_ordered:int}>  $item
     */
    private function tulisRincian(SalesOrder $order, array $item, ?int $userId): void
    {
        foreach ($item as $baris) {
            $detail = SalesOrderDetail::firstOrNew([
                'sales_order_id' => $order->id,
                'product_id' => $baris['product_id'],
            ]);

            if (! $detail->exists) {
                $detail->qty_ordered = $baris['qty_ordered'];
            }

            $detail->qty_approved = $baris['qty_approved'];
            // Outstanding (PRD §7.3) = diminta dikurangi disetujui. DISIMPAN,
            // bukan dihitung ulang saat query: angka ini harus tetap
            // mencerminkan keputusan saat penerimaan sekalipun qty_ordered
            // kelak dikoreksi.
            $detail->outstanding_qty = max(0, $detail->qty_ordered - $detail->qty_approved);
            $detail->save();

            // Riwayat outstanding (permintaan pemilik produk). Kolom di atas
            // hanya menyimpan keadaan sekarang dan akan ditimpa saat barangnya
            // berangkat; tanpa baris riwayat ini, "PO itu dulu kurang berapa"
            // tidak bisa lagi dijawab begitu kekurangannya tertutup.
            $this->outstanding->record(
                $order,
                $detail,
                $detail->outstanding_qty,
                SalesOrderOutstanding::CAUSE_APPROVAL,
                $userId,
                sprintf(
                    'Dipesan %d, disetujui %d saat penerimaan.',
                    $detail->qty_ordered,
                    $detail->qty_approved,
                ),
            );
        }

        // Baris yang dibuang Logistik dari kisi ikut hilang. Dipakai saat
        // item yang tidak jadi dikirim dihapus seluruhnya, bukan di-nol-kan.
        $dipakai = array_column($item, 'product_id');
        $order->details()->whereNotIn('product_id', $dipakai !== [] ? $dipakai : [0])->delete();
    }

    /** @throws RuntimeException bila pesanan sudah dinilai orang lain */
    private function pastikanMasihMenunggu(SalesOrder $order): void
    {
        if ($order->status !== SalesOrder::STATUS_PENDING) {
            throw new RuntimeException(
                "Pesanan {$order->order_number} sudah {$order->status_label} — ".
                'kemungkinan baru saja dinilai orang lain.'
            );
        }
    }
}
