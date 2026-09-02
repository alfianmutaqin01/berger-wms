<?php

namespace Tests\Feature\Wms;

use App\Models\Customer;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderAllocation;
use App\Models\SalesOrderDetail;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Penerimaan pesanan oleh Logistik — Fase 6 tahap 1.
 *
 * Yang paling dijaga di sini bukan tampilannya, melainkan tiga hal yang
 * kalau salah tidak akan langsung terlihat:
 *   1. Alokasi FIFO benar-benar mengambil batch TERTUA lebih dulu.
 *   2. Menyetujui melebihi stok TIDAK membuat qty_available negatif dan
 *      TIDAK menggagalkan transaksi — kelebihannya tercatat menunggu stok.
 *   3. Nomor SO tidak bisa dipakai dua kali.
 */
class OrderApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $gudang;

    private Location $lokasi;

    private Product $produk;

    private PaymentTerm $term;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->create(['code' => 'KRW', 'name' => 'Karawang']);
        $this->lokasi = Location::factory()->create(['warehouse_id' => $this->gudang->id, 'code' => 'A-01-01']);
        $this->produk = Product::factory()->create(['sku' => 'APKO-001', 'uom' => 'PAIL', 'is_active' => true]);
        $this->customer = Customer::factory()->create(['is_active' => true]);
        $this->term = PaymentTerm::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Cash / Tunai', 'days' => 0, 'is_active' => true, 'sort_order' => 1]
        );
    }

    private function loginAs(string $slug = Role::LOGISTICS): User
    {
        $user = User::factory()->withRole($slug)->create(['warehouse_id' => $this->gudang->id]);
        $token = Str::random(64);

        UserSession::create([
            'user_id' => $user->id,
            'session_id' => $token,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'last_activity_at' => now(),
            'created_at' => now(),
        ]);

        $this->withUnencryptedCookies(['device_token' => $token]);

        // postJson() TIDAK mengirim cookie apa pun kecuali withCredentials()
        // dipasang (lihat MakesHttpRequests::prepareCookiesForJsonRequest).
        // Tanpa ini, endpoint resolve teruji dalam keadaan tanpa device_token
        // — yang teruji jadi jalur logout paksa TrackUserSession (302), bukan
        // penerjemahan SKU-nya. Browser sungguhan mengirim cookie ke fetch()
        // se-origin, jadi inilah yang menyerupai keadaan sebenarnya.
        $this->withCredentials();

        $this->actingAs($user);

        return $user;
    }

    private function pesanan(array $atribut = []): SalesOrder
    {
        $sales = User::factory()->withRole(Role::SALES)->create(['warehouse_id' => $this->gudang->id]);

        return SalesOrder::factory()->submitted()->create(array_merge([
            'user_id' => $sales->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->gudang->id,
            'payment_term_id' => $this->term->id,
            'status' => SalesOrder::STATUS_PENDING,
        ], $atribut));
    }

    /** Membuat satu batch stok dengan tanggal produksi tertentu. */
    private function stok(int $qty, string $tanggalProduksi, ?string $batch = null): InventoryStock
    {
        return InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->gudang->id,
            'location_id' => $this->lokasi->id,
            'batch_no' => $batch ?? 'BT-'.Str::random(4),
            'production_date' => $tanggalProduksi,
            'expiry_date' => now()->addYears(2)->toDateString(),
            'qty_available' => $qty,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);
    }

    /* ------------------------------------------------------------ Antrean */

    public function test_antrean_hanya_memuat_pesanan_yang_menunggu(): void
    {
        $this->loginAs();

        $menunggu = $this->pesanan();
        $draft = $this->pesanan(['status' => SalesOrder::STATUS_DRAFT, 'submitted_at' => null]);
        $selesai = $this->pesanan(['status' => SalesOrder::STATUS_APPROVED]);

        $halaman = $this->get('/wms/outbound/approval')->assertOk();

        $halaman->assertSee($menunggu->order_number);
        $halaman->assertDontSee($draft->order_number);
        $halaman->assertDontSee($selesai->order_number);
    }

    public function test_role_operasional_tidak_boleh_membuka_penerimaan(): void
    {
        foreach ([Role::PRODUCTION, Role::WAREHOUSE_OPERATOR] as $slug) {
            $this->loginAs($slug);

            $this->get('/wms/outbound/approval')->assertForbidden();
        }
    }

    /* ---------------------------------------------------------- Usulan qty */

    /** Usulan = min(pesan, stok), sesuai F-OUT-02 langkah 3. */
    public function test_layar_mengusulkan_qty_sebesar_stok_yang_ada(): void
    {
        $this->loginAs();
        $this->stok(10, now()->subMonths(2)->toDateString());

        $order = $this->pesanan();
        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 20,
        ]);

        $this->get("/wms/outbound/approval/{$order->id}")
            ->assertOk()
            ->assertViewHas('baris', function (array $baris) {
                return $baris[0]['qty_ordered'] === 20
                    && $baris[0]['stok'] === 10
                    && $baris[0]['usul'] === 10;
            });
    }

    /**
     * Lebar kisi harus konsisten antara <thead> dan <tfoot>.
     *
     * Kolom tombol hapus hanya dipakai metode dokumen. Versi pertama tetap
     * menggambar <th> kosongnya pada metode rincian, sehingga muncul satu sel
     * menggantung di ujung kanan — terlihat seperti kolom Excel yang lupa
     * dihapus. Diuji dengan menghitung, bukan dengan mencocokkan potongan
     * markup, supaya test ini tidak ikut pecah saat gaya kolomnya diubah.
     */
    #[DataProvider('metodePesanan')]
    public function test_lebar_kisi_konsisten(string $sumber, int $kolom): void
    {
        $this->loginAs();

        $order = $this->pesanan(array_merge(
            ['order_source' => $sumber],
            $sumber === SalesOrder::SOURCE_DOCUMENT
                ? ['document_path' => 'sales-orders/x.pdf', 'document_name' => 'x.pdf']
                : []
        ));
        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 5,
        ]);

        $html = $this->get("/wms/outbound/approval/{$order->id}")->assertOk()->getContent();

        preg_match('~<thead>(.*?)</thead>~s', $html, $kepala);
        preg_match('~<tfoot>(.*?)</tfoot>~s', $html, $kaki);

        preg_match_all('~<th[ >]~', $kepala[1] ?? '', $th);
        preg_match_all('~<td~', $kaki[1] ?? '', $td);
        preg_match_all('~colspan="(\d+)"~', $kaki[1] ?? '', $span);

        $lebarKaki = count($td[0]) - count($span[1]) + array_sum($span[1]);

        $this->assertCount($kolom, $th[0], "Jumlah kolom kepala kisi untuk metode {$sumber}.");
        $this->assertSame($kolom, $lebarKaki, "Lebar baris total tidak sama dengan kepala kisi ({$sumber}).");
    }

    public static function metodePesanan(): array
    {
        return [
            // #, SKU, Deskripsi, UOM, Pesan, Stok, Setuju, Status
            'metode rincian tanpa kolom aksi' => [SalesOrder::SOURCE_MANUAL, 8],
            // ...ditambah kolom tombol hapus
            'metode dokumen dengan kolom aksi' => [SalesOrder::SOURCE_DOCUMENT, 9],
        ];
    }

    /** Kotak tempel dua kolom hanya muncul pada pesanan bermetode dokumen. */
    public function test_kotak_tempel_hanya_pada_metode_dokumen(): void
    {
        $this->loginAs();

        $rincian = $this->pesanan();
        $this->get("/wms/outbound/approval/{$rincian->id}")
            ->assertOk()
            ->assertDontSee('id="tempelSku"', false)
            ->assertDontSee('id="tempelQty"', false);

        $dokumen = $this->pesanan([
            'order_source' => SalesOrder::SOURCE_DOCUMENT,
            'document_path' => 'sales-orders/x.pdf',
            'document_name' => 'x.pdf',
        ]);
        $this->get("/wms/outbound/approval/{$dokumen->id}")
            ->assertOk()
            ->assertSee('id="tempelSku"', false)
            ->assertSee('id="tempelQty"', false)
            // Penjaga baris tidak sejajar: tanpa ini qty bisa menempel diam-diam
            // ke SKU yang salah.
            ->assertSee('id="selisihBaris"', false);
    }

    /* --------------------------------------------------------- Alokasi FIFO */

    /** Batch tertua diambil lebih dulu, lalu berlanjut ke batch berikutnya. */
    public function test_alokasi_mengambil_batch_tertua_lebih_dulu(): void
    {
        $this->loginAs();

        $muda = $this->stok(100, now()->subMonth()->toDateString(), 'BT-MUDA');
        $tua = $this->stok(30, now()->subMonths(6)->toDateString(), 'BT-TUA');

        $order = $this->pesanan();
        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 50,
        ]);

        $this->terima($order, 50)->assertSessionHas('success');

        // 30 dari batch tua (habis), sisa 20 dari batch muda.
        $this->assertSame(0, $tua->fresh()->qty_available);
        $this->assertSame(30, $tua->fresh()->qty_allocated);
        $this->assertSame(80, $muda->fresh()->qty_available);
        $this->assertSame(20, $muda->fresh()->qty_allocated);

        $this->assertSame(2, SalesOrderAllocation::count());
    }

    public function test_alokasi_menulis_entri_ledger(): void
    {
        $this->loginAs();
        $this->stok(100, now()->subMonth()->toDateString());

        $order = $this->pesanan();
        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 40,
        ]);

        $this->terima($order, 40);

        $gerak = StockMovement::where('movement_type', StockMovement::TYPE_ALLOCATED)->firstOrFail();

        // Alokasi MENGURANGI yang tersedia, jadi qty_change negatif —
        // penjumlahan ledger harus tetap setara dengan qty_available.
        $this->assertSame(-40, $gerak->qty_change);
        $this->assertSame(100, $gerak->qty_before);
        $this->assertSame(60, $gerak->qty_after);
        $this->assertSame(StockMovement::REF_SALES_ORDER, $gerak->reference_type);
        $this->assertSame($order->id, $gerak->reference_id);
    }

    /** Stok DDP dan kedaluwarsa tidak boleh ikut dijual. */
    public function test_stok_ddp_tidak_ikut_dialokasikan(): void
    {
        $this->loginAs();

        $ddp = $this->stok(100, now()->subMonths(6)->toDateString());
        $ddp->update(['status' => InventoryStock::STATUS_DDP]);
        $baik = $this->stok(15, now()->subMonth()->toDateString());

        $order = $this->pesanan();
        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 50,
        ]);

        $this->terima($order, 50)->assertSessionHas('warning');

        $this->assertSame(100, $ddp->fresh()->qty_available, 'Stok DDP tidak boleh tersentuh.');
        $this->assertSame(0, $baik->fresh()->qty_available);
    }

    /* -------------------------------------------------- Menunggu stok */

    /**
     * INTI keputusan pemilik produk: Logistik boleh menyetujui melebihi stok
     * tercatat (barang sudah di gudang tapi belum di-putaway). Kelebihannya
     * TIDAK dipaksakan jadi alokasi — kalau dipaksakan, CHECK
     * (qty_available >= 0) akan membatalkan seluruh transaksi.
     */
    public function test_menyetujui_melebihi_stok_mencatat_sisanya_sebagai_menunggu(): void
    {
        $this->loginAs();
        $stok = $this->stok(10, now()->subMonth()->toDateString());

        $order = $this->pesanan();
        $detail = SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 20,
        ]);

        $this->terima($order, 20, ['approval_note' => 'Sudah di gudang, belum putaway.'])
            ->assertSessionHas('warning');

        $detail->refresh();

        $this->assertSame(20, $detail->qty_approved, 'Janji ke customer tetap 20.');
        $this->assertSame(10, $detail->qty_allocated, 'Yang dicadangkan hanya sebanyak stok yang ada.');
        $this->assertSame(10, $detail->qty_pending_stock);

        // Yang paling penting: stok tidak pernah minus.
        $this->assertSame(0, $stok->fresh()->qty_available);
        $this->assertSame(10, $stok->fresh()->qty_allocated);
    }

    public function test_tanpa_stok_sama_sekali_pesanan_tetap_bisa_diterima(): void
    {
        $this->loginAs();

        $order = $this->pesanan();
        $detail = SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 5,
        ]);

        $this->terima($order, 5)->assertSessionHas('warning');

        $this->assertSame(SalesOrder::STATUS_APPROVED, $order->fresh()->status);
        $this->assertSame(5, $detail->fresh()->qty_pending_stock);
        $this->assertSame(0, SalesOrderAllocation::count());
    }

    /* ------------------------------------------------------- Outstanding */

    public function test_qty_yang_dikurangi_tercatat_sebagai_outstanding(): void
    {
        $this->loginAs();
        $this->stok(100, now()->subMonth()->toDateString());

        $order = $this->pesanan();
        $detail = SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 30,
        ]);

        $this->terima($order, 12)->assertSessionHas('success');

        $this->assertSame(12, $detail->fresh()->qty_approved);
        $this->assertSame(18, $detail->fresh()->outstanding_qty);
    }

    public function test_qty_disetujui_tidak_boleh_melebihi_qty_pesanan(): void
    {
        $this->loginAs();
        $this->stok(500, now()->subMonth()->toDateString());

        $order = $this->pesanan();
        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 10,
        ]);

        $this->terima($order, 11)->assertSessionHasErrors('item.0.qty_approved');

        $this->assertSame(SalesOrder::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_seluruh_item_nol_ditolak_dan_diarahkan_ke_tombol_tolak(): void
    {
        $this->loginAs();

        $order = $this->pesanan();
        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 10,
        ]);

        $this->terima($order, 0)->assertSessionHasErrors('item');

        $this->assertSame(SalesOrder::STATUS_PENDING, $order->fresh()->status);
    }

    /* ---------------------------------------------------------- Nomor SO */

    public function test_nomor_so_wajib_diisi(): void
    {
        $this->loginAs();
        $this->stok(50, now()->subMonth()->toDateString());

        $order = $this->pesanan();
        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 10,
        ]);

        $this->post("/wms/outbound/approval/{$order->id}/accept", [
            'item' => [['product_id' => $this->produk->id, 'qty_approved' => 10, 'qty_ordered' => 10]],
        ])->assertSessionHasErrors('bc_so_number');
    }

    /** Nomor SO terulang berarti pesanan ini belum benar-benar masuk BC. */
    public function test_nomor_so_yang_sudah_dipakai_ditolak(): void
    {
        $this->loginAs();
        $this->stok(100, now()->subMonth()->toDateString());

        $pertama = $this->pesanan();
        SalesOrderDetail::factory()->create([
            'sales_order_id' => $pertama->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 10,
        ]);
        $this->terima($pertama, 10, ['bc_so_number' => 'SO-KEMBAR'])->assertSessionHas('success');

        $kedua = $this->pesanan();
        SalesOrderDetail::factory()->create([
            'sales_order_id' => $kedua->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 10,
        ]);

        $this->terima($kedua, 10, ['bc_so_number' => 'SO-KEMBAR'])
            ->assertSessionHasErrors('bc_so_number');

        $this->assertSame(SalesOrder::STATUS_PENDING, $kedua->fresh()->status);
    }

    /* ------------------------------------------------- Metode dokumen */

    /** Rincian pesanan bermetode dokumen DIBUAT oleh Logistik saat menerima. */
    public function test_pesanan_dokumen_rinciannya_dibuat_saat_diterima(): void
    {
        $this->loginAs();
        $this->stok(100, now()->subMonth()->toDateString());

        $order = $this->pesanan([
            'order_source' => SalesOrder::SOURCE_DOCUMENT,
            'customer_po_number' => 'PO-CUST-99',
            'document_path' => 'sales-orders/contoh.pdf',
            'document_name' => 'contoh.pdf',
        ]);

        $this->assertSame(0, $order->details()->count());

        $this->post("/wms/outbound/approval/{$order->id}/accept", [
            'bc_so_number' => 'SO-DOK-1',
            'item' => [['product_id' => $this->produk->id, 'qty_approved' => 35, 'qty_ordered' => 35]],
        ])->assertSessionHas('success');

        $detail = $order->details()->firstOrFail();

        $this->assertSame(35, $detail->qty_ordered);
        $this->assertSame(35, $detail->qty_approved);
        $this->assertSame(35, $detail->qty_allocated);
        $this->assertSame(0, $detail->outstanding_qty);
    }

    /**
     * Logistik tidak boleh menaikkan qty pesanan milik Sales lewat form.
     * Baris yang sudah ada memakai qty_ordered dari database, bukan kiriman.
     */
    public function test_qty_pesanan_sales_tidak_bisa_dinaikkan_lewat_form(): void
    {
        $this->loginAs();
        $this->stok(500, now()->subMonth()->toDateString());

        $order = $this->pesanan();
        $detail = SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 10,
        ]);

        $this->post("/wms/outbound/approval/{$order->id}/accept", [
            'bc_so_number' => 'SO-CURANG',
            'item' => [['product_id' => $this->produk->id, 'qty_approved' => 99, 'qty_ordered' => 999]],
        ])->assertSessionHasErrors('item.0.qty_approved');

        $this->assertSame(10, $detail->fresh()->qty_ordered);
    }

    public function test_sku_kembar_dalam_satu_pesanan_ditolak(): void
    {
        $this->loginAs();
        $this->stok(100, now()->subMonth()->toDateString());

        $order = $this->pesanan(['order_source' => SalesOrder::SOURCE_DOCUMENT,
            'document_path' => 'sales-orders/x.pdf']);

        $this->post("/wms/outbound/approval/{$order->id}/accept", [
            'bc_so_number' => 'SO-GANDA',
            'item' => [
                ['product_id' => $this->produk->id, 'qty_approved' => 5, 'qty_ordered' => 5],
                ['product_id' => $this->produk->id, 'qty_approved' => 3, 'qty_ordered' => 3],
            ],
        ])->assertSessionHasErrors('item');
    }

    /* ------------------------------------------------------- Penolakan */

    public function test_menolak_pesanan_menyimpan_alasan_tanpa_nomor_so(): void
    {
        $this->loginAs();

        $order = $this->pesanan();

        $this->post("/wms/outbound/approval/{$order->id}/reject", [
            'rejection_reason' => 'Customer masih menunggak, diminta pelunasan lebih dulu.',
        ])->assertSessionHas('success');

        $order->refresh();

        $this->assertSame(SalesOrder::STATUS_REJECTED, $order->status);
        $this->assertNotNull($order->rejected_at);
        $this->assertNull($order->bc_so_number);
        $this->assertStringContainsString('menunggak', $order->rejection_reason);
    }

    public function test_alasan_penolakan_terlalu_singkat_ditolak(): void
    {
        $this->loginAs();

        $order = $this->pesanan();

        $this->post("/wms/outbound/approval/{$order->id}/reject", ['rejection_reason' => 'x'])
            ->assertSessionHasErrors('rejection_reason');

        $this->assertSame(SalesOrder::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_penolakan_tidak_mengalokasikan_stok(): void
    {
        $this->loginAs();
        $stok = $this->stok(100, now()->subMonth()->toDateString());

        $order = $this->pesanan();
        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 10,
        ]);

        $this->post("/wms/outbound/approval/{$order->id}/reject", [
            'rejection_reason' => 'Alamat kirim tidak jelas, minta dilengkapi.',
        ]);

        $this->assertSame(100, $stok->fresh()->qty_available);
        $this->assertSame(0, SalesOrderAllocation::count());
    }

    /* -------------------------------------------------- Lomba dua Logistik */

    /** Pesanan yang sudah dinilai orang lain tidak boleh dinilai dua kali. */
    public function test_pesanan_yang_sudah_dinilai_tidak_bisa_dinilai_lagi(): void
    {
        $this->loginAs();
        $stok = $this->stok(100, now()->subMonth()->toDateString());

        $order = $this->pesanan(['status' => SalesOrder::STATUS_APPROVED, 'bc_so_number' => 'SO-LAMA']);
        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 10,
        ]);

        $this->terima($order, 10, ['bc_so_number' => 'SO-BARU'])->assertSessionHas('error');

        $this->assertSame('SO-LAMA', $order->fresh()->bc_so_number);
        $this->assertSame(100, $stok->fresh()->qty_available, 'Tidak boleh ada alokasi sama sekali.');
    }

    public function test_layar_detail_menolak_pesanan_yang_sudah_dinilai(): void
    {
        $this->loginAs();

        $order = $this->pesanan(['status' => SalesOrder::STATUS_REJECTED, 'rejected_at' => now()]);

        $this->get("/wms/outbound/approval/{$order->id}")
            ->assertRedirect(route('wms.approval.index'))
            ->assertSessionHas('error');
    }

    /* ---------------------------------------------------------- Riwayat */

    public function test_riwayat_memuat_yang_diterima_dan_ditolak_saja(): void
    {
        $this->loginAs();
        $this->stok(100, now()->subMonth()->toDateString());

        $diterima = $this->pesanan();
        SalesOrderDetail::factory()->create([
            'sales_order_id' => $diterima->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 10,
        ]);
        $this->terima($diterima, 10, ['bc_so_number' => 'SO-RIWAYAT']);

        $ditolak = $this->pesanan();
        $this->post("/wms/outbound/approval/{$ditolak->id}/reject", [
            'rejection_reason' => 'Barang tidak tersedia dalam waktu dekat.',
        ]);

        $menunggu = $this->pesanan();

        $halaman = $this->get('/wms/outbound/approval/history')->assertOk();

        $halaman->assertSee($diterima->order_number);
        $halaman->assertSee($ditolak->order_number);
        $halaman->assertDontSee($menunggu->order_number);
    }

    public function test_riwayat_bisa_disaring_hanya_yang_ditolak(): void
    {
        $this->loginAs();
        $this->stok(100, now()->subMonth()->toDateString());

        $diterima = $this->pesanan();
        SalesOrderDetail::factory()->create([
            'sales_order_id' => $diterima->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 10,
        ]);
        $this->terima($diterima, 10, ['bc_so_number' => 'SO-SARING']);

        $ditolak = $this->pesanan();
        $this->post("/wms/outbound/approval/{$ditolak->id}/reject", [
            'rejection_reason' => 'Dokumen pendukung belum lengkap.',
        ]);

        $this->get('/wms/outbound/approval/history?hasil=ditolak')
            ->assertOk()
            ->assertSee($ditolak->order_number)
            ->assertDontSee($diterima->order_number);
    }

    /* ------------------------------------------------- Terjemahan SKU */

    public function test_resolve_mengembalikan_nama_dan_stok_untuk_sku_dikenal(): void
    {
        $this->loginAs();
        $this->stok(42, now()->subMonth()->toDateString());

        $order = $this->pesanan();

        $this->postJson("/wms/outbound/approval/{$order->id}/resolve", ['sku' => ['apko-001', 'TIDAK-ADA']])
            ->assertOk()
            ->assertJsonPath('produk.APKO-001.ditemukan', true)
            ->assertJsonPath('produk.APKO-001.stok', 42)
            ->assertJsonPath('produk.TIDAK-ADA.ditemukan', false);
    }

    /* -------------------------------------------------------- Lampiran */

    public function test_unduh_lampiran_pesanan_tanpa_dokumen_menjawab_404(): void
    {
        $this->loginAs();

        $order = $this->pesanan();

        $this->get("/wms/outbound/approval/{$order->id}/document")->assertNotFound();
    }

    /**
     * Mengirim form terima dengan satu baris.
     *
     * @param  array<string, mixed>  $tambahan
     */
    private function terima(SalesOrder $order, int $qtySetuju, array $tambahan = [])
    {
        $detail = $order->details()->first();

        return $this->post("/wms/outbound/approval/{$order->id}/accept", array_merge([
            'bc_so_number' => 'SO-'.Str::upper(Str::random(6)),
            'item' => [[
                'product_id' => $this->produk->id,
                'qty_approved' => $qtySetuju,
                'qty_ordered' => $detail?->qty_ordered ?? $qtySetuju,
            ]],
        ], $tambahan));
    }
}
