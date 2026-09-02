<?php

namespace Tests\Feature\Wms;

use App\Models\Customer;
use App\Models\InboundHeader;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pembatasan data per gudang — sisipan multi-gudang Fase 6.
 *
 * MENGAPA BERKAS INI PENTING
 * --------------------------
 * Sebelum perubahan ini, `users.warehouse_id` sudah ada sejak Fase 1 tetapi
 * TIDAK membatasi apa pun: penyaringan gudang hanyalah filter opsional dari
 * URL. Seluruh 368 test yang ada tetap hijau dalam keadaan itu — karena
 * user di test dahulu tidak terikat gudang, atau terikat pada satu-satunya
 * gudang yang dibuat. Keadaan "tidak ada pembatasan sama sekali" tidak
 * menghasilkan satu pun test merah.
 *
 * Karena itu tiap test di sini selalu membuat DUA gudang dan memeriksa dari
 * sisi gudang yang SALAH. Yang diuji bukan bahwa layarnya bisa dibuka,
 * melainkan bahwa data gudang lain tidak bisa disentuh — terutama lewat URL
 * detail, yang tidak tertutup hanya dengan menyaring daftar.
 */
class WarehouseScopingTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $karawang;

    private Warehouse $surabaya;

    private Product $produk;

    private PaymentTerm $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->karawang = Warehouse::factory()->withProduction()->create(['code' => 'WH-01', 'name' => 'Karawang']);
        $this->surabaya = Warehouse::factory()->create(['code' => 'WH-03', 'name' => 'Surabaya']);
        $this->produk = Product::factory()->create(['sku' => 'APKO-001', 'is_active' => true]);
        $this->term = PaymentTerm::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Cash / Tunai', 'days' => 0, 'is_active' => true, 'sort_order' => 1]
        );
    }

    /** Login sebagai user yang TERIKAT satu gudang. */
    private function loginAt(?Warehouse $gudang, string $slug = Role::LOGISTICS): User
    {
        $user = User::factory()->withRole($slug)->create(['warehouse_id' => $gudang?->id]);
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
        $this->withCredentials();
        $this->actingAs($user);

        return $user;
    }

    private function pesananDi(Warehouse $gudang, array $atribut = []): SalesOrder
    {
        $sales = User::factory()->withRole(Role::SALES)->create(['warehouse_id' => $gudang->id]);

        return SalesOrder::factory()->submitted()->create(array_merge([
            'user_id' => $sales->id,
            'customer_id' => Customer::factory()->create(['is_active' => true])->id,
            'warehouse_id' => $gudang->id,
            'payment_term_id' => $this->term->id,
            'status' => SalesOrder::STATUS_PENDING,
        ], $atribut));
    }

    private function stokDi(Warehouse $gudang, int $qty = 100): InventoryStock
    {
        $lokasi = Location::factory()->create(['warehouse_id' => $gudang->id]);

        return InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'warehouse_id' => $gudang->id,
            'location_id' => $lokasi->id,
            'batch_no' => 'BT-'.Str::random(4),
            'production_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYears(2)->toDateString(),
            'qty_available' => $qty,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);
    }

    /* ----------------------------------------------- Penerimaan pesanan */

    public function test_antrean_pesanan_hanya_memuat_gudang_sendiri(): void
    {
        $this->loginAt($this->karawang);

        $milikSendiri = $this->pesananDi($this->karawang);
        $milikOrangLain = $this->pesananDi($this->surabaya);

        $this->get('/wms/outbound/approval')
            ->assertOk()
            ->assertSee($milikSendiri->order_number)
            ->assertDontSee($milikOrangLain->order_number);
    }

    /**
     * Inti seluruh berkas ini: daftar yang disaring TIDAK menutup URL detail.
     */
    public function test_detail_pesanan_gudang_lain_ditolak(): void
    {
        $this->loginAt($this->karawang);
        $asing = $this->pesananDi($this->surabaya);

        $this->get("/wms/outbound/approval/{$asing->id}")->assertForbidden();
    }

    public function test_menerima_pesanan_gudang_lain_ditolak(): void
    {
        $this->loginAt($this->karawang);
        $asing = $this->pesananDi($this->surabaya);

        $this->post("/wms/outbound/approval/{$asing->id}/accept", [
            'bc_so_number' => 'SO-999',
            'item' => [],
        ])->assertForbidden();

        $this->assertSame(SalesOrder::STATUS_PENDING, $asing->fresh()->status);
    }

    public function test_menolak_pesanan_gudang_lain_ditolak(): void
    {
        $this->loginAt($this->karawang);
        $asing = $this->pesananDi($this->surabaya);

        $this->post("/wms/outbound/approval/{$asing->id}/reject", [
            'rejection_reason' => 'Percobaan dari gudang lain.',
        ])->assertForbidden();

        $this->assertSame(SalesOrder::STATUS_PENDING, $asing->fresh()->status);
    }

    /** resolve() mengembalikan angka stok — celah baca kalau tidak dijaga. */
    public function test_terjemah_sku_pesanan_gudang_lain_ditolak(): void
    {
        $this->loginAt($this->karawang);
        $asing = $this->pesananDi($this->surabaya);

        $this->postJson("/wms/outbound/approval/{$asing->id}/resolve", [
            'sku' => ['APKO-001'],
        ])->assertForbidden();
    }

    public function test_riwayat_penerimaan_hanya_gudang_sendiri(): void
    {
        $this->loginAt($this->karawang);

        $sendiri = $this->pesananDi($this->karawang, [
            'status' => SalesOrder::STATUS_APPROVED, 'approved_at' => now(),
        ]);
        $asing = $this->pesananDi($this->surabaya, [
            'status' => SalesOrder::STATUS_APPROVED, 'approved_at' => now(),
        ]);

        $this->get('/wms/outbound/approval/history')
            ->assertOk()
            ->assertSee($sendiri->order_number)
            ->assertDontSee($asing->order_number);
    }

    /**
     * Filter adalah pilihan tampilan, bukan pintu.
     *
     * Sebelum perubahan ini, MENGHAPUS parameternya membuka seluruh gudang.
     * Sekarang MENGISI parameternya dengan gudang lain pun tidak melebarkan
     * apa pun — nilainya dijepit ke gudang user, bukan dipakai apa adanya.
     */
    public function test_filter_gudang_di_url_tidak_melebarkan_akses(): void
    {
        $this->loginAt($this->karawang);
        $asing = $this->pesananDi($this->surabaya);

        $this->get('/wms/outbound/approval?warehouse='.$this->surabaya->id)
            ->assertOk()
            ->assertDontSee($asing->order_number);
    }

    /* -------------------------------------------------------------- Stok */

    public function test_daftar_stok_hanya_gudang_sendiri(): void
    {
        $this->loginAt($this->karawang, Role::MANAGER);

        // Diperiksa lewat NOMOR BATCH, bukan nama kota: nama gudang juga
        // muncul di dropdown penyaring dan di kepala halaman, sehingga
        // assertSee('Karawang') bisa lulus tanpa ada satu baris stok pun.
        $sendiri = $this->stokDi($this->karawang, 70);
        $asing = $this->stokDi($this->surabaya, 55);

        $this->get('/wms/inventory')
            ->assertOk()
            ->assertSee($sendiri->batch_no)
            ->assertDontSee($asing->batch_no);
    }

    public function test_koreksi_stok_gudang_lain_ditolak(): void
    {
        $this->loginAt($this->karawang, Role::MANAGER);
        $asing = $this->stokDi($this->surabaya, 40);

        $this->post('/wms/inventory/adjust', [
            'stock_id' => $asing->id,
            'qty_new' => 999,
            'reason' => 'Percobaan dari gudang lain.',
        ])->assertForbidden();

        $this->assertSame(40, $asing->fresh()->qty_available);
    }

    public function test_pemindahan_stok_gudang_lain_ditolak(): void
    {
        $this->loginAt($this->karawang, Role::MANAGER);
        $asing = $this->stokDi($this->surabaya, 40);
        Location::factory()->create(['warehouse_id' => $this->surabaya->id, 'code' => 'Z-09-09']);

        $this->post('/wms/inventory/transfer', [
            'stock_id' => $asing->id,
            'to_location_code' => 'Z-09-09',
            'qty' => 10,
            'reason' => 'Percobaan dari gudang lain.',
        ])->assertForbidden();

        $this->assertSame(40, $asing->fresh()->qty_available);
    }

    public function test_menambah_stok_ke_rak_gudang_lain_ditolak(): void
    {
        $this->loginAt($this->karawang, Role::MANAGER);
        $rakAsing = Location::factory()->create(['warehouse_id' => $this->surabaya->id, 'code' => 'Z-01-01']);

        $this->post('/wms/inventory/stocks', [
            'sku' => $this->produk->sku,
            'location_code' => $rakAsing->code,
            'batch_no' => 'BT-0001',
            'qty' => 25,
            'production_date' => now()->subMonth()->toDateString(),
            'reason' => 'Percobaan dari gudang lain.',
        ])->assertForbidden();

        $this->assertDatabaseMissing('inventory_stocks', ['location_id' => $rakAsing->id]);
    }

    /* ----------------------------------------------------------- Inbound */

    public function test_dokumen_produksi_gudang_lain_ditolak(): void
    {
        $this->loginAt($this->karawang, Role::PRODUCTION);

        $asing = InboundHeader::factory()->create([
            'warehouse_id' => $this->surabaya->id,
            'document_number' => 'IN-20260913-0001',
        ]);

        $this->get("/wms/inbound/history/{$asing->document_number}")->assertForbidden();
    }

    /**
     * Produksi hanya ada di Karawang.
     *
     * Ini pemeriksaan yang BERBEDA dari pembatasan gudang: gudangnya memang
     * milik user ini, tetapi gudang itu tidak berproduksi sama sekali.
     */
    public function test_input_produksi_di_gudang_tanpa_lini_produksi_ditolak(): void
    {
        $this->loginAt($this->surabaya, Role::PRODUCTION);

        $this->get('/wms/inbound/create')
            ->assertOk()
            ->assertDontSee('WH-03 (Surabaya)');
    }

    /* ---------------------------------------------------- Manajemen user */

    public function test_manager_hanya_melihat_user_gudangnya(): void
    {
        $this->loginAt($this->karawang, Role::MANAGER);

        $sendiri = User::factory()->withRole(Role::LOGISTICS)
            ->create(['warehouse_id' => $this->karawang->id, 'full_name' => 'Budi Karawang']);
        $asing = User::factory()->withRole(Role::LOGISTICS)
            ->create(['warehouse_id' => $this->surabaya->id, 'full_name' => 'Sari Surabaya']);

        $this->get('/wms/admin/users')
            ->assertOk()
            ->assertSee($sendiri->full_name)
            ->assertDontSee($asing->full_name);
    }

    public function test_manager_tidak_dapat_mengubah_user_gudang_lain(): void
    {
        $this->loginAt($this->karawang, Role::MANAGER);

        $asing = User::factory()->withRole(Role::LOGISTICS)
            ->create(['warehouse_id' => $this->surabaya->id, 'full_name' => 'Sari Surabaya']);

        $this->put("/wms/admin/users/{$asing->id}", [
            'employee_id' => $asing->employee_id,
            'full_name' => 'Nama Diambil Alih',
            'email' => $asing->email,
            'role_id' => $asing->role_id,
            'department_id' => $asing->department_id,
            'warehouse_id' => $this->karawang->id,
            'is_active' => 1,
        ])->assertForbidden();

        $this->assertSame('Sari Surabaya', $asing->fresh()->full_name);
    }

    public function test_manager_tidak_dapat_membuat_user_di_gudang_lain(): void
    {
        $manager = $this->loginAt($this->karawang, Role::MANAGER);

        $this->post('/wms/admin/users', [
            'employee_id' => 'EMP-9001',
            'full_name' => 'Titipan Di Gudang Lain',
            'email' => 'titipan@berger.test',
            'password' => 'rahasia123',
            'role_id' => $manager->role_id,
            'department_id' => $manager->department_id,
            'warehouse_id' => $this->surabaya->id,
            'is_active' => 1,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'titipan@berger.test']);
    }

    /* --------------------------------------------------- Master lokasi */

    public function test_menonaktifkan_rak_gudang_lain_ditolak(): void
    {
        $this->loginAt($this->karawang, Role::MANAGER);
        $rakAsing = Location::factory()->create(['warehouse_id' => $this->surabaya->id, 'is_active' => true]);

        $this->patch("/wms/master/locations/{$rakAsing->id}/status")->assertForbidden();

        $this->assertTrue($rakAsing->fresh()->is_active);
    }

    /* ------------------------------------------------- Akses lintas gudang */

    /**
     * Akun tanpa gudang (Super Admin) TIDAK ikut dibatasi.
     *
     * Diuji supaya pembatasan ini tidak diam-diam mengunci semua orang —
     * kegagalan yang gejalanya justru terlihat seperti keamanan yang bekerja.
     */
    public function test_akun_lintas_gudang_tetap_melihat_semua(): void
    {
        $this->loginAt(null, Role::SUPER_ADMIN);

        $karawang = $this->pesananDi($this->karawang);
        $surabaya = $this->pesananDi($this->surabaya);

        $this->get('/wms/outbound/approval')
            ->assertOk()
            ->assertSee($karawang->order_number)
            ->assertSee($surabaya->order_number);
    }

    public function test_akun_lintas_gudang_dapat_membuka_detail_gudang_mana_pun(): void
    {
        $this->loginAt(null, Role::SUPER_ADMIN);
        $surabaya = $this->pesananDi($this->surabaya);

        $this->get("/wms/outbound/approval/{$surabaya->id}")->assertOk();
    }
}
