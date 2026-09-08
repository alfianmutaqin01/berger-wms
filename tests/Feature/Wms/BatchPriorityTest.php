<?php

namespace Tests\Feature\Wms;

use App\Models\Customer;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\StockBooking;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Outbound\FifoAllocator;
use App\Support\Outbound\ProductBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Penanda batch "Dahulukan Keluar" — permintaan pemilik produk, bukan PRD.
 *
 * KEBALIKAN KARANTINA: karantina mengeluarkan batch dari pencalonan, penanda
 * ini menaikkannya ke depan antrean. Kasus nyatanya B01 dan B05 sama-sama di
 * rak; FIFO akan mengambil B01, tetapi B05-lah yang harus keluar.
 *
 * EMPAT HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * ---------------------------------------------------
 * 1. KETIGA JALUR KELUAR HARUS SEPAKAT. Alokasi pesanan, pencadangan booking,
 *    dan pengeluaran saat kirim dulu menulis urutannya sendiri-sendiri. Kalau
 *    penandanya cuma berlaku di satu jalur, batchnya didahulukan saat pesanan
 *    diterima tetapi tidak saat barangnya keluar — dan itu baru ketahuan
 *    berbulan-bulan kemudian.
 * 2. DUA ARAH DALAM SATU ANTREAN. Batch bertanda diurutkan LIFO (termuda
 *    dulu), yang tidak bertanda tetap FIFO. Salah satu arah saja yang benar
 *    tidak akan menghasilkan galat apa pun — cuma batch yang salah terkirim.
 * 3. ALASAN WAJIB, ditegakkan constraint database — bukan cuma validasi form.
 *    Tanpa itu penanda sementara berubah jadi keadaan permanen tanpa pemilik.
 * 4. LEPAS SENDIRI SAAT BATCH HABIS, dan "habis" berarti qty_available DAN
 *    qty_allocated dua-duanya nol di seluruh baris batch. Batch yang sudah
 *    teralokasi penuh belum habis: barangnya masih di rak.
 */
class BatchPriorityTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $produk;

    private ?User $penanda = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create(['code' => 'WH-01']);
        $this->produk = Product::factory()->create(['sku' => 'ID1-F00113202225', 'uom' => 'PAIL']);
    }

    /* ---------------------------------------------------------- Penolong */

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

    /**
     * Logistik yang "memasang penanda" pada data awal test.
     *
     * Terpisah dari login() dengan sengaja: dipakai untuk mengisi
     * prioritized_by pada baris stok yang dibuat langsung lewat factory, tanpa
     * ikut mengganti siapa yang sedang masuk. Constraint database menuntut
     * kolom itu terisi, dan foreign key menuntut orangnya sungguh ada.
     */
    private function penanda(): User
    {
        return $this->penanda ??= User::factory()->withRole(Role::LOGISTICS)->create();
    }

    private function rak(string $kode): Location
    {
        $parts = Location::parseCode($kode);

        return Location::firstOrCreate(
            ['warehouse_id' => $this->warehouse->id, 'code' => $kode],
            [
                'rack' => $parts['rack'], 'level' => $parts['level'], 'cell' => $parts['cell'],
                'zone' => Location::ZONE_FAST, 'is_active' => true,
            ]
        );
    }

    private function batch(string $nomor, string $produksi, int $qty = 100, array $extra = []): InventoryStock
    {
        // Kode rak diturunkan dari nomor batch supaya tiap batch punya raknya
        // sendiri — penanda ini berlaku sebatch, dan menaruh dua batch di satu
        // rak akan menyamarkan kesalahan "berlaku per baris, bukan per batch".
        static $urut = 0;
        $rak = sprintf('B-%02d-01', ++$urut);

        return InventoryStock::factory()->create(array_merge([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->rak($rak)->id,
            'batch_no' => $nomor,
            'production_date' => $produksi,
            'expiry_date' => now()->addYear()->toDateString(),
            'status' => InventoryStock::STATUS_ACTIVE,
            'qty_available' => $qty,
            'qty_allocated' => 0,
        ], $extra));
    }

    private function detail(int $qty = 10): SalesOrderDetail
    {
        $order = SalesOrder::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'status' => SalesOrder::STATUS_APPROVED,
            // Constraint sales_orders_submitted_at_required: pesanan yang sudah
            // lewat draft wajib punya waktu pengajuan.
            'submitted_at' => now()->subDay(),
        ]);

        return SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => $qty,
            'qty_approved' => $qty,
        ]);
    }

    /* ------------------------------------------------------ Inti: urutan */

    public function test_batch_bertanda_dialokasikan_lebih_dulu_walau_lebih_muda(): void
    {
        $b01 = $this->batch('B01', now()->subMonths(6)->toDateString());
        $b05 = $this->batch('B05', now()->subMonth()->toDateString(), 100, [
            'prioritize_out' => true,
            'prioritize_reason' => 'Diminta customer, harus dikosongkan duluan.',
            'prioritized_at' => now(),
            'prioritized_by' => $this->login()->id,
        ]);

        app(FifoAllocator::class)->allocate($this->detail(10), 10, null);

        $this->assertSame(90, $b05->fresh()->qty_available, 'B05 bertanda seharusnya diambil lebih dulu.');
        $this->assertSame(100, $b01->fresh()->qty_available, 'B01 yang lebih tua seharusnya belum tersentuh.');
    }

    public function test_tanpa_penanda_tetap_fifo_murni(): void
    {
        $b01 = $this->batch('B01', now()->subMonths(6)->toDateString());
        $b05 = $this->batch('B05', now()->subMonth()->toDateString());

        app(FifoAllocator::class)->allocate($this->detail(10), 10, null);

        $this->assertSame(90, $b01->fresh()->qty_available, 'Tanpa penanda, yang tertua tetap keluar duluan.');
        $this->assertSame(100, $b05->fresh()->qty_available);
    }

    public function test_sesama_batch_bertanda_diurutkan_terbalik_lifo(): void
    {
        $userId = $this->login()->id;
        $tanda = [
            'prioritize_out' => true,
            'prioritize_reason' => 'Dikosongkan duluan.',
            'prioritized_at' => now(),
            'prioritized_by' => $userId,
        ];

        $lama = $this->batch('B02', now()->subMonths(4)->toDateString(), 100, $tanda);
        $muda = $this->batch('B07', now()->subMonth()->toDateString(), 100, $tanda);

        app(FifoAllocator::class)->allocate($this->detail(10), 10, null);

        $this->assertSame(90, $muda->fresh()->qty_available,
            'Pada batch bertanda, FIFO berubah jadi LIFO — yang termuda keluar duluan.');
        $this->assertSame(100, $lama->fresh()->qty_available);
    }

    public function test_batch_bertanda_habis_dulu_baru_lanjut_ke_yang_tertua(): void
    {
        $b01 = $this->batch('B01', now()->subMonths(6)->toDateString(), 100);
        $b05 = $this->batch('B05', now()->subMonth()->toDateString(), 30, [
            'prioritize_out' => true,
            'prioritize_reason' => 'Dikosongkan duluan.',
            'prioritized_at' => now(),
            'prioritized_by' => $this->login()->id,
        ]);

        app(FifoAllocator::class)->allocate($this->detail(50), 50, null);

        $this->assertSame(0, $b05->fresh()->qty_available, 'Batch bertanda dihabiskan lebih dulu.');
        $this->assertSame(80, $b01->fresh()->qty_available, 'Sisanya baru diambil dari yang tertua.');
    }

    public function test_penanda_tidak_membuat_batch_karantina_ikut_dialokasikan(): void
    {
        $aktif = $this->batch('B01', now()->subMonths(6)->toDateString());
        $ditahan = $this->batch('B05', now()->subMonth()->toDateString(), 100, [
            'status' => InventoryStock::STATUS_QUARANTINE,
            'quarantine_days' => 7,
            'quarantine_until' => now()->addDays(7)->toDateString(),
            'quarantined_at' => now(),
            'quarantined_by' => $this->login()->id,
            'prioritize_out' => true,
            'prioritize_reason' => 'Dikosongkan duluan setelah karantina lepas.',
            'prioritized_at' => now(),
            'prioritized_by' => $this->penanda()->id,
        ]);

        app(FifoAllocator::class)->allocate($this->detail(10), 10, null);

        $this->assertSame(100, $ditahan->fresh()->qty_available,
            'Penanda hanya mengubah URUTAN. Kelayakan jual tetap ditentukan status — karantina tetap terlewati.');
        $this->assertSame(90, $aktif->fresh()->qty_available);
    }

    /* ----------------------------------------------- Jalur keluar lainnya */

    public function test_booking_memakai_urutan_yang_sama(): void
    {
        $userId = $this->login()->id;
        $b01 = $this->batch('B01', now()->subMonths(6)->toDateString());
        $b05 = $this->batch('B05', now()->subMonth()->toDateString(), 100, [
            'prioritize_out' => true,
            'prioritize_reason' => 'Dikosongkan duluan.',
            'prioritized_at' => now(),
            'prioritized_by' => $userId,
        ]);

        $booking = StockBooking::create([
            'reference' => 'BK260926001',
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => Customer::factory()->create()->id,
            'product_id' => $this->produk->id,
            'qty_booked' => 20,
            'qty_used' => 0,
            'status' => StockBooking::STATUS_OPEN,
            'created_by' => $userId,
        ]);

        app(ProductBooking::class)->reserve($booking, $userId);

        $this->assertSame(20, $b05->fresh()->qty_allocated,
            'Booking harus memakai urutan keluar yang sama persis dengan alokasi pesanan.');
        $this->assertSame(0, $b01->fresh()->qty_allocated);
    }

    /* ------------------------------------------------------- Memasangnya */

    public function test_logistik_dapat_menandai_lewat_layar(): void
    {
        $this->login();
        $stok = $this->batch('B05', now()->subMonth()->toDateString());

        $this->post(route('wms.inventory.prioritize'), [
            'stock_id' => $stok->id,
            'reason' => 'Batch B05 diminta customer PT Aneka.',
        ])->assertSessionHas('warning');

        $segar = $stok->fresh();
        $this->assertTrue($segar->prioritize_out);
        $this->assertSame('Batch B05 diminta customer PT Aneka.', $segar->prioritize_reason);
        $this->assertNotNull($segar->prioritized_by);
    }

    public function test_alasan_kosong_ditolak(): void
    {
        $this->login();
        $stok = $this->batch('B05', now()->subMonth()->toDateString());

        $this->post(route('wms.inventory.prioritize'), [
            'stock_id' => $stok->id,
            'reason' => '',
        ])->assertSessionHasErrors('reason');

        $this->assertFalse($stok->fresh()->prioritize_out);
    }

    public function test_penanda_berlaku_sebatch_bukan_seraknya(): void
    {
        $this->login();
        $rakA = $this->batch('B05', now()->subMonth()->toDateString());
        $rakB = InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->rak('B-90-01')->id,
            'batch_no' => 'B05',
            'production_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'status' => InventoryStock::STATUS_ACTIVE,
            'qty_available' => 40,
        ]);

        $this->post(route('wms.inventory.prioritize'), [
            'stock_id' => $rakA->id,
            'reason' => 'Dikosongkan duluan.',
        ]);

        $this->assertTrue($rakB->fresh()->prioritize_out,
            'Mendahulukan satu rak saja akan membuat sisa batch tetap mengantre di belakang.');
    }

    public function test_batch_ddp_tidak_boleh_didahulukan(): void
    {
        $this->login();
        $stok = $this->batch('B05', now()->subMonth()->toDateString(), 100, [
            'status' => InventoryStock::STATUS_DDP,
            'ddp_reason' => InventoryStock::DDP_WRITE_OFF,
        ]);

        $this->post(route('wms.inventory.prioritize'), [
            'stock_id' => $stok->id,
            'reason' => 'Coba dahulukan.',
        ])->assertSessionHas('error');

        $this->assertFalse($stok->fresh()->prioritize_out);
    }

    public function test_penandaan_tercatat_di_ledger(): void
    {
        $this->login();
        $stok = $this->batch('B05', now()->subMonth()->toDateString());

        $this->post(route('wms.inventory.prioritize'), [
            'stock_id' => $stok->id,
            'reason' => 'Diminta customer PT Aneka.',
        ]);

        $gerak = StockMovement::where('batch_no', 'B05')
            ->where('movement_type', StockMovement::TYPE_ADJUSTMENT)
            ->latest('id')
            ->first();

        $this->assertNotNull($gerak, 'Keputusan yang mengubah barang mana yang keluar wajib bisa ditelusuri.');
        $this->assertSame(0, (int) $gerak->qty_change, 'Qty tidak berubah — hanya urutannya.');
        $this->assertStringContainsString('Diminta customer PT Aneka', $gerak->notes);
    }

    public function test_gudang_lain_ditolak(): void
    {
        // Orangnya yang ditaruh di gudang lain, bukan stoknya — supaya yang
        // diuji benar-benar batas wewenang, bukan sekadar data yang tidak ada.
        $lain = Warehouse::factory()->create(['code' => 'WH-02']);
        $user = User::factory()->withRole(Role::LOGISTICS)->create(['warehouse_id' => $lain->id]);
        $token = Str::random(64);
        UserSession::create([
            'user_id' => $user->id, 'session_id' => $token, 'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit', 'last_activity_at' => now(), 'created_at' => now(),
        ]);
        $this->withUnencryptedCookies(['device_token' => $token]);
        $this->actingAs($user);

        $stok = $this->batch('B09', now()->subMonth()->toDateString());

        $this->post(route('wms.inventory.prioritize'), [
            'stock_id' => $stok->id,
            'reason' => 'Coba lintas gudang.',
        ])->assertForbidden();
    }

    public function test_produksi_tidak_boleh_menandai(): void
    {
        $this->login(Role::PRODUCTION);
        $stok = $this->batch('B05', now()->subMonth()->toDateString());

        $this->post(route('wms.inventory.prioritize'), [
            'stock_id' => $stok->id,
            'reason' => 'Coba.',
        ])->assertForbidden();
    }

    /* --------------------------------------------------------- Melepasnya */

    public function test_melepas_penanda_mengembalikan_urutan_fifo(): void
    {
        $this->login();
        $b01 = $this->batch('B01', now()->subMonths(6)->toDateString());
        $b05 = $this->batch('B05', now()->subMonth()->toDateString(), 100, [
            'prioritize_out' => true,
            'prioritize_reason' => 'Dikosongkan duluan.',
            'prioritized_at' => now(),
            'prioritized_by' => $this->penanda()->id,
        ]);

        $this->post(route('wms.inventory.prioritize.release', $b05))->assertSessionHas('success');

        app(FifoAllocator::class)->allocate($this->detail(10), 10, null);

        $this->assertSame(90, $b01->fresh()->qty_available, 'Setelah dilepas, yang tertua kembali keluar duluan.');
        $this->assertNotNull($b05->fresh()->prioritize_released_at);
    }

    public function test_jejak_alasan_tidak_dihapus_saat_dilepas(): void
    {
        $this->login();
        $stok = $this->batch('B05', now()->subMonth()->toDateString(), 100, [
            'prioritize_out' => true,
            'prioritize_reason' => 'Diminta customer PT Aneka.',
            'prioritized_at' => now(),
            'prioritized_by' => $this->penanda()->id,
        ]);

        $this->post(route('wms.inventory.prioritize.release', $stok));

        $segar = $stok->fresh();
        $this->assertFalse($segar->prioritize_out);
        $this->assertSame('Diminta customer PT Aneka.', $segar->prioritize_reason,
            'Alasan & waktu penandaan adalah jejak audit — hilang kalau ditimpa null saat dilepas.');
        $this->assertNotNull($segar->prioritized_at);
    }

    /* ------------------------------------------------- Ikut pindah ke rak lain */

    public function test_penanda_ikut_pindah_saat_stok_dipindahkan_ke_rak_lain(): void
    {
        $this->login();
        $stok = $this->batch('B05', now()->subMonth()->toDateString(), 100, [
            'prioritize_out' => true,
            'prioritize_reason' => 'Diminta customer PT Aneka.',
            'prioritized_at' => now(),
            'prioritized_by' => $this->penanda()->id,
            'has_quality_issue' => true,
        ]);
        $this->rak('B-95-01');

        $this->post('/wms/inventory/transfer', [
            'stock_id' => $stok->id,
            'to_location_code' => 'B-95-01',
            'qty' => 40,
            'reason' => 'Merapikan rak.',
        ])->assertSessionHas('success');

        $tujuan = InventoryStock::where('batch_no', 'B05')
            ->whereHas('location', fn ($q) => $q->where('code', 'B-95-01'))
            ->first();

        $this->assertNotNull($tujuan);
        $this->assertTrue($tujuan->prioritize_out,
            'Penanda melekat pada BATCH. Baris baru tanpa penanda membuat separuh batch didahulukan dan separuhnya tidak.');
        $this->assertSame('Diminta customer PT Aneka.', $tujuan->prioritize_reason);
        $this->assertTrue($tujuan->has_quality_issue);
    }

    public function test_batch_terkarantina_bisa_dipindahkan_ke_rak_lain(): void
    {
        $this->login();
        $stok = $this->batch('B05', now()->subMonth()->toDateString(), 100, [
            'status' => InventoryStock::STATUS_QUARANTINE,
            'quarantine_days' => 7,
            'quarantine_until' => now()->addDays(7)->toDateString(),
            'quarantined_at' => now(),
            'quarantined_by' => $this->penanda()->id,
        ]);
        $this->rak('B-96-01');

        // Tanpa metadata karantina yang ikut pindah, CHECK constraint
        // inventory_stocks_karantina_lengkap menolak baris tujuannya dan
        // pemindahan gagal dengan galat database mentah.
        $this->post('/wms/inventory/transfer', [
            'stock_id' => $stok->id,
            'to_location_code' => 'B-96-01',
            'qty' => 40,
            'reason' => 'Merapikan rak.',
        ])->assertSessionHas('success');

        $tujuan = InventoryStock::where('batch_no', 'B05')
            ->whereHas('location', fn ($q) => $q->where('code', 'B-96-01'))
            ->first();

        $this->assertNotNull($tujuan);
        $this->assertSame(InventoryStock::STATUS_QUARANTINE, $tujuan->status);
        $this->assertNotNull($tujuan->quarantine_until);
    }

    public function test_operator_gudang_boleh_memindahkan_antar_rak(): void
    {
        $this->login(Role::WAREHOUSE_OPERATOR);
        $stok = $this->batch('B05', now()->subMonth()->toDateString());
        $this->rak('B-97-01');

        $this->post('/wms/inventory/transfer', [
            'stock_id' => $stok->id,
            'to_location_code' => 'B-97-01',
            'qty' => 10,
            'reason' => 'Merapikan rak.',
        ])->assertSessionHas('success');
    }

    public function test_operator_gudang_tetap_tidak_boleh_mengubah_qty(): void
    {
        $this->login(Role::WAREHOUSE_OPERATOR);
        $stok = $this->batch('B05', now()->subMonth()->toDateString());

        // Memindahkan TIDAK mengubah jumlah; menambah/mengurangi tetap
        // wewenang Manager & Super Admin.
        $this->post('/wms/inventory/adjust', [
            'stock_id' => $stok->id,
            'qty_new' => 999,
            'reason' => 'Coba mengubah qty.',
        ])->assertForbidden();
    }

    /* --------------------------------------------------------- Sweep habis */

    public function test_sweep_melepas_penanda_dari_batch_yang_habis(): void
    {
        $this->login();
        $stok = $this->batch('B05', now()->subMonth()->toDateString(), 0, [
            'qty_allocated' => 0,
            'prioritize_out' => true,
            'prioritize_reason' => 'Sudah dikosongkan.',
            'prioritized_at' => now(),
            'prioritized_by' => $this->penanda()->id,
        ]);

        $this->artisan('stock:sweep-priority')->assertSuccessful();

        $this->assertFalse($stok->fresh()->prioritize_out);
    }

    public function test_sweep_tidak_melepas_batch_yang_hanya_teralokasi_penuh(): void
    {
        $this->login();
        $stok = $this->batch('B05', now()->subMonth()->toDateString(), 0, [
            'qty_allocated' => 40,
            'prioritize_out' => true,
            'prioritize_reason' => 'Dikosongkan duluan.',
            'prioritized_at' => now(),
            'prioritized_by' => $this->penanda()->id,
        ]);

        $this->artisan('stock:sweep-priority')->assertSuccessful();

        $this->assertTrue($stok->fresh()->prioritize_out,
            'Teralokasi penuh BUKAN habis — barangnya masih di rak, dan alokasinya masih bisa dibatalkan.');
    }

    public function test_sweep_tidak_melepas_batch_yang_masih_ada_sisa_di_rak_lain(): void
    {
        $this->login();
        $tanda = [
            'prioritize_out' => true,
            'prioritize_reason' => 'Dikosongkan duluan.',
            'prioritized_at' => now(),
            'prioritized_by' => $this->penanda()->id,
        ];

        $kosong = $this->batch('B05', now()->subMonth()->toDateString(), 0, $tanda);
        InventoryStock::factory()->create(array_merge([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->rak('B-90-01')->id,
            'batch_no' => 'B05',
            'production_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'status' => InventoryStock::STATUS_ACTIVE,
            'qty_available' => 25,
        ], $tanda));

        $this->artisan('stock:sweep-priority')->assertSuccessful();

        $this->assertTrue($kosong->fresh()->prioritize_out,
            'Habis diukur per BATCH, bukan per baris rak — satu rak kosong tidak berarti batchnya habis.');
    }

    public function test_sweep_dry_run_tidak_mengubah_apa_pun(): void
    {
        $this->login();
        $stok = $this->batch('B05', now()->subMonth()->toDateString(), 0, [
            'prioritize_out' => true,
            'prioritize_reason' => 'Sudah dikosongkan.',
            'prioritized_at' => now(),
            'prioritized_by' => $this->penanda()->id,
        ]);

        $this->artisan('stock:sweep-priority --dry-run')->assertSuccessful();

        $this->assertTrue($stok->fresh()->prioritize_out);
    }
}
