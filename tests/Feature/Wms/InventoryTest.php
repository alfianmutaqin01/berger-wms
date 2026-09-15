<?php

namespace Tests\Feature\Wms;

use App\Models\InboundDetail;
use App\Models\InboundHeader;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Inventory & Stok — PRD §6.4, §7.2, §7.2.1.
 *
 * Aturan yang paling dijaga di sini:
 *   1. Stok RESMI ADA hanya setelah verifikasi Logistik (F-INB-03 langkah 9).
 *   2. Batch TIDAK dilebur — FIFO menuntut tiap batch jadi baris tersendiri.
 *   3. Stok DDP/kedaluwarsa TIDAK PERNAH ikut alokasi.
 *   4. Ledger stock_movements APPEND-ONLY.
 *   5. Koreksi wajib beralasan dan tidak boleh di bawah qty_allocated.
 */
class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create(['code' => 'WH-01']);
    }

    private function loginAs(string $roleSlug = Role::MANAGER): User
    {
        $user = User::factory()->withRole($roleSlug)->create();
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
                'rack' => $parts['rack'],
                'level' => $parts['level'],
                'cell' => $parts['cell'],
                'zone' => Location::ZONE_FAST,
                'is_active' => true,
            ]
        );
    }

    private function stock(array $overrides = []): InventoryStock
    {
        return InventoryStock::factory()->create(array_merge([
            'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->bin('B-01-01')->id,
            'product_id' => Product::factory()->create(['uom' => 'TIN'])->id,
        ], $overrides));
    }

    /**
     * Seluruh batch yang benar-benar terlihat di layar, dari kedua blok.
     *
     * Daftarnya kini bersarang (satu baris SKU -> blok good + blok DDP), jadi
     * pengujian isi daftar meratakannya dulu supaya yang diperiksa tetap
     * "batch mana yang terlihat", bukan bentuk strukturnya.
     */
    private function batchDiLayar(string $url = '/wms/inventory')
    {
        return collect($this->get($url)->viewData('barisSku'))
            ->flatMap(fn (array $baris) => $baris['good']->merge($baris['karantina'])->merge($baris['ddp']));
    }

    /* ---------------------------------------------------------------- Akses */

    public function test_semua_role_operasional_boleh_melihat_stok(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS, Role::PRODUCTION, Role::WAREHOUSE_OPERATOR] as $slug) {
            $this->loginAs($slug);
            $this->get('/wms/inventory')->assertOk()->assertViewHas('barisSku');
        }
    }

    /** Hanya Manager & Super Admin yang boleh mengoreksi stok (F-INV-02). */
    public function test_hanya_manager_dan_super_admin_boleh_mengoreksi(): void
    {
        $stock = $this->stock();

        foreach ([Role::LOGISTICS, Role::PRODUCTION, Role::WAREHOUSE_OPERATOR] as $slug) {
            $this->loginAs($slug);
            $this->post('/wms/inventory/adjust', [
                'stock_id' => $stock->id, 'qty_new' => 10, 'reason' => 'percobaan tidak sah',
            ])->assertForbidden();
        }

        $this->assertSame(180, $stock->fresh()->qty_available);
    }

    /**
     * Produksi HANYA MELIHAT; Operator Gudang boleh memindahkan antar rak.
     *
     * Keputusan pemilik produk: Operator-lah yang benar-benar mengangkat
     * barangnya, dan memaksa mereka memanggil Logistik hanya untuk mencatat
     * perpindahan yang sudah terjadi membuat sistem tertinggal dari kenyataan
     * di rak. Yang tetap tertutup bagi Operator adalah MENGUBAH JUMLAH —
     * lihat test_produksi_dan_operator_tidak_boleh_koreksi_qty().
     */
    public function test_produksi_tidak_boleh_transfer_tetapi_operator_boleh(): void
    {
        $stock = $this->stock();
        $this->bin('B-01-02');

        $this->loginAs(Role::PRODUCTION);
        $this->post('/wms/inventory/transfer', [
            'stock_id' => $stock->id, 'to_location_code' => 'B-01-02',
            'qty' => 10, 'reason' => 'percobaan tidak sah',
        ])->assertForbidden();

        $this->loginAs(Role::WAREHOUSE_OPERATOR);
        $this->post('/wms/inventory/transfer', [
            'stock_id' => $stock->id, 'to_location_code' => 'B-01-02',
            'qty' => 10, 'reason' => 'merapikan rak',
        ])->assertSessionHas('success');
    }

    public function test_produksi_dan_operator_tidak_boleh_koreksi_qty(): void
    {
        $stock = $this->stock();

        foreach ([Role::PRODUCTION, Role::WAREHOUSE_OPERATOR] as $slug) {
            $this->loginAs($slug);
            $this->post('/wms/inventory/adjust', [
                'stock_id' => $stock->id, 'qty_new' => 999, 'reason' => 'percobaan tidak sah',
            ])->assertForbidden();
        }
    }

    /* --------------------------------------------------------------- Daftar */

    /**
     * Satu SKU = SATU baris accordion, isinya batch-batchnya (docs/4 §4.3.9).
     *
     * Inilah yang membuat halaman tetap terbaca: satu SKU dengan lima palet
     * tidak lagi menghabiskan lima baris layar.
     */
    public function test_satu_sku_jadi_satu_baris_dengan_batch_di_dalamnya(): void
    {
        $this->loginAs();
        $produk = Product::factory()->create(['uom' => 'TIN']);
        $this->stock(['product_id' => $produk->id, 'batch_no' => 'BATCH-A', 'qty_available' => 100]);
        $this->stock(['product_id' => $produk->id, 'batch_no' => 'BATCH-B', 'qty_available' => 80, 'location_id' => $this->bin('B-01-02')->id]);

        $baris = $this->get('/wms/inventory')->viewData('barisSku');

        $this->assertCount(1, $baris, 'Dua batch dari SKU yang sama harus jadi satu baris.');
        $this->assertSame($produk->id, $baris[0]['product']->id);

        // Batch TETAP tidak dilebur di dalam blok — FIFO menuntut tiap batch
        // punya tanggal produksi dan kedaluwarsanya sendiri.
        $this->assertCount(2, $baris[0]['good']);
        $this->assertSame(180, $baris[0]['total_good']);
    }

    /**
     * Isi accordion terbagi dua blok: Good Stock dan DDP (docs/4 §4.3.9).
     *
     * Batch rusak/kedaluwarsa tidak boleh berdampingan dengan yang layak jual
     * dalam satu daftar — di situlah salah ambil barang bermula.
     */
    public function test_accordion_memisahkan_blok_good_stock_dan_ddp(): void
    {
        $this->loginAs();
        $produk = Product::factory()->create(['uom' => 'TIN']);
        $this->stock(['product_id' => $produk->id, 'batch_no' => 'BATCH-BAIK', 'qty_available' => 100]);
        $this->stock([
            'product_id' => $produk->id, 'batch_no' => 'BATCH-RUSAK', 'qty_available' => 20,
            'location_id' => $this->bin('B-01-02')->id,
        ])->update(['status' => InventoryStock::STATUS_DDP, 'ddp_reason' => InventoryStock::DDP_RETURN_DAMAGED]);

        $baris = $this->get('/wms/inventory')->viewData('barisSku');

        $this->assertCount(1, $baris);
        $this->assertSame(['BATCH-BAIK'], $baris[0]['good']->pluck('batch_no')->all());
        $this->assertSame(['BATCH-RUSAK'], $baris[0]['ddp']->pluck('batch_no')->all());
        $this->assertSame(100, $baris[0]['total_good']);
        $this->assertSame(20, $baris[0]['total_ddp']);
    }

    /**
     * Blok DDP tetap dirender meski kosong (docs/4 §4.3.9).
     *
     * Ketiadaan stok rusak harus terbaca sebagai informasi, bukan sebagai
     * data yang gagal dimuat.
     */
    public function test_blok_ddp_tetap_tampil_walau_kosong(): void
    {
        $this->loginAs();
        $this->stock();

        $this->get('/wms/inventory')
            ->assertOk()
            ->assertSee('STOK DDP (Rusak / Karantina / Expired)')
            ->assertSee('Tidak ada stok DDP untuk SKU ini.');
    }

    /** Batch kedaluwarsa hasil sweep ikut muncul di blok DDP, bukan hilang. */
    public function test_batch_kedaluwarsa_muncul_di_blok_ddp(): void
    {
        $this->loginAs();
        $this->stock([
            'batch_no' => 'BATCH-EXP',
            'production_date' => now()->subYears(3)->toDateString(),
            'expiry_date' => now()->subMonth()->toDateString(),
        ])->update(['status' => InventoryStock::STATUS_EXPIRED, 'ddp_reason' => InventoryStock::DDP_EXPIRED]);

        $baris = $this->get('/wms/inventory')->viewData('barisSku');

        $this->assertSame(['BATCH-EXP'], $baris[0]['ddp']->pluck('batch_no')->all());
        $this->assertTrue($baris[0]['good']->isEmpty());
    }

    public function test_ringkasan_memisahkan_good_stock_dan_ddp(): void
    {
        $this->loginAs();
        $this->stock(['qty_available' => 100, 'qty_allocated' => 30]);
        $this->stock(['qty_available' => 50, 'location_id' => $this->bin('B-01-02')->id])->update([
            'status' => InventoryStock::STATUS_DDP,
            'ddp_reason' => InventoryStock::DDP_WRITE_OFF,
        ]);

        $stats = $this->get('/wms/inventory')->viewData('stats');

        $this->assertSame(100, $stats['good']);
        $this->assertSame(30, $stats['dialokasikan']);
        $this->assertSame(50, $stats['ddp']);
    }

    public function test_filter_status_memisahkan_ddp(): void
    {
        $this->loginAs();
        $this->stock();
        InventoryStock::factory()->ddp()->create([
            'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->bin('B-01-02')->id,
            'product_id' => Product::factory()->create()->id,
        ]);

        $batch = $this->batchDiLayar('/wms/inventory?status='.InventoryStock::STATUS_DDP);

        $this->assertCount(1, $batch);
        $this->assertSame(InventoryStock::STATUS_DDP, $batch->first()->status);
    }

    public function test_filter_hampir_kedaluwarsa(): void
    {
        $this->loginAs();
        $this->stock(['expiry_date' => now()->addDays(30)->toDateString()]);
        $this->stock([
            'expiry_date' => now()->addYears(2)->toDateString(),
            'location_id' => $this->bin('B-01-02')->id,
        ]);

        $this->assertCount(1, $this->batchDiLayar('/wms/inventory?expiring=1'));
    }

    public function test_pencarian_lewat_batch_dan_sku(): void
    {
        $this->loginAs();
        $produk = Product::factory()->create(['sku' => 'ID1-FTESTSKU']);
        $this->stock(['product_id' => $produk->id, 'batch_no' => 'BATCH-CARI']);
        $this->stock(['batch_no' => 'BATCH-LAIN', 'location_id' => $this->bin('B-01-02')->id]);

        $this->assertCount(1, $this->batchDiLayar('/wms/inventory?search=BATCH-CARI'));
        $this->assertCount(1, $this->batchDiLayar('/wms/inventory?search=ID1-FTESTSKU'));
    }

    /** SKU yang salah satu batch-nya paling dekat kedaluwarsa tampil lebih dulu. */
    public function test_diurutkan_dari_yang_paling_mendesak(): void
    {
        $this->loginAs();
        $this->stock(['expiry_date' => now()->addYears(2)->toDateString(), 'batch_no' => 'MASIH-LAMA']);
        $this->stock([
            'expiry_date' => now()->addDays(10)->toDateString(),
            'batch_no' => 'HAMPIR-HABIS',
            'location_id' => $this->bin('B-01-02')->id,
        ]);

        $baris = $this->get('/wms/inventory')->viewData('barisSku');

        $this->assertSame('HAMPIR-HABIS', $baris[0]['good']->first()->batch_no);
    }

    /* -------------------------------------------------- Umur simpan di layar */

    public function test_sisa_umur_simpan_tampil_dalam_bulan_dan_minggu(): void
    {
        $this->loginAs();
        $this->stock(['expiry_date' => now()->addMonths(6)->toDateString()]);

        $this->get('/wms/inventory')->assertOk()->assertSee('6 bln 0 minggu');
    }

    /* ------------------------------------------------ Aturan boleh dijual */

    /**
     * scopeSellable menyaring status DAN tanggal sekaligus.
     *
     * Batch yang kedaluwarsa hari ini masih berstatus 'active' sampai sweep
     * harian jalan; kalau hanya status yang disaring, barang kedaluwarsa bisa
     * ikut teralokasi pada sela itu.
     */
    public function test_stok_kedaluwarsa_hari_ini_tidak_boleh_dijual(): void
    {
        $this->stock(['expiry_date' => now()->toDateString()]);
        $this->stock([
            'expiry_date' => now()->addDay()->toDateString(),
            'location_id' => $this->bin('B-01-02')->id,
        ]);

        $this->assertSame(1, InventoryStock::query()->sellable()->count());
    }

    public function test_stok_ddp_tidak_pernah_boleh_dijual(): void
    {
        InventoryStock::factory()->ddp()->create([
            'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->bin('B-01-01')->id,
            'product_id' => Product::factory()->create()->id,
            'expiry_date' => now()->addYears(2)->toDateString(),
        ]);

        $this->assertSame(0, InventoryStock::query()->sellable()->count());
    }

    public function test_urutan_fifo_mengambil_yang_tertua(): void
    {
        $produk = Product::factory()->create();
        $this->stock(['product_id' => $produk->id, 'production_date' => '2026-05-01', 'batch_no' => 'BARU']);
        $this->stock([
            'product_id' => $produk->id, 'production_date' => '2026-01-01',
            'batch_no' => 'TERTUA', 'location_id' => $this->bin('B-01-02')->id,
        ]);

        $urutan = InventoryStock::query()->urutanKeluar()->pluck('batch_no')->all();

        $this->assertSame(['TERTUA', 'BARU'], $urutan);
    }

    /* ------------------------------------------------------------- Koreksi */

    public function test_koreksi_mencatat_ledger_dengan_alasan(): void
    {
        $manager = $this->loginAs();
        $stock = $this->stock(['qty_available' => 180]);

        $this->post('/wms/inventory/adjust', [
            'stock_id' => $stock->id,
            'qty_new' => 178,
            'reason' => 'Hasil stocktake 31 Agu 2026, 2 pail rusak saat penurunan.',
        ])->assertSessionHas('success');

        $this->assertSame(178, $stock->fresh()->qty_available);

        $ledger = StockMovement::latest('id')->first();
        $this->assertSame(StockMovement::TYPE_ADJUSTMENT, $ledger->movement_type);
        $this->assertSame(-2, $ledger->qty_change);
        $this->assertSame(180, $ledger->qty_before);
        $this->assertSame(178, $ledger->qty_after);
        $this->assertSame($manager->id, $ledger->user_id);
        $this->assertStringContainsString('stocktake', $ledger->notes);
    }

    public function test_koreksi_tanpa_alasan_ditolak(): void
    {
        $this->loginAs();
        $stock = $this->stock();

        $this->post('/wms/inventory/adjust', [
            'stock_id' => $stock->id, 'qty_new' => 100, 'reason' => '',
        ])->assertSessionHasErrors('reason');

        $this->assertSame(180, $stock->fresh()->qty_available);
    }

    /**
     * Stok yang sudah dikunci untuk order tidak boleh hilang lewat koreksi —
     * kalau boleh, order yang sudah disetujui mendadak tidak punya barang.
     */
    public function test_koreksi_tidak_boleh_di_bawah_qty_teralokasi(): void
    {
        $this->loginAs();
        $stock = $this->stock(['qty_available' => 180, 'qty_allocated' => 50]);

        $this->post('/wms/inventory/adjust', [
            'stock_id' => $stock->id, 'qty_new' => 30,
            'reason' => 'Percobaan mengurangi di bawah alokasi.',
        ])->assertSessionHas('error');

        $this->assertSame(180, $stock->fresh()->qty_available);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_koreksi_bisa_menandai_ddp(): void
    {
        $this->loginAs();
        $stock = $this->stock();

        $this->post('/wms/inventory/adjust', [
            'stock_id' => $stock->id,
            'qty_new' => 180,
            'ddp_reason' => InventoryStock::DDP_WRITE_OFF,
            'reason' => 'Kemasan penyok, tidak layak jual.',
        ])->assertSessionHas('success');

        $stock->refresh();
        $this->assertSame(InventoryStock::STATUS_DDP, $stock->status);
        $this->assertSame(InventoryStock::DDP_WRITE_OFF, $stock->ddp_reason);
        // Setelah ditandai DDP, stok ini hilang dari yang boleh dijual.
        $this->assertSame(0, InventoryStock::query()->sellable()->count());
    }

    /* ------------------------------------------------------------ Transfer */

    public function test_transfer_mencatat_pasangan_out_dan_in(): void
    {
        $this->loginAs();
        $asal = $this->stock(['qty_available' => 180]);
        $tujuan = $this->bin('B-01-05');

        $this->post('/wms/inventory/transfer', [
            'stock_id' => $asal->id,
            'to_location_code' => 'B-01-05',
            'qty' => 60,
            'reason' => 'Konsolidasi agar rak B-01-01 bisa dikosongkan.',
        ])->assertSessionHas('success');

        $this->assertSame(120, $asal->fresh()->qty_available);

        $stokTujuan = InventoryStock::where('location_id', $tujuan->id)->first();
        $this->assertSame(60, $stokTujuan->qty_available);

        // Batch, tanggal produksi & kedaluwarsa IKUT PINDAH apa adanya —
        // membuat batch baru akan merusak FIFO dan perhitungan kedaluwarsa.
        $this->assertSame($asal->batch_no, $stokTujuan->batch_no);
        $this->assertSame(
            $asal->production_date->toDateString(),
            $stokTujuan->production_date->toDateString()
        );
        $this->assertSame(
            $asal->expiry_date->toDateString(),
            $stokTujuan->expiry_date->toDateString()
        );

        $gerakan = StockMovement::orderBy('id')->get();
        $this->assertCount(2, $gerakan);
        $this->assertSame(StockMovement::TYPE_TRANSFER_OUT, $gerakan[0]->movement_type);
        $this->assertSame(StockMovement::TYPE_TRANSFER_IN, $gerakan[1]->movement_type);
        // Total qty_change harus NOL: transfer tidak menciptakan/menghilangkan stok.
        $this->assertSame(0, $gerakan->sum('qty_change'));
    }

    public function test_transfer_melebihi_stok_tersedia_ditolak(): void
    {
        $this->loginAs();
        $asal = $this->stock(['qty_available' => 50]);
        $this->bin('B-01-05');

        $this->post('/wms/inventory/transfer', [
            'stock_id' => $asal->id, 'to_location_code' => 'B-01-05',
            'qty' => 80, 'reason' => 'Melebihi stok yang ada.',
        ])->assertSessionHas('error');

        $this->assertSame(50, $asal->fresh()->qty_available);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_transfer_ke_rak_tidak_dikenal_ditolak(): void
    {
        $this->loginAs();
        $asal = $this->stock();

        $this->post('/wms/inventory/transfer', [
            'stock_id' => $asal->id, 'to_location_code' => 'Z-99-99',
            'qty' => 10, 'reason' => 'Rak tidak ada.',
        ])->assertSessionHas('error');

        $this->assertSame(0, StockMovement::count());
    }

    public function test_transfer_ke_rak_yang_sama_ditolak(): void
    {
        $this->loginAs();
        $asal = $this->stock();

        $this->post('/wms/inventory/transfer', [
            'stock_id' => $asal->id, 'to_location_code' => 'B-01-01',
            'qty' => 10, 'reason' => 'Tujuan sama dengan asal.',
        ])->assertSessionHas('error');

        $this->assertSame(0, StockMovement::count());
    }

    /* -------------------------------------------------------------- Ledger */

    /** Ledger adalah jejak audit keuangan — tidak boleh diubah. */
    public function test_ledger_tidak_bisa_diubah(): void
    {
        $this->loginAs();
        $stock = $this->stock();
        $this->post('/wms/inventory/adjust', [
            'stock_id' => $stock->id, 'qty_new' => 100, 'reason' => 'Koreksi stocktake.',
        ]);

        $ledger = StockMovement::first();

        $this->expectException(RuntimeException::class);
        $ledger->update(['qty_change' => 999]);
    }

    public function test_ledger_tidak_bisa_dihapus(): void
    {
        $this->loginAs();
        $stock = $this->stock();
        $this->post('/wms/inventory/adjust', [
            'stock_id' => $stock->id, 'qty_new' => 100, 'reason' => 'Koreksi stocktake.',
        ]);

        $ledger = StockMovement::first();

        $this->expectException(RuntimeException::class);
        $ledger->delete();
    }

    /* --------------------------------------------------------- Sweep expiry */

    public function test_sweep_memindahkan_batch_kedaluwarsa(): void
    {
        $kedaluwarsa = $this->stock(['expiry_date' => now()->subDay()->toDateString()]);
        $masihSegar = $this->stock([
            'expiry_date' => now()->addYear()->toDateString(),
            'location_id' => $this->bin('B-01-02')->id,
        ]);

        $this->artisan('stock:sweep-expired')->assertSuccessful();

        $this->assertSame(InventoryStock::STATUS_EXPIRED, $kedaluwarsa->fresh()->status);
        $this->assertSame(InventoryStock::DDP_EXPIRED, $kedaluwarsa->fresh()->ddp_reason);
        $this->assertSame(InventoryStock::STATUS_ACTIVE, $masihSegar->fresh()->status);

        // Qty TIDAK berubah: barangnya masih di rak, hanya tidak boleh dijual.
        $this->assertSame(180, $kedaluwarsa->fresh()->qty_available);

        $ledger = StockMovement::first();
        $this->assertSame(StockMovement::TYPE_ADJUSTMENT, $ledger->movement_type);
        $this->assertSame(0, $ledger->qty_change);
        $this->assertNull($ledger->user_id);
        $this->assertStringContainsString('EXPIRED', $ledger->notes);
    }

    public function test_sweep_dry_run_tidak_mengubah_apa_pun(): void
    {
        $stock = $this->stock(['expiry_date' => now()->subDay()->toDateString()]);

        $this->artisan('stock:sweep-expired --dry-run')->assertSuccessful();

        $this->assertSame(InventoryStock::STATUS_ACTIVE, $stock->fresh()->status);
        $this->assertSame(0, StockMovement::count());
    }

    /* ------------------------------------------- Terhubung ke verifikasi 3c */

    /**
     * Utang Fase 3c dilunasi: verifikasi Logistik benar-benar mengaktifkan
     * stok, bukan sekadar menandai palet.
     */
    public function test_verifikasi_inbound_mengaktifkan_stok(): void
    {
        $logistik = $this->loginAs(Role::LOGISTICS);

        $produk = Product::factory()->create(['max_qty_per_pallet' => 180, 'uom' => 'TIN', 'shelf_life_months' => 30]);
        $bin = $this->bin('B-01-01');
        $header = InboundHeader::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'document_number' => 'IN-260901-001',
            'status' => InboundHeader::STATUS_VERIFICATION_PENDING,
            'production_date' => '2026-01-15',
        ]);
        $palet = InboundDetail::factory()->create([
            'inbound_header_id' => $header->id,
            'product_id' => $produk->id,
            'batch_no' => 'I126080071',
            'pallet_no' => 1,
            'pallet_qty' => 180,
            'total_qty' => 180,
            'location_id' => $bin->id,
            'qty_actual' => 178,
        ]);

        $this->post('/wms/inbound/verify/IN-260901-001', [
            'pallets' => [$palet->id => ['verified' => 1, 'location_code' => 'B-01-01', 'qty_actual' => 178]],
        ])->assertSessionDoesntHaveErrors();

        $stock = InventoryStock::first();
        $this->assertNotNull($stock, 'Verifikasi harus membuat baris inventory_stocks.');
        $this->assertSame(178, $stock->qty_available);
        $this->assertSame('I126080071', $stock->batch_no);
        $this->assertSame($bin->id, $stock->location_id);
        $this->assertSame($logistik->id, $stock->verified_by);

        // expiry = production_date + shelf_life_months (PRD §7.2.1).
        $this->assertSame('2028-07-15', $stock->expiry_date->toDateString());

        $ledger = StockMovement::first();
        $this->assertSame(StockMovement::TYPE_IN, $ledger->movement_type);
        $this->assertSame(178, $ledger->qty_change);
        $this->assertSame(0, $ledger->qty_before);
        $this->assertSame(StockMovement::REF_INBOUND, $ledger->reference_type);
    }

    /* ------------------------------------------- Tambah stok & baris kosong */

    /**
     * GUDANG DIPILIH, TIDAK DISIMPULKAN DARI KODE RAK.
     *
     * Kode rak TIDAK unik antar gudang. Sebelum perbaikan ini, "A-01-02"
     * dicari tanpa menyebut gudangnya dan yang menang adalah baris pertama
     * yang ditemukan — sehingga stok bisa mendarat di gudang yang sama sekali
     * tidak dimaksud, tanpa satu pun pesan galat.
     */
    public function test_tambah_stok_memakai_rak_di_gudang_yang_dipilih(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        $lain = Warehouse::factory()->create(['code' => 'WH-99']);
        $parts = Location::parseCode('A-01-02');

        // Rak berkode SAMA di dua gudang. Yang di gudang lain sengaja dibuat
        // LEBIH DULU supaya ia yang menang kalau gudangnya tidak dijepit.
        $rakGudangLain = Location::create([
            'warehouse_id' => $lain->id, 'code' => 'A-01-02',
            'rack' => $parts['rack'], 'level' => $parts['level'], 'cell' => $parts['cell'],
            'zone' => Location::ZONE_FAST, 'is_active' => true,
        ]);
        $rakGudangSaya = $this->bin('A-01-02');

        $produk = Product::factory()->create(['sku' => 'ID1-UJI-001', 'is_active' => true]);

        $this->post(route('wms.inventory.store'), [
            'warehouse_id' => $this->warehouse->id,
            'sku' => 'ID1-UJI-001',
            'location_code' => 'A-01-02',
            'batch_no' => 'BT-UJI-001',
            'production_date' => now()->subMonth()->toDateString(),
            'qty' => 12,
            'reason' => 'Stocktake, barang sudah di rak.',
        ])->assertSessionHas('success');

        $stok = InventoryStock::where('product_id', $produk->id)->first();

        $this->assertNotNull($stok);
        $this->assertSame($rakGudangSaya->id, $stok->location_id,
            'Rak dicari DI DALAM gudang yang dipilih, bukan yang pertama ketemu.');
        $this->assertSame($this->warehouse->id, $stok->warehouse_id);
        $this->assertNotSame($rakGudangLain->id, $stok->location_id);
    }

    public function test_tambah_stok_ditolak_bila_rak_tidak_ada_di_gudang_yang_dipilih(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        $lain = Warehouse::factory()->create(['code' => 'WH-99']);
        $parts = Location::parseCode('A-09-09');
        Location::create([
            'warehouse_id' => $lain->id, 'code' => 'A-09-09',
            'rack' => $parts['rack'], 'level' => $parts['level'], 'cell' => $parts['cell'],
            'zone' => Location::ZONE_FAST, 'is_active' => true,
        ]);

        Product::factory()->create(['sku' => 'ID1-UJI-002', 'is_active' => true]);

        // Raknya ADA, tapi di gudang lain. Dahulu ini akan berhasil dan
        // menaruh stoknya di gudang yang salah tanpa pesan apa pun.
        $this->post(route('wms.inventory.store'), [
            'warehouse_id' => $this->warehouse->id,
            'sku' => 'ID1-UJI-002',
            'location_code' => 'A-09-09',
            'batch_no' => 'BT-UJI-002',
            'production_date' => now()->subMonth()->toDateString(),
            'qty' => 12,
            'reason' => 'Stocktake, barang sudah di rak.',
        ])->assertSessionHasErrors('location_code');

        $this->assertSame(0, InventoryStock::count());
    }

    /**
     * Baris yang tersedia DAN teralokasi sama-sama nol bukan stok — ia sisa
     * batch yang habis atau seluruhnya dipindah ke rak lain.
     */
    public function test_baris_stok_kosong_tidak_ditampilkan(): void
    {
        $this->loginAs();
        $kosong = $this->stock(['qty_available' => 0, 'qty_allocated' => 0, 'batch_no' => 'BT-KOSONG']);
        $this->stock(['qty_available' => 5, 'qty_allocated' => 0, 'batch_no' => 'BT-ISI']);

        $terlihat = $this->batchDiLayar()->pluck('batch_no');

        $this->assertTrue($terlihat->contains('BT-ISI'));
        $this->assertFalse($terlihat->contains('BT-KOSONG'), 'Rak kosong tidak boleh terbaca sebagai berisi.');
        $this->assertDatabaseHas('inventory_stocks', ['id' => $kosong->id]);
    }

    public function test_baris_yang_habis_dicadangkan_tetap_ditampilkan(): void
    {
        $this->loginAs();
        $this->stock(['qty_available' => 0, 'qty_allocated' => 20, 'batch_no' => 'BT-DICADANGKAN']);

        $terlihat = $this->batchDiLayar()->pluck('batch_no');

        $this->assertTrue($terlihat->contains('BT-DICADANGKAN'),
            'Barangnya masih berdiri di rak. Menyembunyikannya membuat operator picking mencari barang yang menurut layar tidak ada.');
    }

    /* ----------------------------------------------- Export Excel (F-INV-01) */

    /**
     * Tombolnya mengarah ke PRATINJAU laporan, bukan langsung mengunduh.
     *
     * Alurnya sengaja sama dengan kartu Pergerakan Stok di menu Laporan:
     * lihat dulu baris pertama beserta jumlah baris sebenarnya, baru tekan
     * unduh. Mengunduh dengan mata tertutup lalu mendapati isinya kosong atau
     * salah gudang adalah putaran yang mahal — apalagi kalau berkasnya
     * terlanjur diteruskan ke orang lain.
     */
    public function test_tombol_export_mengarah_ke_pratinjau_laporan_yang_sudah_ada(): void
    {
        $this->loginAs(Role::MANAGER);

        $html = $this->get('/wms/inventory')->assertOk()->getContent();

        $this->assertStringContainsString(route('wms.reports.show', 'posisi-stok'), $html);
        $this->assertStringContainsString(route('wms.reports.show', 'pergerakan-stok'), $html);
    }

    /**
     * Melihat di layar dan membawa keluar satu berkas adalah dua hal berbeda.
     *
     * Produksi dan Operator Gudang memang harus bisa membuka halaman ini —
     * mereka mengecek lokasi saat put-away dan picking. Tetapi pintu yang
     * memuntahkan seluruh isi gudang dalam satu berkas yang bisa diteruskan
     * ke mana saja tetap tertutup bagi mereka, sama seperti di menu Laporan.
     */
    public function test_produksi_dan_operator_melihat_stok_tanpa_tombol_export(): void
    {
        $this->stock(['batch_no' => 'BT-RAHASIA']);

        foreach ([Role::PRODUCTION, Role::WAREHOUSE_OPERATOR] as $slug) {
            $this->loginAs($slug);

            $html = $this->get('/wms/inventory')->assertOk()->getContent();

            $this->assertStringNotContainsString('Export Excel', $html);
            $this->assertStringNotContainsString(route('wms.reports.show', 'posisi-stok'), $html);

            // Bukan sekadar tombolnya disembunyikan — pintunya memang tertutup.
            $this->get(route('wms.reports.show', 'posisi-stok'))->assertForbidden();
        }
    }

    /**
     * Gudang yang sedang dipilih ikut terbawa ke pratinjau.
     *
     * Super Admin yang sedang menyaring satu gudang lalu menekan Export tidak
     * boleh mendarat di pratinjau seluruh gudang — ia akan mengunduhnya tanpa
     * menyadari bahwa penyaringnya sudah hilang di tengah jalan.
     */
    public function test_gudang_yang_dipilih_ikut_ke_tautan_pratinjau(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        $html = $this->get('/wms/inventory?warehouse_id='.$this->warehouse->id)->assertOk()->getContent();

        $this->assertStringContainsString(
            route('wms.reports.show', ['key' => 'posisi-stok', 'warehouse_id' => $this->warehouse->id]),
            $html,
        );
    }

    /* ------------------------------------------- Angka lintas gudang */

    /**
     * Satu baris = satu SKU, dan bagi akun lintas gudang itu berarti angkanya
     * MENJUMLAHKAN GUDANG YANG BERBEDA.
     *
     * Pernah terbaca sebagai selisih stocktake: layar ini menunjukkan 234
     * sementara laporan stocktake Karawang menyebut 55 — padahal 180 di
     * antaranya sudah dipindah ke gudang lain berbulan-bulan sebelumnya dan
     * stocktake-nya benar. Kode gudangnya sendiri nyaris kembar, jadi label
     * kecil di tiap baris batch tidak cukup: yang dibaca lebih dulu adalah
     * angka besar di kepala baris.
     */
    public function test_total_sku_menyebutkan_rinciannya_saat_lintas_gudang(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        $produk = Product::factory()->create(['sku' => 'SKU-LINTAS', 'uom' => 'TIN']);
        $lain = Warehouse::factory()->create(['code' => 'WH-99']);

        $this->stock(['product_id' => $produk->id, 'batch_no' => 'BT-SINI', 'qty_available' => 54]);

        InventoryStock::factory()->create([
            'warehouse_id' => $lain->id,
            'location_id' => Location::factory()->create(['warehouse_id' => $lain->id])->id,
            'product_id' => $produk->id,
            'batch_no' => 'BT-SANA',
            'qty_available' => 180,
        ]);

        $baris = $this->get('/wms/inventory')->viewData('barisSku')->first();

        $this->assertSame(234, $baris['total_good'], 'Totalnya memang gabungan — itu tidak diubah.');
        $this->assertSame(['WH-01' => 54, 'WH-99' => 180], $baris['per_gudang']->all());
    }

    /** Satu gudang saja tidak perlu rincian — lencananya cuma jadi kebisingan. */
    public function test_sku_satu_gudang_tidak_perlu_rincian(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        $produk = Product::factory()->create(['sku' => 'SKU-SATU-GUDANG', 'uom' => 'TIN']);
        $this->stock(['product_id' => $produk->id, 'qty_available' => 54]);

        $baris = $this->get('/wms/inventory')->viewData('barisSku')->first();

        $this->assertCount(1, $baris['per_gudang']);
    }
}
