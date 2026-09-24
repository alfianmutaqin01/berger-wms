<?php

namespace Tests\Feature\Wms;

use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Notification;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Inbound\BinAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pemindahan stok DDP ke rak DDP — permintaan pemilik produk.
 *
 * Yang paling perlu dijaga di berkas ini BUKAN tombolnya, melainkan satu hal:
 * daftar pekerjaannya disimpulkan dari lokasi barang, bukan disimpan. Karena
 * itu pengujian di bawah banyak memeriksa kapan sebuah baris MUNCUL dan kapan
 * ia HILANG dengan sendirinya — sebab di situlah rancangan ini bisa runtuh
 * tanpa suara: daftar yang bohong tentang apa yang belum dikerjakan jauh lebih
 * berbahaya daripada daftar yang kosong.
 */
class DdpRelocationTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create(['code' => 'WH-01']);
    }

    private function loginAs(string $roleSlug): User
    {
        $user = User::factory()->withRole($roleSlug)->create(['warehouse_id' => $this->warehouse->id]);
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
        $this->actingAs($user);

        return $user;
    }

    private function bin(string $code, ?string $zone = null): Location
    {
        $parts = Location::parseCode($code);

        return Location::firstOrCreate(
            ['warehouse_id' => $this->warehouse->id, 'code' => $code],
            [
                'rack' => $parts['rack'],
                'level' => $parts['level'],
                'cell' => $parts['cell'],
                'zone' => $zone ?? Location::ZONE_FAST,
                'is_active' => true,
            ]
        );
    }

    private function rakDdp(string $code = 'W-01-01'): Location
    {
        return $this->bin($code, Location::ZONE_DDP);
    }

    private function stokDdp(array $overrides = []): InventoryStock
    {
        return InventoryStock::factory()->create(array_merge([
            'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->bin('B-01-01')->id,
            'product_id' => Product::factory()->create(['uom' => 'TIN'])->id,
            'status' => InventoryStock::STATUS_EXPIRED,
            'ddp_reason' => InventoryStock::DDP_EXPIRED,
            'qty_available' => 60,
        ], $overrides));
    }

    /* --------------------------------------------------------------- Daftar */

    /**
     * INTI RANCANGAN INI. Tidak ada yang membuat daftar: stok DDP yang berdiri
     * di rak barang bagus SUDAH merupakan pekerjaan yang belum selesai.
     */
    public function test_stok_ddp_di_rak_fg_langsung_muncul_tanpa_dibuatkan_tugas(): void
    {
        $stok = $this->stokDdp();

        $this->loginAs(Role::LOGISTICS);

        $this->get(route('wms.ddp.index'))
            ->assertOk()
            ->assertSee($stok->batch_no);
    }

    /** Dan barang yang sudah duduk di rak DDP bukan pekerjaan siapa pun lagi. */
    public function test_stok_ddp_yang_sudah_di_rak_ddp_tidak_ikut_daftar(): void
    {
        $this->stokDdp(['location_id' => $this->rakDdp()->id]);

        $this->loginAs(Role::LOGISTICS);

        $this->get(route('wms.ddp.index'))->assertViewHas('stats', fn (array $s) => $s['baris'] === 0);
    }

    /**
     * Karantina sengaja TIDAK ikut: penahanannya sementara dan sebagiannya
     * kembali jadi barang siap jual. Memindahkannya bolak-balik ke rak DDP
     * hanya menambah pekerjaan yang batal sendiri.
     */
    public function test_stok_karantina_tidak_ikut_daftar_pemindahan_ddp(): void
    {
        $this->stokDdp([
            'status' => InventoryStock::STATUS_QUARANTINE,
            'ddp_reason' => null,
            'quarantine_days' => 7,
            'quarantine_until' => now()->addDays(7)->toDateString(),
            'quarantined_at' => now(),
            'quarantined_by' => User::factory()->withRole(Role::LOGISTICS)->create()->id,
        ]);

        $this->loginAs(Role::LOGISTICS);

        $this->get(route('wms.ddp.index'))->assertViewHas('stats', fn (array $s) => $s['baris'] === 0);
    }

    /* ---------------------------------------------------------------- Akses */

    public function test_produksi_dan_sales_tidak_boleh_membuka(): void
    {
        foreach ([Role::PRODUCTION, Role::SALES] as $role) {
            $this->loginAs($role);
            $this->get(route('wms.ddp.index'))->assertForbidden();
        }
    }

    /** Operator mengangkat barangnya, jadi ia harus bisa membuka daftarnya. */
    public function test_operator_dan_logistik_boleh_membuka(): void
    {
        foreach ([Role::WAREHOUSE_OPERATOR, Role::LOGISTICS] as $role) {
            $this->loginAs($role);
            $this->get(route('wms.ddp.index'))->assertOk();
        }
    }

    /** Operator tidak menentukan baris mana yang dikerjakan — itu Logistik. */
    public function test_operator_tidak_boleh_menyerahkan_daftar(): void
    {
        $stok = $this->stokDdp();

        $this->loginAs(Role::WAREHOUSE_OPERATOR);

        $this->post(route('wms.ddp.serahkan'), ['stock_ids' => [$stok->id]])->assertForbidden();

        $this->assertNull($stok->fresh()->ddp_assigned_at);
    }

    /** Dan Logistik tidak mengangkat barangnya sendiri. */
    public function test_logistik_tidak_boleh_menjalankan_pemindahan(): void
    {
        $stok = $this->stokDdp();

        $this->loginAs(Role::LOGISTICS);

        $this->post(route('wms.ddp.pindahkan'), [
            'stock_id' => $stok->id,
            'location_id' => $this->rakDdp()->id,
            'qty' => 60,
        ])->assertForbidden();
    }

    /* ------------------------------------------------------------ Serah tugas */

    public function test_logistik_menyerahkan_baris_dan_operator_dikabari(): void
    {
        $stok = $this->stokDdp();
        $operator = User::factory()->withRole(Role::WAREHOUSE_OPERATOR)->create(['warehouse_id' => $this->warehouse->id]);

        $logistik = $this->loginAs(Role::LOGISTICS);

        $this->post(route('wms.ddp.serahkan'), ['stock_ids' => [$stok->id]])
            ->assertRedirect()
            ->assertSessionHas('success');

        $stok->refresh();
        $this->assertNotNull($stok->ddp_assigned_at);
        $this->assertSame($logistik->id, $stok->ddp_assigned_by);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $operator->id,
            'type' => Notification::STOCK_DDP_ASSIGNED,
        ]);
    }

    /* ------------------------------------------------------------ Pemindahan */

    public function test_operator_memindahkan_dan_barisnya_hilang_dari_daftar(): void
    {
        $stok = $this->stokDdp(['ddp_assigned_at' => now()]);
        $rakDdp = $this->rakDdp();

        $this->loginAs(Role::WAREHOUSE_OPERATOR);

        $this->post(route('wms.ddp.pindahkan'), [
            'stock_id' => $stok->id,
            'location_id' => $rakDdp->id,
            'qty' => 60,
        ])->assertRedirect()->assertSessionHas('success');

        // Barang pindah utuh: asal kosong, rak DDP berisi, status ikut serta.
        $this->assertSame(0, $stok->fresh()->qty_available);

        $diRakDdp = InventoryStock::where('location_id', $rakDdp->id)->firstOrFail();
        $this->assertSame(60, $diRakDdp->qty_available);
        $this->assertSame(InventoryStock::STATUS_EXPIRED, $diRakDdp->status);
        $this->assertSame($stok->batch_no, $diRakDdp->batch_no);

        // Mutasinya sepasang dan berjumlah nol — tidak ada stok tercipta.
        $this->assertSame(0, (int) StockMovement::where('batch_no', $stok->batch_no)->sum('qty_change'));

        $this->get(route('wms.ddp.index'))->assertViewHas('stats', fn (array $s) => $s['baris'] === 0);
    }

    /**
     * Tidak semuanya selalu terangkut sekali jalan. Sisanya WAJIB tetap
     * muncul, kalau tidak separuh barang kedaluwarsa tertinggal di rak FG
     * tanpa satu pun layar yang menyebutnya lagi.
     */
    public function test_pemindahan_sebagian_menyisakan_baris_di_daftar(): void
    {
        $stok = $this->stokDdp(['ddp_assigned_at' => now(), 'qty_available' => 60]);

        $this->loginAs(Role::WAREHOUSE_OPERATOR);

        $this->post(route('wms.ddp.pindahkan'), [
            'stock_id' => $stok->id,
            'location_id' => $this->rakDdp()->id,
            'qty' => 25,
        ])->assertRedirect();

        $this->assertSame(35, $stok->fresh()->qty_available);

        $this->get(route('wms.ddp.index'))->assertViewHas('stats', fn (array $s) => $s['baris'] === 1 && $s['unit'] === 35);
    }

    /**
     * RAK TUJUAN WAJIB RAK DDP, dan itu diperiksa di server. Dropdown yang
     * sudah benar tetap bisa dilewati dengan mengirim id rak lain, dan
     * menurunkan barang kedaluwarsa ke rak FG adalah persis kesalahan yang
     * dicegah seluruh layar ini.
     */
    public function test_rak_tujuan_bukan_rak_ddp_ditolak(): void
    {
        $stok = $this->stokDdp(['ddp_assigned_at' => now()]);
        $rakFg = $this->bin('C-02-03');

        $this->loginAs(Role::WAREHOUSE_OPERATOR);

        $this->post(route('wms.ddp.pindahkan'), [
            'stock_id' => $stok->id,
            'location_id' => $rakFg->id,
            'qty' => 60,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(60, $stok->fresh()->qty_available);
        $this->assertSame(0, InventoryStock::where('location_id', $rakFg->id)->count());
    }

    /** Stok siap jual tidak lewat jalur ini, meski id-nya dikirim langsung. */
    public function test_stok_bagus_tidak_bisa_diturunkan_lewat_jalur_ddp(): void
    {
        $bagus = $this->stokDdp([
            'status' => InventoryStock::STATUS_ACTIVE,
            'ddp_reason' => null,
            'ddp_assigned_at' => now(),
        ]);

        $this->loginAs(Role::WAREHOUSE_OPERATOR);

        $this->post(route('wms.ddp.pindahkan'), [
            'stock_id' => $bagus->id,
            'location_id' => $this->rakDdp()->id,
            'qty' => 60,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(60, $bagus->fresh()->qty_available);
    }

    /* ------------------------------------------------------------- Penandaan */

    public function test_logistik_menandai_deret_rak_sebagai_rak_ddp(): void
    {
        $this->bin('W-01-01');
        $this->bin('W-01-02');

        $this->loginAs(Role::LOGISTICS);

        $this->patch(route('wms.locations.deret-ddp', 'W'), [
            'warehouse_id' => $this->warehouse->id,
            'jadikan_ddp' => 1,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(2, Location::where('rack', 'W')->where('zone', Location::ZONE_DDP)->count());
    }

    /**
     * Menandai deret yang masih menyimpan barang siap jual akan menyembunyikan
     * barang itu dari saran put-away sekaligus menaruhnya di rak yang mestinya
     * hanya berisi barang tak layak jual — percampuran yang sama, hanya
     * terbalik arahnya.
     */
    public function test_deret_yang_masih_berisi_barang_bagus_ditolak(): void
    {
        $this->stokDdp([
            'location_id' => $this->bin('W-02-01')->id,
            'status' => InventoryStock::STATUS_ACTIVE,
            'ddp_reason' => null,
        ]);

        $this->loginAs(Role::LOGISTICS);

        $this->patch(route('wms.locations.deret-ddp', 'W'), [
            'warehouse_id' => $this->warehouse->id,
            'jadikan_ddp' => 1,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, Location::where('zone', Location::ZONE_DDP)->count());
    }

    public function test_operator_tidak_boleh_menandai_deret_rak(): void
    {
        $this->bin('W-01-01');

        $this->loginAs(Role::WAREHOUSE_OPERATOR);

        $this->patch(route('wms.locations.deret-ddp', 'W'), [
            'warehouse_id' => $this->warehouse->id,
            'jadikan_ddp' => 1,
        ])->assertForbidden();
    }

    /* ------------------------------------------------------------ Notifikasi */

    public function test_perintah_mengabari_logistik_sekali_saja(): void
    {
        $this->stokDdp();
        $logistik = User::factory()->withRole(Role::LOGISTICS)->create(['warehouse_id' => $this->warehouse->id]);

        $this->artisan('stock:kabarkan-ddp')->assertSuccessful();

        $this->assertSame(1, Notification::where('user_id', $logistik->id)
            ->where('type', Notification::STOCK_DDP_PENDING)->count());

        // Putaran berikutnya tidak mengulang kabar yang sama; kalau mengulang,
        // lonceng dipenuhi satu kejadian dan kabar lain tenggelam.
        $this->artisan('stock:kabarkan-ddp')->assertSuccessful();

        $this->assertSame(1, Notification::where('user_id', $logistik->id)
            ->where('type', Notification::STOCK_DDP_PENDING)->count());
    }

    /** Yang sudah rapi di rak DDP tidak perlu dikabarkan sama sekali. */
    public function test_stok_yang_sudah_di_rak_ddp_tidak_dikabarkan(): void
    {
        $this->stokDdp(['location_id' => $this->rakDdp()->id]);
        User::factory()->withRole(Role::LOGISTICS)->create(['warehouse_id' => $this->warehouse->id]);

        $this->artisan('stock:kabarkan-ddp')->assertSuccessful();

        $this->assertSame(0, Notification::where('type', Notification::STOCK_DDP_PENDING)->count());
    }

    /* --------------------------------------------------------------- Put-away */

    /**
     * Rak DDP tidak boleh muncul sebagai saran put-away barang baru. Kalau
     * muncul, barang bagus mendarat di rak DDP dan percampuran yang ingin
     * dicegah rak itu kembali lagi lewat pintu depan.
     */
    public function test_rak_ddp_tidak_disarankan_untuk_barang_bagus(): void
    {
        $rakDdp = $this->rakDdp('W-03-01');
        $rakBiasa = $this->bin('B-01-01');

        $allocator = BinAllocator::forWarehouse($this->warehouse->id, $this->warehouse->code);

        $this->assertFalse($allocator->has($rakDdp->code), 'Rak DDP tidak boleh ikut daftar bin put-away.');
        $this->assertTrue($allocator->has($rakBiasa->code), 'Rak biasa tetap harus dikenal.');
    }
}
