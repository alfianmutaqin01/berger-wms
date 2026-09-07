<?php

namespace Tests\Feature\Wms;

use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Inventory\StockTakeRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Stok opname — mencocokkan angka sistem dengan barang di rak.
 *
 * EMPAT HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * ---------------------------------------------------
 * 1. MENGHITUNG TIDAK MENGUBAH STOK. Selama sesi berjalan, angka gudang tidak
 *    boleh bergeser satu unit pun; yang mengubahnya hanya pengesahan laporan.
 * 2. KOREKSINYA SELISIH, BUKAN PENIMPAAN. Barang yang sah berangkat SETELAH
 *    raknya dihitung tidak boleh dihidupkan kembali oleh laporan opname —
 *    inilah yang membuat opname tidak perlu membekukan operasi gudang, dan
 *    yang paling mudah dirusak oleh "sederhanakan saja jadi set qty".
 * 3. RAK YANG TIDAK DIHITUNG TIDAK DISENTUH. Menganggapnya kosong berarti satu
 *    rak yang terlewat langsung menghapus stoknya dari sistem.
 * 4. YANG MENGHITUNG BUKAN YANG MENGESAHKAN.
 */
class StockTakeTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $gudang;

    private Location $rak;

    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->create(['code' => 'WH-01', 'name' => 'Karawang']);
        $this->rak = Location::factory()->create([
            'warehouse_id' => $this->gudang->id,
            'code' => 'B-01-01', 'rack' => 'B-01', 'level' => 1, 'cell' => 1,
            'zone' => Location::ZONE_FAST,
        ]);
        $this->produk = Product::factory()->create([
            'sku' => 'APKO-001', 'name' => 'Bocor Guard 2 Base 1Kg', 'uom' => 'TIN', 'is_active' => true,
        ]);
    }

    private function loginAs(string $slug = Role::MANAGER): User
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
        $this->withCredentials();
        $this->actingAs($user);

        return $user;
    }

    private function stok(int $tersedia, int $teralokasi = 0, ?Location $lokasi = null): InventoryStock
    {
        return InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->gudang->id,
            'location_id' => ($lokasi ?? $this->rak)->id,
            'batch_no' => 'BT-'.Str::random(4),
            'production_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYears(2)->toDateString(),
            'qty_available' => $tersedia,
            'qty_allocated' => $teralokasi,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);
    }

    private function bukaSesi(array $ubah = [])
    {
        return $this->post(route('wms.stocktake.store'), array_merge([
            'warehouse_id' => $this->gudang->id,
            'scope_type' => StockTake::SCOPE_WAREHOUSE,
        ], $ubah));
    }

    private function hitung(StockTakeItem $item, int $qty, ?string $catatan = null)
    {
        return $this->post(route('wms.stocktake.count', $item), [
            'qty_physical' => $qty,
            'count_note' => $catatan,
        ]);
    }

    /* ============================================================= Akses */

    public function test_operator_boleh_menghitung_tetapi_tidak_boleh_membuka_sesi(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi()->assertSessionHasNoErrors();

        $this->loginAs(Role::WAREHOUSE_OPERATOR);

        $this->get('/wms/stocktake')->assertOk();

        // Membuka sesi menggeser angka beku seluruh gudang — bukan wewenangnya.
        $this->bukaSesi()->assertForbidden();
    }

    public function test_operator_tidak_boleh_mengesahkan_laporan(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();
        $sesi = StockTake::firstOrFail();

        $this->loginAs(Role::WAREHOUSE_OPERATOR);

        $this->post(route('wms.stocktake.finalize', $sesi))->assertForbidden();
    }

    public function test_sales_tidak_boleh_membuka_stok_opname(): void
    {
        $this->loginAs(Role::SALES);

        $this->get('/wms/stocktake')->assertForbidden();
    }

    /* =========================================================== Pembukaan */

    public function test_membuka_sesi_membekukan_angka_sistem(): void
    {
        $this->loginAs();
        // Barang fisik di rak = tersedia + teralokasi. Yang dicadangkan tetap
        // berdiri di sana sampai operator mengambilnya.
        $this->stok(30, 12);

        $this->bukaSesi()->assertSessionHasNoErrors();

        $item = StockTakeItem::firstOrFail();

        $this->assertSame(42, $item->qty_system);
        $this->assertNull($item->qty_physical, 'Belum dihitung, bukan nol.');
    }

    public function test_dua_sesi_berjalan_di_satu_gudang_ditolak(): void
    {
        $this->loginAs();
        $this->stok(10);

        $this->bukaSesi()->assertSessionHasNoErrors();
        $this->bukaSesi()->assertSessionHas('error');

        $this->assertSame(1, StockTake::count());
    }

    public function test_cakupan_zona_hanya_memuat_rak_zona_itu(): void
    {
        $this->loginAs();

        $rakLambat = Location::factory()->create([
            'warehouse_id' => $this->gudang->id,
            'code' => 'C-01-01', 'rack' => 'C-01', 'level' => 1, 'cell' => 1,
            'zone' => Location::ZONE_SLOW,
        ]);

        $this->stok(10);
        $this->stok(20, 0, $rakLambat);

        $this->bukaSesi([
            'scope_type' => StockTake::SCOPE_ZONE,
            'scope_value' => Location::ZONE_FAST,
        ])->assertSessionHasNoErrors();

        $items = StockTakeItem::all();

        $this->assertCount(1, $items);
        $this->assertSame($this->rak->id, $items[0]->location_id);
    }

    public function test_cakupan_tanpa_rak_yang_cocok_ditolak(): void
    {
        $this->loginAs();
        $this->stok(10);

        $this->bukaSesi([
            'scope_type' => StockTake::SCOPE_RACK,
            'scope_value' => 'Z-99',
        ])->assertSessionHas('error');

        $this->assertSame(0, StockTake::count());
    }

    /* =========================================================== Menghitung */

    /** Inti janjinya: menghitung TIDAK menggeser angka gudang. */
    public function test_menghitung_tidak_mengubah_stok(): void
    {
        $this->loginAs();
        $stok = $this->stok(30);
        $this->bukaSesi();

        $this->hitung(StockTakeItem::firstOrFail(), 25)->assertSessionHasNoErrors();

        $this->assertSame(30, $stok->fresh()->qty_available, 'Stok belum boleh bergerak.');
        $this->assertSame(-5, StockTakeItem::firstOrFail()->selisih);
        $this->assertSame(0, StockMovement::count(), 'Belum ada mutasi apa pun.');
    }

    /** Nol berarti "sudah dicek, memang kosong" — bukan "belum dihitung". */
    public function test_hitungan_nol_dianggap_sudah_dihitung(): void
    {
        $this->loginAs();
        $this->stok(8);
        $this->bukaSesi();

        $this->hitung(StockTakeItem::firstOrFail(), 0)->assertSessionHasNoErrors();

        $item = StockTakeItem::firstOrFail();

        $this->assertTrue($item->sudahDihitung());
        $this->assertSame(-8, $item->selisih);
    }

    /**
     * Hitungan di bawah jumlah yang sudah dicadangkan ditolak.
     *
     * Kekurangan sebanyak itu menyentuh barang yang SUDAH DIJANJIKAN ke
     * pelanggan, dan itu keputusan orang — bukan sesuatu yang boleh
     * diselesaikan diam-diam oleh pengesahan laporan.
     */
    public function test_hitungan_di_bawah_jumlah_teralokasi_ditolak(): void
    {
        $this->loginAs();
        $this->stok(4, 6);
        $this->bukaSesi();

        $this->hitung(StockTakeItem::firstOrFail(), 3)->assertSessionHas('error');

        $this->assertNull(StockTakeItem::firstOrFail()->qty_physical);
    }

    public function test_hitungan_tidak_bisa_diubah_setelah_sesi_disahkan(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $item = StockTakeItem::firstOrFail();
        $this->hitung($item, 10);
        $this->post(route('wms.stocktake.finalize', StockTake::firstOrFail()));

        $this->hitung($item, 99)->assertSessionHas('error');

        $this->assertSame(10, StockTakeItem::firstOrFail()->qty_physical);
    }

    /* =========================================================== Pengesahan */

    public function test_pengesahan_menerapkan_selisih_dan_mencatat_ledger(): void
    {
        $this->loginAs();
        $stok = $this->stok(30);
        $this->bukaSesi();

        $this->hitung(StockTakeItem::firstOrFail(), 25);
        $this->post(route('wms.stocktake.finalize', StockTake::firstOrFail()))
            ->assertSessionHasNoErrors();

        $this->assertSame(25, $stok->fresh()->qty_available);

        $mutasi = StockMovement::firstOrFail();

        $this->assertSame(StockMovement::TYPE_ADJUSTMENT, $mutasi->movement_type);
        $this->assertSame(-5, $mutasi->qty_change);
        $this->assertSame(30, $mutasi->qty_before);
        $this->assertSame(25, $mutasi->qty_after);

        $item = StockTakeItem::firstOrFail();

        $this->assertSame(-5, $item->applied_delta);
        $this->assertSame(25, $item->qty_after);
    }

    /**
     * YANG PALING MUDAH DIRUSAK: koreksinya SELISIH, bukan penimpaan.
     *
     * Rak dihitung 25 saat stok sistem 30. Sebelum laporannya disahkan, 10
     * unit berangkat lewat pengiriman yang sah, sehingga stok jadi 20. Yang
     * benar adalah 20 + (25-30) = 15 — bukan 25, yang akan menghidupkan
     * kembali barang yang sudah pergi.
     */
    public function test_barang_yang_berangkat_di_tengah_sesi_tidak_dihidupkan_kembali(): void
    {
        $this->loginAs();
        $stok = $this->stok(30);
        $this->bukaSesi();

        $this->hitung(StockTakeItem::firstOrFail(), 25);

        // Pengiriman yang sah terjadi setelah raknya dihitung.
        $stok->forceFill(['qty_available' => 20])->save();

        $this->post(route('wms.stocktake.finalize', StockTake::firstOrFail()))
            ->assertSessionHasNoErrors();

        $this->assertSame(15, $stok->fresh()->qty_available);
    }

    /** Rak yang belum sempat dihitung TIDAK disentuh sama sekali. */
    public function test_baris_yang_belum_dihitung_tidak_disentuh(): void
    {
        $this->loginAs();
        $dihitung = $this->stok(30);
        $dilewat = $this->stok(40);
        $this->bukaSesi();

        $item = StockTakeItem::where('inventory_stock_id', $dihitung->id)->firstOrFail();
        $this->hitung($item, 28);

        $this->post(route('wms.stocktake.finalize', StockTake::firstOrFail()))
            ->assertSessionHas('warning');

        $this->assertSame(28, $dihitung->fresh()->qty_available);
        $this->assertSame(40, $dilewat->fresh()->qty_available, 'Yang tidak dihitung tidak boleh bergeser.');

        $lewat = StockTakeItem::where('inventory_stock_id', $dilewat->id)->firstOrFail();

        $this->assertNull($lewat->applied_delta);
        $this->assertNull($lewat->qty_after);
    }

    public function test_baris_yang_cocok_tidak_menghasilkan_mutasi(): void
    {
        $this->loginAs();
        $this->stok(30);
        $this->bukaSesi();

        $this->hitung(StockTakeItem::firstOrFail(), 30);
        $this->post(route('wms.stocktake.finalize', StockTake::firstOrFail()));

        $this->assertSame(0, StockMovement::count(), 'Tidak ada yang berubah, jadi tidak ada yang dicatat.');
    }

    public function test_sesi_tidak_bisa_disahkan_dua_kali(): void
    {
        $this->loginAs();
        $stok = $this->stok(30);
        $this->bukaSesi();
        $sesi = StockTake::firstOrFail();

        $this->hitung(StockTakeItem::firstOrFail(), 25);
        $this->post(route('wms.stocktake.finalize', $sesi));
        $this->post(route('wms.stocktake.finalize', $sesi))->assertSessionHas('error');

        $this->assertSame(25, $stok->fresh()->qty_available, 'Selisihnya tidak boleh diterapkan dua kali.');
        $this->assertSame(1, StockMovement::count());
    }

    public function test_pembatalan_sesi_tidak_mengubah_stok(): void
    {
        $this->loginAs();
        $stok = $this->stok(30);
        $this->bukaSesi();
        $sesi = StockTake::firstOrFail();

        $this->hitung(StockTakeItem::firstOrFail(), 25);
        $this->post(route('wms.stocktake.cancel', $sesi))->assertSessionHasNoErrors();

        $this->assertSame(30, $stok->fresh()->qty_available);
        $this->assertSame(StockTake::STATUS_CANCELLED, $sesi->fresh()->status);
        // Hasil hitungannya sengaja disimpan: ia menentukan dari mana
        // penghitungan berikutnya mulai.
        $this->assertSame(25, StockTakeItem::firstOrFail()->qty_physical);
    }

    /* ============================================================ Laporan */

    public function test_laporan_memuat_sku_stok_sebelum_sesudah_dan_selisih(): void
    {
        $this->loginAs();
        $this->stok(30);
        $this->bukaSesi();

        $this->hitung(StockTakeItem::firstOrFail(), 25);
        $sesi = StockTake::firstOrFail();
        $this->post(route('wms.stocktake.finalize', $sesi));

        $this->get(route('wms.stocktake.report', $sesi))
            ->assertOk()
            ->assertSee('APKO-001')
            ->assertSee('Bocor Guard 2 Base 1Kg')
            ->assertViewHas('baris', function (array $baris) {
                return $baris[0]['sebelum'] === 30
                    && $baris[0]['sesudah'] === 25
                    && $baris[0]['selisih'] === -5;
            });
    }

    /** Satu SKU di banyak rak dijumlahkan — laporannya global, bukan per rak. */
    public function test_laporan_menjumlahkan_satu_sku_dari_banyak_rak(): void
    {
        $this->loginAs();

        $rakKedua = Location::factory()->create([
            'warehouse_id' => $this->gudang->id,
            'code' => 'B-01-02', 'rack' => 'B-01', 'level' => 1, 'cell' => 2,
            'zone' => Location::ZONE_FAST,
        ]);

        $this->stok(30);
        $this->stok(20, 0, $rakKedua);
        $this->bukaSesi();

        foreach (StockTakeItem::orderBy('id')->get() as $i => $item) {
            $this->hitung($item, $i === 0 ? 28 : 20);
        }

        $sesi = StockTake::firstOrFail();
        $this->post(route('wms.stocktake.finalize', $sesi));

        $this->get(route('wms.stocktake.report', $sesi))
            ->assertOk()
            ->assertViewHas('baris', function (array $baris) {
                return count($baris) === 1
                    && $baris[0]['sebelum'] === 50
                    && $baris[0]['sesudah'] === 48
                    && $baris[0]['selisih'] === -2;
            });
    }

    /**
     * Laporan sebelum pengesahan harus MENGAKU bahwa ia baru pratinjau.
     *
     * Lembar yang terlihat resmi padahal angkanya belum berlaku di gudang
     * adalah cara tercepat membuat dua sumber kebenaran.
     */
    public function test_laporan_sebelum_disahkan_ditandai_pratinjau(): void
    {
        $this->loginAs();
        $this->stok(30);
        $this->bukaSesi();
        $this->hitung(StockTakeItem::firstOrFail(), 25);

        $this->get(route('wms.stocktake.report', StockTake::firstOrFail()))
            ->assertOk()
            ->assertSee('baru pratinjau')
            ->assertSee('BELUM DISAHKAN');
    }

    public function test_laporan_menyebutkan_baris_yang_belum_dihitung(): void
    {
        $this->loginAs();
        $this->stok(30);
        $this->stok(40);
        $this->bukaSesi();

        $this->hitung(StockTakeItem::orderBy('id')->firstOrFail(), 30);
        $sesi = StockTake::firstOrFail();
        $this->post(route('wms.stocktake.finalize', $sesi));

        $this->get(route('wms.stocktake.report', $sesi))
            ->assertOk()
            ->assertSee('tidak dihitung')
            ->assertViewHas('ringkasan', fn (array $r) => $r['belum'] === 1 && $r['dihitung'] === 1);
    }

    public function test_sesi_gudang_lain_tidak_bisa_dibuka(): void
    {
        $lain = Warehouse::factory()->create(['code' => 'WH-02', 'name' => 'Pekanbaru']);
        $rakLain = Location::factory()->create([
            'warehouse_id' => $lain->id,
            'code' => 'D-01-01', 'rack' => 'D-01', 'level' => 1, 'cell' => 1,
        ]);

        // Sesi milik gudang lain, dibuat oleh akun lintas gudang.
        $superAdmin = User::factory()->withRole(Role::SUPER_ADMIN)->create(['warehouse_id' => null]);
        InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'warehouse_id' => $lain->id,
            'location_id' => $rakLain->id,
            'qty_available' => 10,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $sesiLain = app(StockTakeRun::class)->open(
            $lain, StockTake::SCOPE_WAREHOUSE, null, null, $superAdmin->id
        );

        // Manager Karawang mencoba membukanya.
        $this->loginAs(Role::MANAGER);

        $this->get(route('wms.stocktake.show', $sesiLain))->assertForbidden();
        $this->post(route('wms.stocktake.finalize', $sesiLain))->assertForbidden();
    }
}
