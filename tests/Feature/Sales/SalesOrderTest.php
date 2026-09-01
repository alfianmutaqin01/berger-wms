<?php

namespace Tests\Feature\Sales;

use App\Models\Customer;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\OrderCutoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Portal Sales — Buat Pesanan & Riwayat (PRD §6.5 F-OUT-01, §7.5).
 *
 * Aturan yang paling dijaga di sini:
 *   1. SEMI-BLIND — angka stok TIDAK PERNAH sampai ke layar Sales (F-INV-03).
 *   2. Cutoff 15:00 mengunci Submit saja; Simpan Draft tetap hidup (§7.5).
 *   3. Draft boleh diubah/dihapus; pesanan terkirim TERKUNCI (F-OUT-01 #7).
 *   4. Sales hanya bisa menyentuh pesanannya sendiri.
 *   5. Nomor PO unik dan urutannya reset tiap bulan.
 */
class SalesOrderTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Customer $customer;

    private PaymentTerm $term;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->warehouse = Warehouse::factory()->create(['code' => 'WH-01']);
        $this->customer = Customer::factory()->create(['is_active' => true]);
        $this->term = PaymentTerm::create([
            'code' => 'tempo_30', 'name' => 'Tempo 30 Hari',
            'days' => 30, 'is_active' => true, 'sort_order' => 3,
        ]);

        // Pukul 09:00 WIB — sebelum cutoff, supaya test yang tidak sedang
        // menguji cutoff tidak diam-diam gagal saat dijalankan sore hari.
        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00', OrderCutoff::timezone()));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* -------------------------------------------------------- Pembantu */

    private function loginAs(string $slug = Role::SALES): User
    {
        $user = User::factory()->withRole($slug)->create(['warehouse_id' => $this->warehouse->id]);
        $token = Str::random(64);

        // Middleware session.track menuntut device_token yang cocok; tanpa
        // cookie ini setiap permintaan dilempar balik ke /login.
        UserSession::create([
            'user_id' => $user->id,
            'session_id' => $token,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'last_activity_at' => now(),
            'created_at' => now(),
        ]);

        $this->withUnencryptedCookies(['device_token' => $token]);

        // getJson() TIDAK mengirim cookie apa pun kecuali withCredentials()
        // dipasang (lihat MakesHttpRequests::prepareCookiesForJsonRequest).
        // Tanpa ini, endpoint pencarian diuji dalam keadaan tanpa
        // device_token — yang teruji jadi jalur logout paksa, bukan
        // pencariannya. Browser sungguhan mengirim cookie ke fetch()
        // se-origin, jadi inilah yang menyerupai keadaan sebenarnya.
        $this->withCredentials();
        $this->actingAs($user);

        return $user;
    }

    private function produk(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'uom' => 'TIN', 'is_active' => true, 'stock_threshold_low' => 50,
        ], $overrides));
    }

    /** Menaruh Good Stock nyata di gudang, supaya indikator punya dasar. */
    private function stok(Product $product, int $qty): InventoryStock
    {
        return InventoryStock::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'location_id' => Location::factory()->create(['warehouse_id' => $this->warehouse->id])->id,
            'qty_available' => $qty,
            'status' => InventoryStock::STATUS_ACTIVE,
            'expiry_date' => now()->addYears(2)->toDateString(),
        ]);
    }

    private function isian(array $overrides = []): array
    {
        return array_merge([
            'action' => 'submit',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'payment_term_id' => $this->term->id,
            'order_source' => SalesOrder::SOURCE_MANUAL,
            'items' => [['product_id' => $this->produk()->id, 'qty' => 100]],
        ], $overrides);
    }

    /* ---------------------------------------------------------- Akses */

    public function test_hanya_portal_sales_yang_bisa_membuka_form(): void
    {
        // PRD §5.2: Portal Sales tertutup bagi SELURUH role lain, Super Admin
        // sekalipun. EnsurePortalAccess menjawab 403, bukan mengalihkan.
        foreach ([Role::LOGISTICS, Role::WAREHOUSE_OPERATOR, Role::PRODUCTION, Role::SUPER_ADMIN] as $slug) {
            $this->loginAs($slug);
            $this->get('/sales/new-order')->assertForbidden();
        }

        $this->loginAs();
        $this->get('/sales/new-order')->assertOk();
    }

    /** Pesanan Sales lain tidak boleh terlihat maupun tersentuh. */
    public function test_pesanan_sales_lain_tidak_bisa_diakses(): void
    {
        $lain = $this->loginAs();
        $order = SalesOrder::factory()->create(['user_id' => $lain->id]);

        $this->loginAs();

        // 404, bukan 403: menjawab "terlarang" sama saja memberi tahu bahwa
        // nomor pesanan itu ada.
        $this->get('/sales/orders/'.$order->id)->assertNotFound();
        $this->get('/sales/orders/'.$order->id.'/edit')->assertNotFound();
        $this->delete('/sales/orders/'.$order->id)->assertNotFound();
        $this->post('/sales/orders/'.$order->id.'/submit')->assertNotFound();
    }

    public function test_riwayat_hanya_memuat_pesanan_sendiri(): void
    {
        $lain = $this->loginAs();
        SalesOrder::factory()->create(['user_id' => $lain->id]);

        $saya = $this->loginAs();
        SalesOrder::factory()->count(2)->create(['user_id' => $saya->id]);

        $this->assertCount(2, $this->get('/sales/my-orders')->viewData('orders'));
    }

    /* ------------------------------------------------------- Semi-blind */

    /**
     * Angka stok TIDAK BOLEH sampai ke layar Sales (F-INV-03).
     *
     * Yang dikirim hanya kode indikatornya. Apa pun yang masuk HTML bisa
     * dibaca lewat inspect, jadi menyembunyikannya dengan CSS tidak cukup.
     */
    public function test_form_tidak_pernah_mengirim_angka_stok(): void
    {
        $this->loginAs();
        $produk = $this->produk();
        $this->stok($produk, 137);

        $this->get('/sales/new-order')->assertOk()->assertDontSee('137');

        // Endpoint pencarian adalah SATU-SATUNYA tempat Sales melihat
        // ketersediaan, jadi aturan Semi-Blind ditegakkan di sana juga.
        $hasil = $this->getJson('/sales/lookup/products?q='.$produk->sku.'&warehouse_id='.$this->warehouse->id);

        $hasil->assertOk()->assertDontSee('137');
        $this->assertSame('available', $hasil->json('0.indicator'));
    }

    /* -------------------------------------------------------- Pencarian */

    /**
     * Halaman TIDAK memuat daftar produk maupun customer.
     *
     * Keduanya berjumlah ribuan. Menaruhnya di halaman berarti Sales di
     * lapangan mengunduh berkas raksasa lewat kuota lalu menggulirnya di
     * layar HP — persis keluhan yang membuat bentuk ini diubah.
     */
    public function test_daftar_penuh_tidak_ikut_dikirim_ke_halaman(): void
    {
        $this->loginAs();
        $this->produk(['sku' => 'SKU-TIDAK-BOLEH-MUNCUL', 'name' => 'Produk Tak Terkait']);
        Customer::factory()->create(['name' => 'Customer Tak Terkait', 'is_active' => true]);

        $this->get('/sales/new-order')
            ->assertOk()
            ->assertDontSee('SKU-TIDAK-BOLEH-MUNCUL')
            ->assertDontSee('Produk Tak Terkait')
            ->assertDontSee('Customer Tak Terkait');
    }

    /**
     * Mengetik "APKO" tidak boleh memunculkan satu pun produk non-APKO.
     *
     * Ini permintaan langsung pemilik produk: hasil pencarian harus
     * menyesuaikan yang diketik, bukan sekadar dipotong jumlahnya.
     */
    public function test_pencarian_produk_hanya_mengembalikan_yang_cocok(): void
    {
        $this->loginAs();
        $this->produk(['sku' => 'APKO-001', 'name' => 'Apko Wall Sealer 5Kg']);
        $this->produk(['sku' => 'APKO-002', 'name' => 'Apko Emulsion 25Kg']);
        $this->produk(['sku' => 'BRG-999', 'name' => 'Cat Kayu Coklat']);

        $sku = collect($this->getJson('/sales/lookup/products?q=APKO&warehouse_id='.$this->warehouse->id)
            ->assertOk()
            ->json())->pluck('sku');

        // Diperiksa dari isinya, bukan jumlahnya — produk lain di basis data
        // dibuat factory dengan nama acak yang bisa saja ikut cocok.
        $this->assertContains('APKO-001', $sku->all());
        $this->assertContains('APKO-002', $sku->all());
        $this->assertNotContains('BRG-999', $sku->all());
    }

    public function test_pencarian_customer_hanya_mengembalikan_yang_cocok(): void
    {
        $this->loginAs();
        Customer::factory()->create(['name' => 'Toko Jaya Makmur', 'is_active' => true]);
        Customer::factory()->create(['name' => 'Toko Sinar Abadi', 'is_active' => true]);

        $nama = collect($this->getJson('/sales/lookup/customers?q=Jaya')->assertOk()->json())
            ->pluck('name');

        // Diperiksa dari ISI hasilnya, bukan jumlahnya: setUp dan factory lain
        // membuat customer bernama acak dari faker, yang sewaktu-waktu bisa
        // mengandung kata kunci ini juga dan menggagalkan test tanpa ada yang
        // rusak. Yang diuji adalah aturannya — yang tidak cocok tidak muncul.
        $this->assertContains('Toko Jaya Makmur', $nama->all());
        $this->assertNotContains('Toko Sinar Abadi', $nama->all());
    }

    /**
     * Kata kunci di bawah 2 huruf mengembalikan daftar KOSONG.
     *
     * Tanpa batas ini, kolom yang baru disentuh akan menumpahkan seluruh isi
     * tabel — persis daftar raksasa yang justru mau dihindari.
     */
    public function test_pencarian_menolak_kata_kunci_terlalu_pendek(): void
    {
        $this->loginAs();
        $this->produk(['sku' => 'APKO-001']);
        Customer::factory()->create(['name' => 'Toko Jaya', 'is_active' => true]);

        $this->getJson('/sales/lookup/products?q=A&warehouse_id='.$this->warehouse->id)
            ->assertOk()->assertExactJson([]);
        $this->getJson('/sales/lookup/products?q=')->assertOk()->assertExactJson([]);
        $this->getJson('/sales/lookup/customers?q=T')->assertOk()->assertExactJson([]);
    }

    /** Customer nonaktif tidak boleh bisa dipesankan. */
    public function test_pencarian_customer_melewatkan_yang_nonaktif(): void
    {
        $this->loginAs();
        Customer::factory()->create(['name' => 'Toko Nonaktif', 'is_active' => false]);

        $this->getJson('/sales/lookup/customers?q=Nonaktif')->assertOk()->assertExactJson([]);
    }

    /** Endpoint pencarian ikut tertutup bagi role non-Sales. */
    public function test_pencarian_tertutup_bagi_role_lain(): void
    {
        $this->loginAs(Role::LOGISTICS);

        $this->getJson('/sales/lookup/products?q=APKO')->assertForbidden();
        $this->getJson('/sales/lookup/customers?q=Toko')->assertForbidden();
    }

    /* ------------------------------------------------------- Semi-blind */

    /** Indikator satu produk, lewat endpoint pencarian yang dipakai form. */
    private function indikatorUntuk(Product $produk): ?string
    {
        $hasil = $this->getJson('/sales/lookup/products?q='.$produk->sku.'&warehouse_id='.$this->warehouse->id)
            ->assertOk()
            ->json();

        return collect($hasil)->firstWhere('id', $produk->id)['indicator'] ?? null;
    }

    public function test_indikator_mengikuti_ambang_per_produk(): void
    {
        $this->loginAs();

        $banyak = $this->produk(['sku' => 'APKO-BANYAK', 'stock_threshold_low' => 50]);
        $sedikit = $this->produk(['sku' => 'APKO-SEDIKIT', 'stock_threshold_low' => 50]);
        $habis = $this->produk(['sku' => 'APKO-HABIS', 'stock_threshold_low' => 50]);

        $this->stok($banyak, 200);
        $this->stok($sedikit, 40);

        $this->assertSame('available', $this->indikatorUntuk($banyak));
        $this->assertSame('limited', $this->indikatorUntuk($sedikit));
        $this->assertSame('out', $this->indikatorUntuk($habis));
    }

    /** Stok DDP dan kedaluwarsa tidak pernah dihitung sebagai ketersediaan. */
    public function test_stok_ddp_tidak_dihitung_sebagai_tersedia(): void
    {
        $this->loginAs();
        $produk = $this->produk(['sku' => 'APKO-DDP']);
        $this->stok($produk, 500)->update(['status' => InventoryStock::STATUS_DDP]);

        $this->assertSame('out', $this->indikatorUntuk($produk));
    }

    /* ---------------------------------------------------------- Simpan */

    public function test_submit_membuat_pesanan_dengan_status_menunggu(): void
    {
        $sales = $this->loginAs();
        $produk = $this->produk();

        $this->post('/sales/new-order', $this->isian([
            'items' => [['product_id' => $produk->id, 'qty' => 120]],
        ]))->assertRedirect('/sales/my-orders');

        $order = SalesOrder::sole();

        $this->assertSame(SalesOrder::STATUS_PENDING, $order->status);
        $this->assertSame($sales->id, $order->user_id);
        $this->assertNotNull($order->submitted_at, 'submitted_at adalah titik awal SLA.');
        $this->assertSame(120, $order->details()->sole()->qty_ordered);
    }

    public function test_draft_tersimpan_tanpa_waktu_submit(): void
    {
        $this->loginAs();

        $this->post('/sales/new-order', $this->isian(['action' => 'draft']))
            ->assertRedirect('/sales/my-orders');

        $order = SalesOrder::sole();

        $this->assertSame(SalesOrder::STATUS_DRAFT, $order->status);
        $this->assertNull($order->submitted_at);
    }

    public function test_metode_rincian_wajib_punya_item(): void
    {
        $this->loginAs();

        $this->post('/sales/new-order', $this->isian(['items' => []]))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, SalesOrder::count());
    }

    /**
     * SKU ganda ditolak.
     *
     * Dua baris SKU yang sama membuat pemeriksaan stok saat approval
     * menghitung ketersediaan yang sama dua kali, sehingga sistem
     * menjanjikan barang yang sebenarnya tidak ada.
     */
    public function test_sku_ganda_dalam_satu_pesanan_ditolak(): void
    {
        $this->loginAs();
        $produk = $this->produk();

        $this->post('/sales/new-order', $this->isian([
            'items' => [
                ['product_id' => $produk->id, 'qty' => 10],
                ['product_id' => $produk->id, 'qty' => 20],
            ],
        ]))->assertSessionHasErrors('items');

        $this->assertSame(0, SalesOrder::count());
    }

    /* ------------------------------------------------------ Metode dokumen */

    public function test_pesanan_dokumen_tersimpan_tanpa_rincian_item(): void
    {
        $this->loginAs();

        $this->post('/sales/new-order', $this->isian([
            'order_source' => SalesOrder::SOURCE_DOCUMENT,
            'customer_po_number' => 'PO-TOKO-9912',
            'document' => UploadedFile::fake()->create('po-customer.pdf', 120, 'application/pdf'),
            'items' => [],
        ]))->assertRedirect('/sales/my-orders');

        $order = SalesOrder::sole();

        $this->assertTrue($order->isDocumentBased());
        $this->assertSame('PO-TOKO-9912', $order->customer_po_number);
        $this->assertSame(0, $order->details()->count(), 'Rincian diisi Logistik di Fase 6.');
        Storage::disk('local')->assertExists($order->document_path);
    }

    /** Nomor internal tetap dibuat walau customer punya nomor PO sendiri. */
    public function test_pesanan_dokumen_tetap_dapat_nomor_internal(): void
    {
        $this->loginAs();

        $this->post('/sales/new-order', $this->isian([
            'order_source' => SalesOrder::SOURCE_DOCUMENT,
            'customer_po_number' => 'PO-TOKO-9912',
            'document' => UploadedFile::fake()->create('po.pdf', 10, 'application/pdf'),
            'items' => [],
        ]));

        $this->assertStringStartsWith('PO26', SalesOrder::sole()->order_number);
    }

    public function test_pesanan_dokumen_tanpa_berkas_ditolak(): void
    {
        $this->loginAs();

        $this->post('/sales/new-order', $this->isian([
            'order_source' => SalesOrder::SOURCE_DOCUMENT,
            'customer_po_number' => 'PO-TOKO-9912',
            'items' => [],
        ]))->assertSessionHasErrors('document');

        $this->assertSame(0, SalesOrder::count());
    }

    public function test_pesanan_dokumen_tanpa_nomor_po_customer_ditolak(): void
    {
        $this->loginAs();

        $this->post('/sales/new-order', $this->isian([
            'order_source' => SalesOrder::SOURCE_DOCUMENT,
            'document' => UploadedFile::fake()->create('po.pdf', 10, 'application/pdf'),
            'items' => [],
        ]))->assertSessionHasErrors('customer_po_number');
    }

    /** Dua customer boleh memakai nomor PO yang sama — itu nomor milik mereka. */
    public function test_nomor_po_customer_boleh_sama_antar_pesanan(): void
    {
        $this->loginAs();

        foreach ([1, 2] as $ke) {
            $this->post('/sales/new-order', $this->isian([
                'order_source' => SalesOrder::SOURCE_DOCUMENT,
                'customer_po_number' => 'PO-SAMA-001',
                'document' => UploadedFile::fake()->create('po'.$ke.'.pdf', 10, 'application/pdf'),
                'items' => [],
            ]))->assertSessionHasNoErrors();
        }

        $this->assertSame(2, SalesOrder::where('customer_po_number', 'PO-SAMA-001')->count());
    }

    /* ------------------------------------------------------------ Cutoff */

    /**
     * Lewat pukul 15:00 WIB, Submit dikunci — TAPI draft tetap bisa disimpan
     * (§7.5 + docs/4 §3.3.2). Kalau keduanya mati, Sales yang menerima
     * pesanan sore hari akan mencatatnya di luar sistem.
     */
    public function test_submit_ditolak_setelah_pukul_15(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 15:00:00', OrderCutoff::timezone()));
        $this->loginAs();

        $this->post('/sales/new-order', $this->isian())->assertSessionHasErrors('action');

        $this->assertSame(0, SalesOrder::count());
    }

    public function test_draft_tetap_bisa_disimpan_setelah_pukul_15(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 16:30:00', OrderCutoff::timezone()));
        $this->loginAs();

        $this->post('/sales/new-order', $this->isian(['action' => 'draft']))
            ->assertSessionHasNoErrors();

        $this->assertSame(SalesOrder::STATUS_DRAFT, SalesOrder::sole()->status);
    }

    /** Tepat pukul 15:00 sudah ditolak — PRD menulis `>= 15:00`, bukan `>`. */
    public function test_pukul_15_tepat_sudah_tertutup(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 14:59:59', OrderCutoff::timezone()));
        $this->assertTrue(OrderCutoff::isOpen());

        Carbon::setTestNow(Carbon::parse('2026-09-01 15:00:00', OrderCutoff::timezone()));
        $this->assertFalse(OrderCutoff::isOpen());
    }

    /** Draft yang dibuat pagi tidak boleh lolos lewat tombol Kirim sore hari. */
    public function test_kirim_draft_juga_terkunci_setelah_cutoff(): void
    {
        // Jam dimajukan SEBELUM login. TrackUserSession memutus sesi yang
        // menganggur 60 menit, jadi memajukan jam setelah login akan
        // melempar user ke /login dan yang teruji jadi hal yang keliru.
        Carbon::setTestNow(Carbon::parse('2026-09-01 16:00:00', OrderCutoff::timezone()));

        $sales = $this->loginAs();
        $order = SalesOrder::factory()->create([
            'user_id' => $sales->id,
            'created_at' => Carbon::parse('2026-09-01 09:00:00', OrderCutoff::timezone()),
        ]);
        SalesOrderDetail::factory()->create(['sales_order_id' => $order->id]);

        $this->post('/sales/orders/'.$order->id.'/submit')->assertSessionHas('error');

        $this->assertSame(SalesOrder::STATUS_DRAFT, $order->fresh()->status);
    }

    /* --------------------------------------------------- Draft vs terkunci */

    public function test_draft_bisa_diubah(): void
    {
        $sales = $this->loginAs();
        $order = SalesOrder::factory()->create(['user_id' => $sales->id]);
        SalesOrderDetail::factory()->create(['sales_order_id' => $order->id, 'qty_ordered' => 10]);

        $produk = $this->produk();

        $this->put('/sales/orders/'.$order->id, $this->isian([
            'action' => 'draft',
            'items' => [['product_id' => $produk->id, 'qty' => 77]],
        ]))->assertRedirect('/sales/my-orders');

        $detail = $order->fresh()->details()->sole();

        $this->assertSame($produk->id, $detail->product_id);
        $this->assertSame(77, $detail->qty_ordered);
    }

    public function test_draft_bisa_dihapus(): void
    {
        $sales = $this->loginAs();
        $order = SalesOrder::factory()->create(['user_id' => $sales->id]);

        $this->delete('/sales/orders/'.$order->id)->assertRedirect('/sales/my-orders');

        $this->assertSoftDeleted('sales_orders', ['id' => $order->id]);
    }

    /**
     * Pesanan terkirim TERKUNCI (F-OUT-01 #7).
     *
     * Logistik mungkin sedang menilainya; mengubah isinya di belakang layar
     * berarti Logistik menyetujui sesuatu yang berbeda dari yang dilihatnya.
     */
    public function test_pesanan_terkirim_tidak_bisa_diubah_atau_dihapus(): void
    {
        $sales = $this->loginAs();
        $order = SalesOrder::factory()->submitted()->create(['user_id' => $sales->id]);

        $this->get('/sales/orders/'.$order->id.'/edit')->assertForbidden();
        $this->put('/sales/orders/'.$order->id, $this->isian(['action' => 'draft']))->assertForbidden();
        $this->delete('/sales/orders/'.$order->id)->assertForbidden();
        $this->post('/sales/orders/'.$order->id.'/submit')->assertForbidden();

        $this->assertSame(SalesOrder::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_kirim_draft_kosong_ditolak(): void
    {
        $sales = $this->loginAs();
        $order = SalesOrder::factory()->create(['user_id' => $sales->id]);

        $this->post('/sales/orders/'.$order->id.'/submit')->assertSessionHas('error');

        $this->assertSame(SalesOrder::STATUS_DRAFT, $order->fresh()->status);
    }

    /* ------------------------------------------------------------ Detail */

    public function test_detail_menampilkan_item_dan_tahapan(): void
    {
        $sales = $this->loginAs();
        $produk = $this->produk(['sku' => 'ID1-FTESTSKU', 'name' => 'Apko Wall Sealer 5Kg']);
        $order = SalesOrder::factory()->submitted()->create(['user_id' => $sales->id]);
        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id, 'product_id' => $produk->id, 'qty_ordered' => 42,
        ]);

        $halaman = $this->get('/sales/orders/'.$order->id)->assertOk();

        $halaman->assertSee('ID1-FTESTSKU')
            ->assertSee('Apko Wall Sealer 5Kg')
            ->assertSee('42');

        // Stepper memuat SELURUH tahap, termasuk yang belum terjadi, supaya
        // Sales bisa menjawab "sudah sampai mana" tanpa menebak.
        foreach (['Dibuat', 'Diterima', 'Dikemas', 'Dikirim', 'Tiba', 'Selesai'] as $tahap) {
            $halaman->assertSee($tahap);
        }
    }

    /**
     * "Draft" bukan tahap perjalanan pesanan (keputusan pemilik produk).
     *
     * Draft belum jadi pesanan; memasukkannya berarti satu tahap yang tidak
     * pernah berarti apa pun bagi pelanggan memakan seperenam lebar layar HP.
     */
    public function test_stepper_tidak_memuat_tahap_draft(): void
    {
        $sales = $this->loginAs();
        $order = SalesOrder::factory()->submitted()->create(['user_id' => $sales->id]);

        $tahap = collect($this->get('/sales/orders/'.$order->id)->viewData('timeline'))
            ->pluck('judul');

        $this->assertNotContains('Draft', $tahap->all());
        $this->assertSame('Dibuat', $tahap->first());
    }

    /**
     * Hanya SATU tahap yang boleh bertanda "menunggu", dan hanya pada pesanan
     * yang memang sedang berjalan.
     */
    public function test_tahap_menunggu_hanya_pada_pesanan_berjalan(): void
    {
        $sales = $this->loginAs();

        $berjalan = SalesOrder::factory()->submitted()->create(['user_id' => $sales->id]);
        $ditunggu = collect($this->get('/sales/orders/'.$berjalan->id)->viewData('timeline'))
            ->filter(fn ($t) => $t['menunggu']);

        $this->assertCount(1, $ditunggu);
        $this->assertSame('Diterima', $ditunggu->first()['judul']);

        // Draft belum masuk antrean siapa pun.
        $draft = SalesOrder::factory()->create(['user_id' => $sales->id]);
        $this->assertCount(0, collect($this->get('/sales/orders/'.$draft->id)->viewData('timeline'))
            ->filter(fn ($t) => $t['menunggu']));

        // Pesanan yang ditolak berhenti di situ — tidak ada tahap berikutnya.
        $ditolak = SalesOrder::factory()->submitted()->create([
            'user_id' => $sales->id,
            'status' => SalesOrder::STATUS_REJECTED,
            'rejected_at' => now(),
            'rejection_reason' => 'Stok kosong total.',
        ]);
        $this->assertCount(0, collect($this->get('/sales/orders/'.$ditolak->id)->viewData('timeline'))
            ->filter(fn ($t) => $t['menunggu']));
    }

    /**
     * Gudang ditampilkan sebagai NAMA KOTA, bukan kode.
     *
     * "Karawang" langsung dikenali Sales; "WH-01" menuntut hafalan yang tidak
     * ada gunanya di layar ini.
     */
    public function test_gudang_ditampilkan_dengan_namanya(): void
    {
        $sales = $this->loginAs();
        $this->warehouse->update(['code' => 'WH-09', 'name' => 'Karawang']);
        $order = SalesOrder::factory()->submitted()->create([
            'user_id' => $sales->id, 'warehouse_id' => $this->warehouse->id,
        ]);

        $this->get('/sales/orders/'.$order->id)->assertOk()->assertSee('Karawang');
        $this->get('/sales/my-orders')->assertOk()->assertSee('Karawang');
        $this->get('/sales/new-order')->assertOk()->assertSee('Karawang');
    }

    /**
     * UOM tidak ditampilkan di rincian item (permintaan pemilik produk).
     *
     * Layar Sales dipakai dari HP; satuan tidak menambah keputusan apa pun di
     * sana dan hanya memakan lebar yang dibutuhkan nama produk.
     */
    public function test_detail_tidak_menampilkan_uom(): void
    {
        $sales = $this->loginAs();
        $produk = $this->produk(['uom' => 'KALENG']);
        $order = SalesOrder::factory()->submitted()->create(['user_id' => $sales->id]);
        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id, 'product_id' => $produk->id,
        ]);

        $this->get('/sales/orders/'.$order->id)->assertOk()->assertDontSee('KALENG');
    }

    /** Sebelum disetujui, qty_approved 0 berarti "belum dinilai", bukan "nol". */
    public function test_qty_disetujui_belum_ditampilkan_sebelum_approval(): void
    {
        $sales = $this->loginAs();
        $order = SalesOrder::factory()->submitted()->create(['user_id' => $sales->id]);
        SalesOrderDetail::factory()->create(['sales_order_id' => $order->id, 'qty_ordered' => 42]);

        // "Disetujui 0" akan terbaca sebagai "tidak ada yang disetujui",
        // padahal artinya pesanan ini belum dinilai Logistik.
        $this->get('/sales/orders/'.$order->id)
            ->assertOk()
            ->assertDontSee('Disetujui')
            ->assertDontSee('Tidak terpenuhi');
    }

    /** Sesudah approval, qty disetujui dan Lost Sales barulah muncul. */
    public function test_qty_disetujui_muncul_setelah_approval(): void
    {
        $sales = $this->loginAs();
        $order = SalesOrder::factory()->submitted()->create([
            'user_id' => $sales->id,
            'status' => SalesOrder::STATUS_APPROVED,
            'approved_at' => now(),
        ]);
        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'qty_ordered' => 100, 'qty_approved' => 80, 'lost_qty' => 20,
        ]);

        $this->get('/sales/orders/'.$order->id)
            ->assertOk()
            ->assertSee('Disetujui')
            ->assertSee('80')
            ->assertSee('Tidak terpenuhi');
    }
}
