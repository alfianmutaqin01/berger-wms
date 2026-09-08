<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SalesOrderRequest;
use App\Models\Customer;
use App\Models\DeliveryProof;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Support\DocumentNumber;
use App\Support\OrderCutoff;
use App\Support\Returns\CustomerRejection;
use App\Support\StockIndicator;
use App\Support\WarehouseScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;

/**
 * Portal Sales — pembuatan dan riwayat pesanan (PRD §6.5 F-OUT-01).
 *
 * BATAS FASE 5: controller ini hanya memegang sisi Sales — draft, submit,
 * riwayat, dan detail. Approval, alokasi FIFO, serta pencatatan Outstanding
 * (§7.3) ada di Fase 6 bersama layar Logistik yang memicunya.
 *
 * Dua aturan yang membentuk hampir seluruh isi berkas ini:
 *
 *   1. SEMI-BLIND. Sales tidak pernah menerima angka stok, hanya indikator
 *      ✅/⚠️/❌ (F-INV-03). Karena itu tidak ada satu pun angka qty stok yang
 *      dikirim ke view Portal Sales.
 *   2. DRAFT vs TERKUNCI. Draft boleh diubah dan dihapus; begitu disubmit,
 *      pesanan masuk antrean Logistik dan tidak boleh berubah lagi
 *      (F-OUT-01 #7).
 */
class SalesOrderController extends Controller
{
    private const DISK = 'local';

    private const FOLDER = 'sales-orders';

    /** Panjang minimal kata kunci sebelum pencarian dijalankan. */
    private const MIN_CARI = 2;

    /** Batas saran yang ditampilkan; cukup untuk dibaca sekali lihat di HP. */
    private const MAKS_SARAN = 20;

    /* ------------------------------------------------------- Buat pesanan */

    public function create(Request $request): View
    {
        return view('sales.new_order', $this->formData($request));
    }

    public function store(SalesOrderRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['warehouse_id'] = WarehouseScope::require($request->user());

        if ($galat = $this->galatDokumen($request, null)) {
            return back()->withInput()->withErrors(['document' => $galat]);
        }

        $order = DB::transaction(function () use ($request, $data): SalesOrder {
            $order = new SalesOrder([
                // Nomor internal SELALU dibuat, termasuk untuk pesanan
                // bermetode dokumen — nomor PO customer tidak dijamin unik
                // antar pelanggan, jadi tidak bisa jadi identitas sistem.
                'order_number' => DocumentNumber::forSalesOrder(),
                'user_id' => $request->user()->id,
                'status' => SalesOrder::STATUS_DRAFT,
            ]);

            $this->isiDari($order, $data, $request->file('document'));
            $order->save();

            $this->tulisRincian($order, $data);

            if ($request->wantsSubmit()) {
                $this->tandaiTerkirim($order);
            }

            return $order;
        });

        return redirect('/sales/my-orders')->with(
            'success',
            $request->wantsSubmit()
                ? 'Pesanan '.$order->order_number.' berhasil dikirim ke Logistik.'
                : 'Draft pesanan '.$order->order_number.' tersimpan. Masih bisa diubah sebelum dikirim.'
        );
    }

    /* -------------------------------------------------------- Ubah draft */

    public function edit(Request $request, SalesOrder $order): View
    {
        $this->pastikanMilikSendiri($request, $order);
        // Pesanan yang DITOLAK ikut boleh diubah — itulah inti perbaikan ini.
        // Yang sudah diterima Logistik tetap tidak, karena isinya sudah jadi
        // dasar keputusan dan cadangan stok.
        abort_unless($order->bolehDiperbaiki(), 403, 'Pesanan yang sudah dikirim tidak bisa diubah.');

        return view('sales.new_order', $this->formData($request, $order));
    }

    public function update(SalesOrderRequest $request, SalesOrder $order): RedirectResponse
    {
        $this->pastikanMilikSendiri($request, $order);
        // Pesanan yang DITOLAK ikut boleh diubah — itulah inti perbaikan ini.
        // Yang sudah diterima Logistik tetap tidak, karena isinya sudah jadi
        // dasar keputusan dan cadangan stok.
        abort_unless($order->bolehDiperbaiki(), 403, 'Pesanan yang sudah dikirim tidak bisa diubah.');

        $data = $request->validated();
        // Diambil ulang dari akun, bukan dipertahankan dari draft: kalau Sales
        // dipindahkan ke gudang lain, draft lamanya ikut pindah bersamanya.
        $data['warehouse_id'] = WarehouseScope::require($request->user());

        // Dibaca SEBELUM disimpan: tandaiTerkirim() akan menghapus penanda
        // penolakannya, dan sesudah itu tidak ada lagi cara membedakan
        // pengajuan ulang dari pengiriman draft biasa untuk pesan di layar.
        $pengajuanUlang = $order->sedangDitolak();

        if ($galat = $this->galatDokumen($request, $order)) {
            return back()->withInput()->withErrors(['document' => $galat]);
        }

        DB::transaction(function () use ($request, $order, $data): void {
            $this->isiDari($order, $data, $request->file('document'));
            $order->save();

            // Rincian ditulis ulang seluruhnya, bukan disamakan baris per
            // baris: form mengirim keadaan akhir yang dikehendaki Sales, dan
            // menyamakan selisihnya hanya menambah jalan untuk keliru.
            $order->details()->delete();
            $this->tulisRincian($order, $data);

            if ($request->wantsSubmit()) {
                $this->tandaiTerkirim($order);
            }
        });

        return redirect('/sales/my-orders')->with('success', match (true) {
            $request->wantsSubmit() && $pengajuanUlang => 'Pesanan '.$order->order_number
                .' diajukan ulang ke Logistik. Catatan bahwa pesanan ini pernah ditolak tetap tersimpan.',
            $request->wantsSubmit() => 'Pesanan '.$order->order_number.' berhasil dikirim ke Logistik.',
            $pengajuanUlang => 'Perbaikan pesanan '.$order->order_number
                .' tersimpan. Pesanannya belum diajukan ulang.',
            default => 'Draft pesanan '.$order->order_number.' diperbarui.',
        });
    }

    public function destroy(Request $request, SalesOrder $order): RedirectResponse
    {
        $this->pastikanMilikSendiri($request, $order);
        abort_unless($order->isEditable(), 403, 'Pesanan yang sudah dikirim tidak bisa dihapus.');

        $nomor = $order->order_number;

        DB::transaction(function () use ($order): void {
            $this->hapusDokumen($order->document_path);
            $order->details()->delete();
            $order->delete();
        });

        return redirect('/sales/my-orders')->with('success', 'Draft pesanan '.$nomor.' dihapus.');
    }

    /**
     * Mengirim draft yang sudah tersimpan, tanpa membuka formulirnya lagi.
     *
     * Cutoff diperiksa DI SINI juga, bukan hanya di SalesOrderRequest: draft
     * bisa dibuat pagi lalu dikirim lewat tombol ini pada sore hari.
     */
    public function submit(Request $request, SalesOrder $order): RedirectResponse
    {
        $this->pastikanMilikSendiri($request, $order);
        abort_unless($order->isEditable(), 403, 'Pesanan ini sudah pernah dikirim.');

        if (! OrderCutoff::isOpen()) {
            return back()->with('error', OrderCutoff::closedMessage());
        }

        if (! $order->isDocumentBased() && $order->details()->doesntExist()) {
            return back()->with('error', 'Draft ini belum punya item pesanan. Lengkapi dulu sebelum dikirim.');
        }

        $this->tandaiTerkirim($order);

        return back()->with('success', 'Pesanan '.$order->order_number.' berhasil dikirim ke Logistik.');
    }

    /* ------------------------------------------------------- Riwayat */

    public function history(Request $request): View
    {
        $filters = [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
        ];

        $orders = SalesOrder::query()
            ->ownedBy($request->user()->id)
            ->with(['customer:id,code,name', 'warehouse:id,code,name', 'paymentTerm:id,code,name'])
            ->withCount(['details', 'rejections'])
            ->search($filters['search'])
            ->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
            // Draft di atas: itu satu-satunya yang masih menunggu tindakan
            // Sales sendiri. Sisanya urut dari yang terbaru.
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [SalesOrder::STATUS_DRAFT])
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('sales.my_orders', [
            'orders' => $orders,
            'filters' => $filters,
            'statuses' => SalesOrder::STATUS_LABELS,
            'cutoffOpen' => OrderCutoff::isOpen(),
            'cutoffLabel' => OrderCutoff::label(),
        ]);
    }

    /** Detail + timeline status (docs/4 §3.3.3). */
    public function show(Request $request, SalesOrder $order): View
    {
        $this->pastikanMilikSendiri($request, $order);

        $order->load([
            'customer', 'warehouse:id,code,name', 'paymentTerm',
            'details.product:id,sku,name,uom',
            'user:id,full_name', 'approvedBy:id,full_name', 'rejectedBy:id,full_name',
            'proofs.verifiedBy:id,full_name',
            // Seluruh penolakan yang pernah terjadi, bukan hanya yang
            // terakhir: Sales perlu melihat apa saja yang sudah pernah
            // diperbaiki, terutama pada pengajuan ketiga dan seterusnya.
            'rejections' => fn ($q) => $q->with('rejectedBy:id,full_name')->orderByDesc('attempt_no'),
        ]);

        $bukti = $order->proofs->sortByDesc('uploaded_at');

        return view('sales.order_detail', [
            'order' => $order,
            'timeline' => $this->timeline($order),
            'bukti' => $bukti,
            /*
             * Sisa kuota dihitung dari foto yang MASIH BERLAKU saja. Kalau
             * yang ditolak ikut dihitung, Sales yang tiga kali salah foto
             * terkunci selamanya dan pesanannya tidak akan pernah selesai.
             */
            'sisaKuotaBukti' => DeliveryProof::MAKS_FOTO - $bukti
                ->whereIn('status', [DeliveryProof::STATUS_PENDING, DeliveryProof::STATUS_VERIFIED])
                ->count(),
            'alasanDitolak' => $bukti->contains('status', DeliveryProof::STATUS_PENDING)
                ? null
                : $bukti->firstWhere('status', DeliveryProof::STATUS_REJECTED)?->rejection_reason,
        ]);
    }

    /** Mengunduh kembali dokumen PO yang diunggah. */
    public function document(Request $request, SalesOrder $order)
    {
        $this->pastikanMilikSendiri($request, $order);
        abort_if(blank($order->document_path), 404);

        return Storage::disk(self::DISK)->download($order->document_path, $order->document_name);
    }

    /* ------------------------------------------------------- Penolakan */

    /**
     * Sales melaporkan barang yang ditolak customer, dari depan toko.
     *
     * MENEMPEL DI HALAMAN DETAIL PESANAN, bukan halaman sendiri — aturan yang
     * sama dengan unggah bukti Surat Jalan: keduanya dikerjakan bersamaan
     * dalam satu kunjungan, sambil memegang HP, sebelum truk pergi.
     */
    public function reportReturn(Request $request, CustomerRejection $penolakan): RedirectResponse
    {
        $order = SalesOrder::query()->findOrFail((int) $request->input('order_id'));

        $this->pastikanMilikSendiri($request, $order);

        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            // Alasan WAJIB dan tidak boleh sepatah kata. Logistik yang
            // menilai klaim ini tidak ikut ke toko; kalimat "ditolak" saja
            // tidak memberinya apa pun untuk dinilai.
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'qty' => ['required', 'array', 'min:1'],
            'qty.*' => ['nullable', 'integer', 'min:0'],
        ], [], ['reason' => 'alasan penolakan', 'qty' => 'jumlah yang ditolak']);

        // Baris berjumlah nol atau kosong bukan kesalahan — Sales mencentang
        // sebagian item saja dan sisanya dibiarkan kosong.
        $baris = [];

        foreach ($data['qty'] as $detailId => $qty) {
            if ((int) $qty > 0) {
                $baris[] = ['detail_id' => (int) $detailId, 'qty' => (int) $qty];
            }
        }

        try {
            $retur = $penolakan->report($order, $baris, $data['reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf(
            'Laporan penolakan %s terkirim. Logistik akan memeriksanya bersama foto Surat Jalan Anda.',
            $retur->reference,
        ));
    }

    /* -------------------------------------------------------- Pencarian */

    /**
     * Cari customer sambil mengetik.
     *
     * MINIMAL DUA HURUF. Tanpa batas ini, kolom kosong akan mengembalikan
     * seluruh pelanggan — persis daftar raksasa yang justru mau dihindari.
     */
    public function lookupCustomers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        if (mb_strlen($q) < self::MIN_CARI) {
            return response()->json([]);
        }

        $hasil = Customer::active()
            // Sales hanya menemukan pelanggan yang gudangnya memang melayani
            // wilayah itu. Penyaringan yang sama diulang saat menyimpan di
            // SalesOrderRequest — daftar ini kenyamanan, bukan pengamanan.
            ->servedBy($request->user()?->warehouse)
            ->search($q)
            ->orderBy('name')
            ->limit(self::MAKS_SARAN)
            ->get(['id', 'code', 'name'])
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
            ]);

        return response()->json($hasil);
    }

    /**
     * Cari produk sambil mengetik, lengkap dengan indikator ketersediaannya.
     *
     * Yang dikembalikan HANYA yang cocok dengan yang diketik — mengetik
     * "APKO" tidak boleh memunculkan satu pun produk non-APKO.
     *
     * Indikator ikut di sini, BUKAN angka stoknya (Semi-Blind, F-INV-03).
     * Karena ini titik satu-satunya tempat Sales melihat ketersediaan, aturan
     * itu ditegakkan di sini juga, bukan hanya di halaman formulirnya.
     */
    public function lookupProducts(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        // Gudang TIDAK lagi dibaca dari URL. Indikator ketersediaan adalah
        // angka stok yang disamarkan; membiarkan gudangnya dipilih dari
        // permintaan berarti Sales bisa mengintip keadaan gudang lain satu
        // SKU demi satu SKU hanya dengan mengganti parameter.
        $warehouseId = (int) WarehouseScope::boundary($request->user());

        if (mb_strlen($q) < self::MIN_CARI) {
            return response()->json([]);
        }

        $produk = Product::query()
            ->where('is_active', true)
            ->search($q)
            ->orderBy('sku')
            ->limit(self::MAKS_SARAN)
            ->get(['id', 'sku', 'name', 'uom', 'stock_threshold_low']);

        // Ketersediaan diambil sekali untuk seluruh hasil, bukan per produk.
        $tersedia = $warehouseId > 0
            ? StockIndicator::availabilityByWarehouse($warehouseId)
            : collect();

        return response()->json($produk->map(function (Product $p) use ($tersedia, $warehouseId) {
            $kode = $warehouseId > 0
                ? StockIndicator::for($p, $tersedia->get($p->id, 0))
                : null;

            return [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'uom' => $p->uom,
                'indicator' => $kode,
                'label' => $kode ? StockIndicator::label($kode) : null,
                'badge' => $kode ? StockIndicator::badge($kode) : null,
            ];
        }));
    }

    /* ------------------------------------------------------- Pembantu */

    /**
     * Pesanan hanya boleh dilihat dan diubah oleh Sales yang membuatnya.
     *
     * 404, bukan 403: menjawab "terlarang" pada nomor pesanan orang lain
     * berarti memberi tahu bahwa nomor itu ada.
     */
    private function pastikanMilikSendiri(Request $request, SalesOrder $order): void
    {
        abort_unless($order->user_id === $request->user()->id, 404);
    }

    /**
     * Data yang dibutuhkan form Buat Pesanan, baik untuk baru maupun ubah.
     *
     * DAFTAR PRODUK DAN CUSTOMER TIDAK DIKIRIM KE SINI. Keduanya berjumlah
     * ribuan; menaruhnya di halaman berarti Sales di lapangan mengunduh
     * berkas raksasa lewat kuota, lalu menggulir ribuan baris di layar HP
     * untuk mencari satu SKU. Keduanya dicari lewat lookupProducts() dan
     * lookupCustomers() sambil mengetik. Yang dikirim di sini hanya nilai
     * yang SUDAH terpilih, supaya labelnya tampil saat halaman dibuka.
     */
    private function formData(Request $request, ?SalesOrder $order = null): array
    {
        return [
            'order' => $order,
            // Bukan daftar pilihan lagi, melainkan keterangan: gudang Sales
            // sudah ditentukan akunnya. Tetap dikirim supaya formulir bisa
            // menampilkan gudang mana yang akan memproses pesanannya —
            // menyembunyikannya sama sekali membuat Sales tidak tahu ke mana
            // pesanannya pergi.
            'gudangSales' => $request->user()?->warehouse,
            'paymentTerms' => PaymentTerm::where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name', 'days']),
            'customerTerpilih' => $this->customerTerpilih($order),
            'produkTerpilih' => $this->produkTerpilih($order),
            'indikatorLabel' => StockIndicator::LABELS,
            'indikatorBadge' => StockIndicator::BADGES,
            'cutoffOpen' => OrderCutoff::isOpen(),
            'cutoffLabel' => OrderCutoff::label(),
            'dokumenConfig' => config('wms.order_document'),
        ];
    }

    /**
     * Customer yang sudah terpilih, untuk mengisi label kolom pencarian.
     *
     * Tanpa ini, membuka draft atau kembali dari validasi yang gagal akan
     * memperlihatkan kolom customer yang KOSONG padahal id-nya tersimpan —
     * Sales akan mengira pilihannya hilang lalu memilih ulang.
     */
    private function customerTerpilih(?SalesOrder $order): ?array
    {
        $id = old('customer_id', $order?->customer_id);

        if (blank($id)) {
            return null;
        }

        $customer = Customer::find($id, ['id', 'code', 'name']);

        return $customer ? [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
        ] : null;
    }

    /**
     * Produk pada baris item yang sudah terisi, dengan alasan yang sama.
     *
     * @return array<int, array{id:int, sku:string, name:string, uom:string}>
     */
    private function produkTerpilih(?SalesOrder $order): array
    {
        $items = old('items', $order
            ? $order->details->map(fn ($d) => ['product_id' => $d->product_id, 'qty' => $d->qty_ordered])->values()->all()
            : []);

        $ids = collect($items)->pluck('product_id')->filter()->unique();

        if ($ids->isEmpty()) {
            return [];
        }

        return Product::whereIn('id', $ids)
            ->get(['id', 'sku', 'name', 'uom'])
            ->mapWithKeys(fn (Product $p) => [$p->id => [
                'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'uom' => $p->uom,
            ]])
            ->all();
    }

    /** Menyalin isian form ke model, termasuk mengganti berkas bila ada. */
    private function isiDari(SalesOrder $order, array $data, ?UploadedFile $berkas): void
    {
        $order->fill([
            'customer_id' => $data['customer_id'],
            'warehouse_id' => $data['warehouse_id'],
            'payment_term_id' => $data['payment_term_id'],
            'order_source' => $data['order_source'],
            'notes' => $data['notes'] ?? null,
            'customer_po_number' => $data['order_source'] === SalesOrder::SOURCE_DOCUMENT
                ? $data['customer_po_number']
                : null,
        ]);

        if ($berkas !== null) {
            // Berkas lama dihapus SETELAH yang baru tersimpan, supaya
            // kegagalan penyimpanan tidak meninggalkan pesanan tanpa dokumen.
            $lama = $order->document_path;

            $order->fill([
                'document_path' => $berkas->store(self::FOLDER, self::DISK),
                'document_name' => $berkas->getClientOriginalName(),
                'document_size' => $berkas->getSize(),
                'document_mime' => $berkas->getMimeType(),
            ]);

            $this->hapusDokumen($lama);
        }

        // Berpindah ke metode rincian: dokumen lamanya tidak lagi berarti.
        if ($data['order_source'] === SalesOrder::SOURCE_MANUAL && filled($order->document_path)) {
            $this->hapusDokumen($order->document_path);
            $order->fill([
                'document_path' => null, 'document_name' => null,
                'document_size' => null, 'document_mime' => null,
            ]);
        }
    }

    /**
     * Menulis baris item.
     *
     * Pesanan bermetode dokumen sengaja TIDAK punya baris item di Fase 5 —
     * rinciannya diisi Logistik saat approval sambil membaca dokumennya.
     */
    private function tulisRincian(SalesOrder $order, array $data): void
    {
        if ($data['order_source'] === SalesOrder::SOURCE_DOCUMENT) {
            return;
        }

        foreach ($data['items'] ?? [] as $item) {
            $order->details()->create([
                'product_id' => $item['product_id'],
                'qty_ordered' => $item['qty'],
            ]);
        }
    }

    /** Submit: status berpindah dan SLA (§7.6) mulai dihitung dari sini. */
    private function tandaiTerkirim(SalesOrder $order): void
    {
        $order->forceFill([
            'status' => SalesOrder::STATUS_PENDING,
            'submitted_at' => now(),
            // Pesanan yang pernah ditolak lalu diperbaiki: penanda
            // penolakannya dibersihkan supaya keadaan SEKARANG-nya jujur —
            // ia sedang menunggu dinilai, bukan sedang ditolak. Riwayatnya
            // tetap utuh di sales_order_rejections, dan dari sanalah catatan
            // "pernah ditolak" tetap terbaca sampai akhir.
            'rejected_at' => null,
            'rejected_by' => null,
            'rejection_reason' => null,
        ])->save();
    }

    /**
     * Memeriksa keberadaan dokumen pada metode dokumen.
     *
     * Tidak bisa ditaruh di FormRequest karena aturannya bergantung pada
     * pesanan yang sedang disunting: saat mengubah draft, berkas yang sudah
     * tersimpan tetap sah walau tidak diunggah ulang.
     */
    private function galatDokumen(SalesOrderRequest $request, ?SalesOrder $order): ?string
    {
        if ($request->input('order_source') !== SalesOrder::SOURCE_DOCUMENT) {
            return null;
        }

        if ($request->hasFile('document') || filled($order?->document_path)) {
            return null;
        }

        return 'Unggah dokumen PO customer — pesanan bermetode dokumen tidak bisa diproses Logistik tanpa berkasnya.';
    }

    private function hapusDokumen(?string $path): void
    {
        if (filled($path) && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * Tahapan pesanan untuk stepper di halaman detail (docs/4 §3.3.3).
     *
     * BENTUKNYA STEPPER MENDATAR, bukan daftar menurun. Portal Sales dipakai
     * dari HP (docs/4 §3 "Mobile-First"): tahap yang ditumpuk ke bawah
     * mendorong item pesanan keluar layar, sehingga Sales harus menggulir
     * hanya untuk tahu pesanannya sampai mana. Bulatan berjajar muat dalam
     * satu pandangan.
     *
     * Judul sengaja SATU KATA — di lebar 360px, label dua kata membuat
     * kolomnya pecah dan bulatannya tidak lagi sejajar.
     *
     * TAHAP "DRAFT" TIDAK IKUT (keputusan pemilik produk). Draft belum jadi
     * pesanan; memasukkannya berarti satu tahap yang tidak pernah berarti
     * apa pun bagi pelanggan memakan seperenam lebar layar. Perjalanan
     * dimulai sejak pesanannya benar-benar dibuat.
     *
     * Tahap milik Fase 6 ke atas tetap ditampilkan sebagai "belum", supaya
     * Sales bisa menjawab "sudah sampai mana" tanpa menebak.
     *
     * @return array<int, array{judul:string, ikon:string, waktu:?Carbon, oleh:?string, selesai:bool, gagal:bool, menunggu:bool}>
     */
    private function timeline(SalesOrder $order): array
    {
        $ditolak = $order->rejected_at !== null;

        $tahap = [
            [
                'judul' => 'Dibuat',
                'ikon' => 'bi-file-earmark-text',
                // submitted_at, BUKAN created_at. Menurut pemilik produk,
                // pesanan baru "dibuat" saat DIKIRIM ke Logistik — draft
                // yang tersimpan di laci Sales belum jadi pesanan. Memakai
                // created_at akan menandai draft sebagai sudah berjalan
                // padahal Logistik belum pernah melihatnya.
                'waktu' => $order->submitted_at,
                'oleh' => $order->user?->full_name,
                'selesai' => $order->submitted_at !== null,
                'gagal' => false,
            ],
            [
                'judul' => $ditolak ? 'Ditolak' : 'Diterima',
                'ikon' => $ditolak ? 'bi-x-lg' : 'bi-check2-circle',
                'waktu' => $order->rejected_at ?? $order->approved_at,
                'oleh' => ($order->rejectedBy ?? $order->approvedBy)?->full_name,
                'selesai' => $order->approved_at !== null || $ditolak,
                'gagal' => $ditolak,
            ],
            [
                'judul' => 'Dikemas',
                'ikon' => 'bi-box-seam',
                'waktu' => $order->picking_completed_at,
                'oleh' => null,
                'selesai' => $order->picking_completed_at !== null,
                'gagal' => false,
            ],
            [
                'judul' => 'Dikirim',
                'ikon' => 'bi-truck',
                'waktu' => $order->shipped_at,
                'oleh' => null,
                'selesai' => $order->shipped_at !== null,
                'gagal' => false,
            ],
            [
                'judul' => 'Tiba',
                'ikon' => 'bi-geo-alt',
                'waktu' => $order->delivered_at,
                'oleh' => null,
                'selesai' => $order->delivered_at !== null,
                'gagal' => false,
            ],
            [
                'judul' => 'Selesai',
                'ikon' => 'bi-patch-check',
                'waktu' => $order->completed_at,
                'oleh' => null,
                'selesai' => $order->completed_at !== null,
                'gagal' => false,
            ],
        ];

        return $this->tandaiYangDitunggu($tahap, $order);
    }

    /**
     * Menandai satu tahap yang sedang ditunggu — tahap belum selesai yang
     * pertama. Dibedakan dari tahap yang belum tersentuh supaya Sales tahu
     * bola sedang ada di siapa.
     *
     * Dihitung DI SINI, bukan di view, karena aturannya butuh tahu keadaan
     * pesanannya: draft belum masuk antrean siapa pun, dan pesanan yang
     * ditolak berhenti di situ — pada keduanya TIDAK ADA yang sedang
     * ditunggu, dan menandainya "Menunggu" adalah janji palsu.
     */
    private function tandaiYangDitunggu(array $tahap, SalesOrder $order): array
    {
        $berhenti = $order->status === SalesOrder::STATUS_DRAFT
            || $order->rejected_at !== null;

        $indeksDitunggu = null;

        if (! $berhenti) {
            foreach ($tahap as $i => $isi) {
                if (! $isi['selesai']) {
                    $indeksDitunggu = $i;
                    break;
                }
            }
        }

        foreach ($tahap as $i => $isi) {
            $tahap[$i]['menunggu'] = $i === $indeksDitunggu;
        }

        return $tahap;
    }
}
