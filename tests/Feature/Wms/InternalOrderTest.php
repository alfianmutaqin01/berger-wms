<?php

namespace Tests\Feature\Wms;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\OrderCutoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Buat Pesanan jalur internal — Admin & Manager atas nama Sales.
 *
 * ENAM HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * -------------------------------------------------
 * 1. CATATANNYA TIDAK BOLEH BERBOHONG. `user_id` = Sales pemiliknya,
 *    `placed_by` = yang benar-benar mengetiknya. Kalau keduanya dilebur,
 *    setiap layar dan setiap laporan akan menyebut Sales membuat pesanan
 *    yang tidak pernah ia sentuh.
 * 2. SALES-NYA HARUS DIBERI TAHU. Pemilik produk memutuskan pembuat boleh
 *    menyetujui pesanannya sendiri, jadi Sales inilah satu-satunya orang di
 *    luar rantai yang bisa menyadari ada yang tidak beres.
 * 3. LOGISTIK TIDAK BOLEH PUNYA AKSESNYA. Merekalah yang menilai pesanan.
 * 4. ATAS NAMA SIAPA DIJAGA KETAT: harus Sales, harus aktif, harus segudang.
 * 5. ALASAN WAJIB, dan ditegakkan basis data juga — bukan cuma PHP.
 * 6. PORTAL SALES TETAP TERTUTUP. Fitur ini pintu terpisah di sisi WMS,
 *    bukan celah ke portal yang PRD §5.2 tutup rapat.
 */
class InternalOrderTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $karawang;

    private Warehouse $pekanbaru;

    private Customer $pelanggan;

    private Product $produk;

    private PaymentTerm $term;

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->karawang = Warehouse::factory()->create([
            'code' => 'WH-01', 'name' => 'Karawang',
        ]);
        $this->pekanbaru = Warehouse::factory()->create(['code' => 'WH-02', 'name' => 'Pekanbaru']);

        $this->pelanggan = Customer::factory()->create([
            'code' => 'C-001', 'name' => 'Toko Melati', 'is_active' => true,
        ]);
        $this->produk = Product::factory()->create([
            'sku' => 'APKO-5L', 'name' => 'Apko 5 Liter', 'uom' => 'PAIL', 'is_active' => true,
        ]);
        $this->term = PaymentTerm::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Cash / Tunai', 'days' => 0, 'is_active' => true, 'sort_order' => 1]
        );

        $this->sales = $this->buatUser(Role::SALES, $this->karawang);

        // Pukul 09:00 WIB — sebelum cutoff, supaya test yang tidak sedang
        // menguji cutoff tidak diam-diam gagal saat dijalankan sore hari.
        //
        // Zona waktunya DISEBUT, bukan mengandalkan zona aplikasi: cutoff
        // dihitung dalam WIB, dan jam yang dipatok di zona lain akan
        // bergeser diam-diam saat dikonversi.
        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00', OrderCutoff::timezone()));
    }

    private function buatUser(string $slug, ?Warehouse $gudang, array $extra = []): User
    {
        return User::factory()->withRole($slug)->create(array_merge([
            'warehouse_id' => $gudang?->id,
            'is_active' => true,
        ], $extra));
    }

    private function login(User $user): User
    {
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
        // WAJIB untuk getJson(): tanpa withCredentials(), Laravel tidak
        // mengirim satu pun cookie pada permintaan JSON — device_token tidak
        // sampai, TrackUserSession menganggap sesinya hilang, dan yang
        // terbaca adalah 302 ke /login alih-alih 403 yang sedang diuji.
        $this->withCredentials();
        $this->actingAs($user);

        return $user;
    }

    /** @param  array<string, mixed>  $ubah */
    private function isian(array $ubah = []): array
    {
        return array_merge([
            'action' => 'submit',
            'sales_user_id' => $this->sales->id,
            'customer_id' => $this->pelanggan->id,
            'payment_term_id' => $this->term->id,
            'order_source' => SalesOrder::SOURCE_MANUAL,
            'reason' => 'Sales sedang cuti dan pelanggan minta barangnya hari ini.',
            'items' => [['product_id' => $this->produk->id, 'qty' => 5]],
        ], $ubah);
    }

    /* ---------------------------------------------------------------- Akses */

    /**
     * Logistik SENGAJA tidak dapat.
     *
     * Merekalah yang menilai pesanan. Memberi mereka pintu pembuatan berarti
     * satu orang membuat sekaligus menilai tanpa seorang pun di luar rantai.
     */
    public function test_logistik_operator_dan_sales_tidak_bisa_membuka(): void
    {
        foreach ([Role::LOGISTICS, Role::WAREHOUSE_OPERATOR, Role::PRODUCTION] as $slug) {
            $this->login($this->buatUser($slug, $this->karawang));

            $this->get(route('wms.internal-order.create'))->assertForbidden();
            $this->post(route('wms.internal-order.store'), $this->isian())->assertForbidden();
        }
    }

    public function test_admin_dan_manager_bisa_membuka(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::MANAGER] as $slug) {
            $this->login($this->buatUser($slug, $slug === Role::SUPER_ADMIN ? null : $this->karawang));

            $this->get(route('wms.internal-order.create'))->assertOk();
        }
    }

    /** Portal Sales tetap tertutup — fitur ini pintu terpisah, bukan celah. */
    public function test_portal_sales_tetap_tertutup_untuk_admin(): void
    {
        $this->login($this->buatUser(Role::SUPER_ADMIN, null));

        $this->get('/sales/new-order')->assertForbidden();
    }

    /* ------------------------------------------- Catatannya tidak berbohong */

    public function test_pesanan_milik_sales_tetapi_pembuatnya_tercatat_terpisah(): void
    {
        $manager = $this->login($this->buatUser(Role::MANAGER, $this->karawang));

        $this->post(route('wms.internal-order.store'), $this->isian())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('wms.approval.index'));

        $order = SalesOrder::latest('id')->first();

        $this->assertSame($this->sales->id, $order->user_id, 'Pesanannya MILIK Sales.');
        $this->assertSame($manager->id, $order->placed_by, 'Yang mengetiknya dicatat terpisah.');
        $this->assertStringContainsString('cuti', $order->placed_reason);
        $this->assertSame(SalesOrder::STATUS_PENDING, $order->status);
        $this->assertTrue($order->dibuatkanOrangLain());

        // Isinya tetap pesanan biasa: Logistik tidak boleh menemukan bentuk
        // yang berbeda hanya karena jalur masuknya lain.
        $this->assertSame(1, $order->details()->count());
        $this->assertSame(5, $order->details()->first()->qty_ordered);
        $this->assertSame($this->karawang->id, $order->warehouse_id);
    }

    /**
     * Pesanan yang dibuat Sales sendiri TIDAK boleh punya placed_by.
     *
     * "Dibuat Budi atas nama Budi" bukan keterangan, itu kebisingan — dan
     * basis data pun menolaknya lewat CHECK.
     */
    public function test_pesanan_sales_sendiri_tidak_punya_penanda_pewakil(): void
    {
        $this->login($this->sales);

        $this->post('/sales/new-order', [
            'action' => 'submit',
            'customer_id' => $this->pelanggan->id,
            'payment_term_id' => $this->term->id,
            'order_source' => SalesOrder::SOURCE_MANUAL,
            'items' => [['product_id' => $this->produk->id, 'qty' => 3]],
        ])->assertSessionHasNoErrors();

        $order = SalesOrder::latest('id')->first();

        $this->assertSame($this->sales->id, $order->user_id);
        $this->assertNull($order->placed_by);
        $this->assertNull($order->placed_reason);
        $this->assertFalse($order->dibuatkanOrangLain());
    }

    /* ------------------------------------------------------ Sales diberi tahu */

    public function test_sales_diberi_tahu_pesanan_dibuat_atas_namanya(): void
    {
        $this->login($this->buatUser(Role::MANAGER, $this->karawang));

        $this->post(route('wms.internal-order.store'), $this->isian())->assertSessionHasNoErrors();

        $kabar = Notification::where('user_id', $this->sales->id)->latest('id')->first();

        $this->assertNotNull($kabar, 'Sales yang namanya dipakai wajib diberi tahu.');
        $this->assertStringContainsString('atas nama Anda', $kabar->title);
    }

    /** Draft belum masuk antrean, jadi belum ada yang perlu dikabari. */
    public function test_draft_belum_mengabari_siapa_pun(): void
    {
        $this->login($this->buatUser(Role::MANAGER, $this->karawang));

        $this->post(route('wms.internal-order.store'), $this->isian(['action' => 'draft']))
            ->assertSessionHasNoErrors();

        $order = SalesOrder::latest('id')->first();

        $this->assertSame(SalesOrder::STATUS_DRAFT, $order->status);
        $this->assertNull($order->submitted_at);
        $this->assertSame(0, Notification::where('user_id', $this->sales->id)->count());

        // Tetap dicatat: membuat pesanan atas nama orang lain lalu
        // membiarkannya sebagai draft tetap membuat pesanan atas nama orang lain.
        $this->assertSame(1, ActivityLog::where('action', ActivityLog::ORDER_PLACED_INTERNAL)->count());
    }

    /* --------------------------------------------------- Atas nama siapa */

    public function test_hanya_akun_sales_yang_bisa_dijadikan_pemilik(): void
    {
        $this->login($this->buatUser(Role::MANAGER, $this->karawang));

        $operator = $this->buatUser(Role::WAREHOUSE_OPERATOR, $this->karawang);

        $this->post(route('wms.internal-order.store'), $this->isian(['sales_user_id' => $operator->id]))
            ->assertSessionHasErrors('sales_user_id');

        $this->assertSame(0, SalesOrder::count());
    }

    public function test_sales_nonaktif_ditolak(): void
    {
        $this->login($this->buatUser(Role::MANAGER, $this->karawang));

        $nonaktif = $this->buatUser(Role::SALES, $this->karawang, ['is_active' => false]);

        $this->post(route('wms.internal-order.store'), $this->isian(['sales_user_id' => $nonaktif->id]))
            ->assertSessionHasErrors('sales_user_id');
    }

    public function test_sales_gudang_lain_ditolak(): void
    {
        $this->login($this->buatUser(Role::MANAGER, $this->karawang));

        $salesLain = $this->buatUser(Role::SALES, $this->pekanbaru);

        $this->post(route('wms.internal-order.store'), $this->isian(['sales_user_id' => $salesLain->id]))
            ->assertSessionHasErrors('sales_user_id');
    }

    /* ------------------------------------------------------- Batas gudang */

    /** Manager tidak bisa memesan untuk gudang lain, sekalipun mengetiknya. */
    public function test_manager_tidak_bisa_memesan_untuk_gudang_lain(): void
    {
        $this->login($this->buatUser(Role::MANAGER, $this->karawang));

        $salesLain = $this->buatUser(Role::SALES, $this->pekanbaru);

        $this->post(route('wms.internal-order.store'), $this->isian([
            'warehouse_id' => $this->pekanbaru->id,
            'sales_user_id' => $salesLain->id,
        ]))->assertSessionHasErrors('sales_user_id');

        $this->assertSame(0, SalesOrder::count());
    }

    /**
     * Super Admin WAJIB memilih gudang.
     *
     * Ia tidak punya gudang sama sekali (warehouse_id NULL berarti "tidak
     * dibatasi"), jadi tanpa kolom gudang di formulir fiturnya mati justru
     * untuk peran yang paling berhak memakainya.
     */
    public function test_super_admin_memilih_gudang_dan_pesanannya_mendarat_di_sana(): void
    {
        $this->login($this->buatUser(Role::SUPER_ADMIN, null));

        $this->post(route('wms.internal-order.store'), $this->isian([
            'warehouse_id' => $this->karawang->id,
        ]))->assertSessionHasNoErrors();

        $this->assertSame($this->karawang->id, SalesOrder::latest('id')->first()->warehouse_id);
    }

    /* ------------------------------------------------------------ Alasan */

    public function test_alasan_wajib_dan_tidak_boleh_asal_isi(): void
    {
        $this->login($this->buatUser(Role::MANAGER, $this->karawang));

        $this->post(route('wms.internal-order.store'), $this->isian(['reason' => '']))
            ->assertSessionHasErrors('reason');

        $this->post(route('wms.internal-order.store'), $this->isian(['reason' => 'lupa']))
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, SalesOrder::count());
    }

    /* ------------------------------------------------------------- Cutoff */

    /**
     * Batas jam berlaku sama untuk jalur internal.
     *
     * Cutoff ada supaya gudang bisa merencanakan picking hari itu, bukan
     * untuk mendisiplinkan Sales — membebaskan jalur ini darinya berarti
     * membuka cara mengacaukan rencana picking yang tidak pernah disepakati.
     */
    public function test_lewat_batas_jam_masih_bisa_draft_tetapi_tidak_bisa_dikirim(): void
    {
        // Jamnya digeser LEBIH DULU, baru login. Menggeser jam sesudah login
        // membuat sesinya sendiri basi dan permintaannya dilempar ke halaman
        // login — yang gagal lalu bukan cutoff-nya, melainkan test-nya.
        Carbon::setTestNow(Carbon::parse(
            '2026-09-01 '.(OrderCutoff::hour() + 1).':00:00',
            OrderCutoff::timezone(),
        ));

        $this->login($this->buatUser(Role::MANAGER, $this->karawang));

        $this->post(route('wms.internal-order.store'), $this->isian())
            ->assertSessionHasErrors('action');

        $this->post(route('wms.internal-order.store'), $this->isian(['action' => 'draft']))
            ->assertSessionHasNoErrors();

        $this->assertSame(SalesOrder::STATUS_DRAFT, SalesOrder::latest('id')->first()->status);
    }

    /* ------------------------------------------------------------- Jejak */

    public function test_pembuatan_tercatat_sebagai_tindakan_tersendiri(): void
    {
        $manager = $this->login($this->buatUser(Role::MANAGER, $this->karawang));

        $this->post(route('wms.internal-order.store'), $this->isian())->assertSessionHasNoErrors();

        $log = ActivityLog::where('action', ActivityLog::ORDER_PLACED_INTERNAL)->first();

        $this->assertNotNull($log, 'Membuat pesanan atas nama orang lain wajib punya jenis log sendiri.');
        $this->assertSame($manager->id, $log->user_id);
        $this->assertStringContainsString($this->sales->full_name, $log->description);
        $this->assertStringContainsString('cuti', $log->properties['alasan']);
    }

    /* ------------------------------------------------- Pencarian isian */

    public function test_pencarian_customer_dan_produk_hanya_untuk_yang_berhak(): void
    {
        $this->login($this->buatUser(Role::MANAGER, $this->karawang));

        $this->getJson(route('wms.internal-order.lookup.customers', ['q' => 'Melati']))
            ->assertOk()
            ->assertJsonFragment(['code' => 'C-001']);

        $this->getJson(route('wms.internal-order.lookup.products', ['q' => 'APKO']))
            ->assertOk()
            ->assertJsonFragment(['sku' => 'APKO-5L']);

        // Logistik tidak punya pintunya, jadi tidak punya pencariannya juga.
        $this->login($this->buatUser(Role::LOGISTICS, $this->karawang));

        $this->getJson(route('wms.internal-order.lookup.customers', ['q' => 'Melati']))->assertForbidden();
        $this->getJson(route('wms.internal-order.lookup.products', ['q' => 'APKO']))->assertForbidden();
    }

    /**
     * Hasil produk TIDAK membawa angka stok.
     *
     * Mengikuti aturan semi-blind Portal Sales (F-INV-03): dua jalur
     * pemesanan tidak boleh bekerja dengan dasar yang berbeda, dan Logistik
     * tetap yang memutuskan qty sebenarnya saat approval.
     */
    public function test_pencarian_produk_tidak_membocorkan_angka_stok(): void
    {
        $this->login($this->buatUser(Role::MANAGER, $this->karawang));

        $hasil = $this->getJson(route('wms.internal-order.lookup.products', ['q' => 'APKO']))
            ->assertOk()
            ->json();

        $this->assertSame(['id', 'sku', 'name', 'uom'], array_keys($hasil[0]));
    }

    /* ----------------------------------------------- Terlihat di layar */

    /** Logistik yang menilai harus tahu pesanan ini bukan dari Sales-nya. */
    public function test_layar_penerimaan_menandai_pesanan_jalur_internal(): void
    {
        $this->login($this->buatUser(Role::MANAGER, $this->karawang));
        $this->post(route('wms.internal-order.store'), $this->isian())->assertSessionHasNoErrors();

        $order = SalesOrder::latest('id')->first();

        $this->login($this->buatUser(Role::LOGISTICS, $this->karawang));

        $this->get(route('wms.approval.show', $order))
            ->assertOk()
            ->assertSee('dibuatkan')
            ->assertSee('cuti');
    }

    /** Dan Sales-nya sendiri harus menemukan penjelasannya di layarnya. */
    public function test_sales_melihat_penjelasan_di_detail_pesanannya(): void
    {
        $manager = $this->login($this->buatUser(Role::MANAGER, $this->karawang));
        $this->post(route('wms.internal-order.store'), $this->isian())->assertSessionHasNoErrors();

        $order = SalesOrder::latest('id')->first();

        $this->login($this->sales);

        $this->get('/sales/orders/'.$order->id)
            ->assertOk()
            ->assertSee('bukan oleh Anda')
            ->assertSee($manager->full_name)
            ->assertSee('cuti');
    }
}
