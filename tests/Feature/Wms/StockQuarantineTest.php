<?php

namespace Tests\Feature\Wms;

use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Outbound\FifoAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Karantina & Masalah Kualitas — permintaan pemilik produk, bukan PRD.
 *
 * LIMA HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * ---------------------------------------------------
 * 1. KARANTINA BUKAN DDP. Selama berlaku, stok tetap "boleh dijual, tapi
 *    menunggu" — otomatis lepas sendiri, tidak menunggu tindakan manual.
 * 2. SATU BATCH, SATU KEPUTUSAN. Menahan satu baris harus ikut menahan
 *    seluruh baris produk+gudang+batch yang sama, bukan cuma yang dipilih.
 * 3. TIDAK IKUT FIFO SELAMA DITAHAN. FifoAllocator menyaring `status=active`
 *    secara langsung — batch berstatus 'quarantine' harus otomatis terlewati
 *    tanpa perlu mengubah satu pun query alokasi.
 * 4. LEPAS OTOMATIS via sweep, BUKAN via permintaan halaman. Sweep berjalan
 *    lepas dari siapa pun yang sedang membuka layar.
 * 5. MASALAH KUALITAS MURNI INFORMASI — tidak menyentuh status, tidak
 *    menghalangi alokasi sama sekali. Namanya terdengar seperti penahanan,
 *    jadi justru inilah yang paling perlu dikunci tesnya: yang menahan
 *    batch tetap Karantina dan DDP, bukan penanda ini.
 */
class StockQuarantineTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create(['code' => 'WH-01']);
        $this->produk = Product::factory()->create(['sku' => 'ID1-F00113202225', 'uom' => 'PAIL']);
    }

    /* ------------------------------------------------------------ Perkakas */

    private function login(string $slug = Role::LOGISTICS): User
    {
        $user = User::factory()->withRole($slug)->create();
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

    private function bin(string $code): Location
    {
        $parts = Location::parseCode($code);

        return Location::firstOrCreate(
            ['warehouse_id' => $this->warehouse->id, 'code' => $code],
            [
                'rack' => $parts['rack'], 'level' => $parts['level'], 'cell' => $parts['cell'],
                'zone' => Location::ZONE_FAST, 'is_active' => true,
            ]
        );
    }

    private function stock(array $overrides = []): InventoryStock
    {
        return InventoryStock::factory()->create(array_merge([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->bin('B-01-01')->id,
            'batch_no' => 'BT-2026-001',
            'status' => InventoryStock::STATUS_ACTIVE,
            'qty_available' => 50,
            'qty_allocated' => 0,
        ], $overrides));
    }

    /**
     * Stok berstatus 'quarantine' yang metadatanya LENGKAP.
     *
     * CHECK inventory_stocks_karantina_lengkap mewajibkan quarantine_until,
     * quarantined_by, DAN quarantined_at terisi bersamaan selama status masih
     * 'quarantine' — persis kondisi yang seharusnya selalu benar kalau baris
     * itu dibuat lewat StockQuarantine::place(), bukan ditulis langsung.
     */
    private function stokKarantina(array $overrides = []): InventoryStock
    {
        $hari = $overrides['quarantine_days'] ?? 30;

        return $this->stock(array_merge([
            'status' => InventoryStock::STATUS_QUARANTINE,
            'quarantine_days' => $hari,
            'quarantine_until' => now()->addDays($hari)->toDateString(),
            'quarantined_at' => now(),
            'quarantined_by' => User::factory()->create()->id,
        ], $overrides));
    }

    /* -------------------------------------------------------------- Akses */

    public function test_logistik_boleh_mengarantina(): void
    {
        $this->login(Role::LOGISTICS);
        $stok = $this->stock();

        $this->post(route('wms.inventory.quarantine'), ['stock_id' => $stok->id, 'days' => 30])
            ->assertSessionHas('warning');

        $this->assertSame(InventoryStock::STATUS_QUARANTINE, $stok->fresh()->status);
    }

    public function test_produksi_tidak_boleh_mengarantina(): void
    {
        $this->login(Role::PRODUCTION);
        $stok = $this->stock();

        $this->post(route('wms.inventory.quarantine'), ['stock_id' => $stok->id, 'days' => 30])
            ->assertForbidden();

        $this->assertSame(InventoryStock::STATUS_ACTIVE, $stok->fresh()->status);
    }

    /* ---------------------------------------------------------- Karantina */

    public function test_mengarantina_menghitung_tanggal_lepas_dari_hari_ini(): void
    {
        $this->login();
        $stok = $this->stock();

        $this->post(route('wms.inventory.quarantine'), [
            'stock_id' => $stok->id,
            'days' => 30,
            'note' => 'Menunggu hasil uji QC.',
        ]);

        $stok->refresh();

        $this->assertSame(InventoryStock::STATUS_QUARANTINE, $stok->status);
        $this->assertSame(30, $stok->quarantine_days);
        $this->assertSame(now()->addDays(30)->toDateString(), $stok->quarantine_until->toDateString());
        $this->assertNotNull($stok->quarantined_at);
        $this->assertSame('Menunggu hasil uji QC.', $stok->quarantine_note);
        $this->assertNull($stok->quarantine_released_at);
    }

    public function test_seluruh_baris_sebatch_ikut_dikarantina(): void
    {
        $this->login();

        // Batch yang sama, dua rak berbeda — persis kasus yang dijelaskan
        // pemilik produk: satu batch, dua lokasi.
        $rakA = $this->stock(['location_id' => $this->bin('B-01-01')->id, 'qty_available' => 20]);
        $rakB = $this->stock(['location_id' => $this->bin('B-01-02')->id, 'qty_available' => 30]);

        // Batch LAIN, produk sama — tidak boleh ikut tersentuh.
        $batchLain = $this->stock(['batch_no' => 'BT-2026-999', 'qty_available' => 15]);

        $this->post(route('wms.inventory.quarantine'), ['stock_id' => $rakA->id, 'days' => 14])
            ->assertSessionHas('warning');

        $this->assertSame(InventoryStock::STATUS_QUARANTINE, $rakA->fresh()->status);
        $this->assertSame(InventoryStock::STATUS_QUARANTINE, $rakB->fresh()->status);
        $this->assertSame(InventoryStock::STATUS_ACTIVE, $batchLain->fresh()->status, 'Batch lain tidak boleh ikut dikarantina.');
    }

    public function test_batch_yang_sudah_ddp_tidak_bisa_dikarantina(): void
    {
        $this->login();
        $stok = $this->stock([
            'status' => InventoryStock::STATUS_DDP,
            'ddp_reason' => InventoryStock::DDP_RETURN_DAMAGED,
        ]);

        $this->post(route('wms.inventory.quarantine'), ['stock_id' => $stok->id, 'days' => 30])
            ->assertSessionHas('error');

        $this->assertSame(InventoryStock::STATUS_DDP, $stok->fresh()->status);
    }

    public function test_karantina_mencatat_ledger_tanpa_mengubah_qty(): void
    {
        $this->login();
        $stok = $this->stock(['qty_available' => 40]);

        $this->post(route('wms.inventory.quarantine'), ['stock_id' => $stok->id, 'days' => 30]);

        $mutasi = StockMovement::where('reference_id', $stok->id)
            ->where('reference_type', StockMovement::REF_ADJUSTMENT)
            ->latest('id')->first();

        $this->assertNotNull($mutasi);
        $this->assertSame(0, $mutasi->qty_change);
        $this->assertSame(40, $stok->fresh()->qty_available, 'Qty fisik tidak boleh berubah — barangnya tidak pindah.');
        $this->assertStringContainsString('KARANTINA', $mutasi->notes);
    }

    public function test_batch_dikarantina_tidak_ikut_alokasi_fifo(): void
    {
        $stok = $this->stock(['qty_available' => 50]);
        $this->login();

        $this->post(route('wms.inventory.quarantine'), ['stock_id' => $stok->id, 'days' => 30]);

        $order = SalesOrder::factory()->create(['warehouse_id' => $this->warehouse->id]);
        $detail = SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id, 'product_id' => $this->produk->id,
            'qty_ordered' => 10, 'qty_approved' => 10,
        ]);

        // TIDAK ADA stok lain — kalau FifoAllocator diam-diam mengambil batch
        // yang dikarantina, angka ini akan lebih besar dari 0.
        $didapat = app(FifoAllocator::class)->allocate($detail, 10, null);

        $this->assertSame(0, $didapat, 'Batch yang dikarantina tidak boleh ikut teralokasi.');
        $this->assertSame(0, $stok->fresh()->qty_allocated);
    }

    public function test_hari_kurang_dari_satu_ditolak(): void
    {
        $this->login();
        $stok = $this->stock();

        $this->post(route('wms.inventory.quarantine'), ['stock_id' => $stok->id, 'days' => 0])
            ->assertSessionHasErrors('days');
    }

    public function test_gudang_lain_ditolak_403(): void
    {
        $lain = Warehouse::factory()->create(['code' => 'WH-02']);
        $user = User::factory()->withRole(Role::LOGISTICS)->create(['warehouse_id' => $lain->id]);
        $token = Str::random(64);
        UserSession::create([
            'user_id' => $user->id, 'session_id' => $token, 'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit', 'last_activity_at' => now(), 'created_at' => now(),
        ]);
        $this->withUnencryptedCookies(['device_token' => $token]);
        $this->actingAs($user);

        $stok = $this->stock();

        $this->post(route('wms.inventory.quarantine'), ['stock_id' => $stok->id, 'days' => 30])
            ->assertForbidden();
    }

    /* ---------------------------------------------------- Lepas lebih awal */

    public function test_melepas_karantina_lebih_awal(): void
    {
        $this->login();
        $stok = $this->stokKarantina();

        $this->post(route('wms.inventory.quarantine.release', $stok))->assertSessionHas('success');

        $stok->refresh();
        $this->assertSame(InventoryStock::STATUS_ACTIVE, $stok->status);
        $this->assertNotNull($stok->quarantine_released_at);
    }

    public function test_melepas_batch_yang_tidak_dikarantina_ditolak(): void
    {
        $this->login();
        $stok = $this->stock();

        $this->post(route('wms.inventory.quarantine.release', $stok))->assertSessionHas('error');
    }

    /* ------------------------------------------------------- Sweep otomatis */

    public function test_sweep_melepas_batch_yang_jangka_waktunya_lewat(): void
    {
        $lewat = $this->stokKarantina([
            'batch_no' => 'BT-LEWAT',
            'quarantine_until' => now()->subDay()->toDateString(),
            'quarantined_at' => now()->subDays(31),
        ]);
        $belumLewat = $this->stokKarantina([
            'batch_no' => 'BT-BELUM',
            'quarantine_until' => now()->addDays(5)->toDateString(),
            'quarantined_at' => now()->subDays(25),
        ]);

        $this->artisan('stock:sweep-quarantine')->assertSuccessful();

        $this->assertSame(InventoryStock::STATUS_ACTIVE, $lewat->fresh()->status);
        $this->assertNotNull($lewat->fresh()->quarantine_released_at);

        $this->assertSame(
            InventoryStock::STATUS_QUARANTINE,
            $belumLewat->fresh()->status,
            'Batch yang jangka waktunya belum lewat tidak boleh ikut dilepas.'
        );
    }

    public function test_sweep_dry_run_tidak_mengubah_apa_pun(): void
    {
        $stok = $this->stokKarantina([
            'quarantine_until' => now()->subDay()->toDateString(),
            'quarantined_at' => now()->subDays(31),
        ]);

        $this->artisan('stock:sweep-quarantine', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(InventoryStock::STATUS_QUARANTINE, $stok->fresh()->status);
    }

    /* ----------------------------------------------------------- Riwayat */

    public function test_kolom_karantina_lama_tidak_dikosongkan_setelah_lepas(): void
    {
        // Justru kolom historis inilah jejak "batch ini pernah dikarantina
        // dan kapan" — menimpanya jadi null menghapus riwayat itu.
        $stok = $this->stokKarantina([
            'quarantine_until' => now()->subDay()->toDateString(),
            'quarantined_at' => now()->subDays(31),
        ]);

        $this->artisan('stock:sweep-quarantine');

        $stok->refresh();
        $this->assertSame(30, $stok->quarantine_days);
        $this->assertNotNull($stok->quarantined_at);
        $this->assertNotNull($stok->quarantine_released_at);
    }

    /* -------------------------------------------------------- Masalah Kualitas */

    public function test_menandai_masalah_kualitas(): void
    {
        $this->login();
        $stok = $this->stock();

        $this->post(route('wms.inventory.quality-issue', $stok))->assertSessionHas('success');

        $this->assertTrue($stok->fresh()->has_quality_issue);
    }

    public function test_menandai_masalah_kualitas_dua_kali_membalik_lagi(): void
    {
        $this->login();
        $stok = $this->stock(['has_quality_issue' => true]);

        $this->post(route('wms.inventory.quality-issue', $stok));

        $this->assertFalse($stok->fresh()->has_quality_issue);
    }

    public function test_masalah_kualitas_berlaku_sebatch(): void
    {
        $this->login();
        $rakA = $this->stock(['location_id' => $this->bin('B-01-01')->id]);
        $rakB = $this->stock(['location_id' => $this->bin('B-01-02')->id]);

        $this->post(route('wms.inventory.quality-issue', $rakA));

        $this->assertTrue($rakB->fresh()->has_quality_issue, 'Masalah Kualitas adalah atribut batch, bukan atribut satu baris rak.');
    }

    public function test_masalah_kualitas_tidak_menghalangi_alokasi(): void
    {
        $stok = $this->stock(['has_quality_issue' => true, 'qty_available' => 50]);
        $this->login();

        $order = SalesOrder::factory()->create(['warehouse_id' => $this->warehouse->id]);
        $detail = SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id, 'product_id' => $this->produk->id,
            'qty_ordered' => 10, 'qty_approved' => 10,
        ]);

        $didapat = app(FifoAllocator::class)->allocate($detail, 10, null);

        $this->assertSame(10, $didapat, 'Masalah Kualitas murni informasi — tidak boleh menghalangi FIFO.');
    }

    public function test_masalah_kualitas_boleh_ditandai_pada_stok_ddp(): void
    {
        $this->login();
        $stok = $this->stock([
            'status' => InventoryStock::STATUS_DDP,
            'ddp_reason' => InventoryStock::DDP_WRITE_OFF,
        ]);

        $this->post(route('wms.inventory.quality-issue', $stok))->assertSessionHas('success');

        $this->assertTrue($stok->fresh()->has_quality_issue);
        $this->assertSame(InventoryStock::STATUS_DDP, $stok->fresh()->status, 'Penanda ini tidak boleh mengubah status DDP.');
    }

    /* --------------------------------------------------------- Tampilan */

    public function test_batch_dikarantina_tetap_terlihat_di_accordion(): void
    {
        // Sebelum bucket ketiga ditambahkan, batch berstatus 'quarantine'
        // tidak cocok dengan bucket good (status != active) MAUPUN ddp
        // (bukan ddp/expired) — lenyap dari accordion sama sekali.
        $this->login(Role::MANAGER);
        $this->stokKarantina();

        $baris = collect($this->get('/wms/inventory')->viewData('barisSku'))->first();

        $this->assertNotNull($baris);
        $this->assertCount(1, $baris['karantina']);
        $this->assertCount(0, $baris['good']);
        $this->assertCount(0, $baris['ddp']);
    }

    public function test_ringkasan_menghitung_karantina_terpisah_dari_ddp(): void
    {
        $this->login(Role::MANAGER);
        $this->stokKarantina(['qty_available' => 25]);
        $this->stock(['batch_no' => 'BT-DDP', 'status' => InventoryStock::STATUS_DDP, 'ddp_reason' => InventoryStock::DDP_OPNAME, 'qty_available' => 8]);

        $stats = $this->get('/wms/inventory')->viewData('stats');

        $this->assertSame(25, $stats['karantina']);
        $this->assertSame(8, $stats['ddp'], 'Karantina tidak boleh ikut terhitung sebagai DDP.');
    }

    public function test_filter_status_karantina(): void
    {
        $this->login(Role::MANAGER);
        $this->stokKarantina();
        $this->stock(['batch_no' => 'BT-DDP', 'status' => InventoryStock::STATUS_DDP, 'ddp_reason' => InventoryStock::DDP_OPNAME]);

        $baris = collect(
            $this->get('/wms/inventory?status='.InventoryStock::STATUS_QUARANTINE)->viewData('barisSku')
        )->first();

        $this->assertCount(1, $baris['karantina']);
        $this->assertCount(0, $baris['ddp']);
    }
}
