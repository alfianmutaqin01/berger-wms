<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\AcceptSalesOrderRequest;
use App\Http\Requests\Wms\RejectSalesOrderRequest;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderCancellation;
use App\Models\SalesOrderDetail;
use App\Support\Outbound\FifoAllocator;
use App\Support\Outbound\OrderCanceller;
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
 * history() : $orders LengthAwarePaginator<SalesOrder>, $filters{search,hasil}
 */
class OrderApprovalController extends Controller
{
    public function __construct(
        private readonly FifoAllocator $allocator,
        private readonly OrderCanceller $canceller,
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
            ->withCount('details')
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
        ]);

        $tersedia = $this->allocator->availableFor(
            $order->details->pluck('product_id')->all(),
            $order->warehouse_id
        );

        $baris = $order->details->map(function (SalesOrderDetail $detail) use ($tersedia) {
            $stok = $tersedia[$detail->product_id] ?? 0;

            return [
                'product_id' => $detail->product_id,
                'sku' => $detail->product?->sku,
                'nama' => $detail->product?->name,
                'uom' => $detail->product?->uom,
                'qty_ordered' => $detail->qty_ordered,
                'stok' => $stok,
                // Usulan = min(pesan, stok), sesuai F-OUT-02 langkah 3.
                // Hanya USULAN: Logistik boleh menaikkannya sampai qty pesan.
                'usul' => min($detail->qty_ordered, $stok),
            ];
        })->values()->all();

        return view('wms.outbound.approval-detail', [
            'order' => $order,
            'baris' => $baris,
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

                $this->tulisRincian($terkunci, $request->itemData());
                $terkunci->load('details');

                $dialokasikan = 0;
                $menunggu = 0;

                foreach ($terkunci->details as $detail) {
                    $dapat = $this->allocator->allocate($detail, $detail->qty_approved, $userId);
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

                return ['dialokasikan' => $dialokasikan, 'menunggu' => $menunggu];
            });
        } catch (RuntimeException $e) {
            return redirect()->route('wms.approval.index')->with('error', $e->getMessage());
        }

        $pesan = "Pesanan {$order->order_number} diterima. {$ringkasan['dialokasikan']} unit dicadangkan dari stok.";

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

        return redirect()->route('wms.approval.index')
            ->with('success', "Pesanan {$order->order_number} ditolak.");
    }

    /**
     * Memeriksa nomor SO sambil diketik, sebelum tombol Terima ditekan.
     *
     * Tanpa ini, satu-satunya cara Logistik tahu nomornya bentrok adalah
     * menekan Terima lalu ditolak — dan pada pesanan bermetode dokumen itu
     * berarti seluruh tempelan dari BC harus diulang. Jawabannya membedakan
     * tiga keadaan, karena tindak lanjutnya berbeda:
     *
     *   bebas          -> lanjut seperti biasa
     *   dapat_digabung -> pelanggan sama, tawarkan penggabungan invoice
     *   terpakai       -> pelanggan lain, tidak ada jalan selain memeriksa BC
     *
     * Sumber kebenarannya SATU dengan validasi (AcceptSalesOrderRequest::
     * pemegangNomorSo), supaya layar dan server tidak pernah berbeda jawaban.
     */
    public function checkSoNumber(Request $request, SalesOrder $order): JsonResponse
    {
        WarehouseScope::assert($order->warehouse_id, $request->user());

        $data = $request->validate(['bc_so_number' => ['required', 'string', 'max:50']]);

        $pemegang = AcceptSalesOrderRequest::pemegangNomorSo(
            $data['bc_so_number'],
            $request->user(),
            $order->id,
        );

        if ($pemegang === null) {
            return response()->json(['status' => 'bebas']);
        }

        $sama = $pemegang->customer_id === $order->customer_id;

        return response()->json([
            'status' => $sama ? 'dapat_digabung' : 'terpakai',
            'pesanan' => [
                'id' => $pemegang->id,
                'nomor' => $pemegang->order_number,
                'customer' => $pemegang->customer?->name,
                'diterima' => $pemegang->approved_at?->format('d M Y H:i'),
            ],
        ]);
    }

    /**
     * Membatalkan pesanan yang SUDAH diterima — temuan lapangan.
     *
     * Customer bisa membatalkan setelah pesanan diterima, atau BC ternyata
     * tidak menyetujuinya. Di BC nomor SO yang gagal dipakai ulang untuk
     * pesanan berikutnya; tanpa jalan ini, nomor itu terkunci selamanya di
     * WMS dan pesanan berikutnya ditolak dengan alasan yang keliru.
     *
     * Seluruh aturannya ada di App\Support\Outbound\OrderCanceller.
     */
    public function cancel(Request $request, SalesOrder $order): RedirectResponse
    {
        WarehouseScope::assert($order->warehouse_id, $request->user());

        $data = $request->validate([
            'cancellation_source' => ['required', 'in:'.implode(',', array_keys(SalesOrderCancellation::SOURCE_LABELS))],
            'cancellation_reason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [], [
            'cancellation_source' => 'sumber pembatalan',
            'cancellation_reason' => 'alasan pembatalan',
        ]);

        try {
            $hasil = $this->canceller->cancel(
                $order,
                $data['cancellation_source'],
                $data['cancellation_reason'],
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('wms.approval.history')->with('warning', sprintf(
            'Pesanan %s dibatalkan. %d unit dikembalikan ke stok%s, dan pesanannya kembali ke antrean — '.
            'terima lagi bila sudah diperbaiki, atau tolak bila memang final.',
            $order->order_number,
            $hasil['qty_dilepas'],
            $hasil['nomor_so'] ? ", nomor SO {$hasil['nomor_so']} kembali bisa dipakai" : '',
        ));
    }

    /** Riwayat penerimaan, penolakan, dan pembatalan (permintaan pemilik produk). */
    public function history(Request $request): View
    {
        $filters = [
            'search' => $request->query('search'),
            'hasil' => $request->query('hasil'),
        ];

        $orders = WarehouseScope::apply(SalesOrder::query(), $request->user())
            // Dibungkus where() sendiri: tanpa itu orWhere di dalamnya akan
            // membatalkan filter pencarian dan filter hasil di sebelahnya,
            // sehingga riwayat memunculkan pesanan yang tidak dicari.
            ->where(fn ($q) => $q->whereNotNull('approved_at')
                ->orWhereNotNull('rejected_at')
                ->orWhereNotNull('cancelled_at'))
            ->search($filters['search'])
            // "diterima" TIDAK mencakup yang sudah dibatalkan: pesanan yang
            // dibatalkan memang pernah diterima, tetapi hasil akhirnya bukan
            // itu lagi, dan menghitungnya sebagai diterima membuat rekap
            // penerimaan lebih besar daripada yang benar-benar berjalan.
            ->when($filters['hasil'] === 'diterima', fn ($q) => $q->whereNotNull('approved_at')->whereNull('cancelled_at'))
            ->when($filters['hasil'] === 'ditolak', fn ($q) => $q->whereNotNull('rejected_at'))
            ->when($filters['hasil'] === 'dibatalkan', fn ($q) => $q->whereNotNull('cancelled_at'))
            ->with(['customer:id,code,name', 'warehouse:id,code,name',
                'approvedBy:id,full_name', 'rejectedBy:id,full_name', 'cancelledBy:id,full_name'])
            ->withCount('details')
            ->orderByDesc(DB::raw("GREATEST(COALESCE(approved_at, 'epoch'), COALESCE(rejected_at, 'epoch'), COALESCE(cancelled_at, 'epoch'))"))
            ->paginate(15)
            ->withQueryString();

        return view('wms.outbound.approval-history', [
            'orders' => $orders,
            'filters' => $filters,
        ]);
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
    private function tulisRincian(SalesOrder $order, array $item): void
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
