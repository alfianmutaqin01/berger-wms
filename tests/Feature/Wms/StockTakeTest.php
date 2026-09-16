<?php

namespace Tests\Feature\Wms;

use App\Models\ActivityLog;
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
use App\Support\Inventory\BatchProduksi;
use App\Support\Inventory\StockTakeRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * Stocktake — mencocokkan angka sistem dengan barang di rak.
 *
 * EMPAT HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * ---------------------------------------------------
 * 1. MENGHITUNG TIDAK MENGUBAH STOK. Selama sesi berjalan, angka gudang tidak
 *    boleh bergeser satu unit pun; yang mengubahnya hanya pengesahan laporan.
 * 2. KOREKSINYA SELISIH, BUKAN PENIMPAAN. Barang yang sah berangkat SETELAH
 *    raknya dihitung tidak boleh dihidupkan kembali oleh laporan stocktake —
 *    inilah yang membuat stocktake tidak perlu membekukan operasi gudang, dan
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

    public function test_sales_tidak_boleh_membuka_stok_stocktake(): void
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

    /*
    | Penyimpanan tanpa memuat ulang halaman.
    |
    | Satu sesi stocktake bisa berisi ribuan baris. Dengan submit biasa, orang
    | yang sudah menghitung sampai baris terakhir dilempar kembali ke puncak
    | halaman setiap kali satu centang ditekan. Layarnya mengirim lewat
    | fetch(), dan endpoint yang sama harus menjawab dua bentuk.
    */

    public function test_hitungan_lewat_json_menjawab_selisih_dan_ringkasan(): void
    {
        $this->loginAs();
        $this->stok(30);
        $this->stok(40);
        $this->bukaSesi();

        $item = StockTakeItem::orderBy('id')->firstOrFail();

        $this->postJson(route('wms.stocktake.count', $item), ['qty_physical' => 25])
            ->assertOk()
            ->assertJsonPath('qty_physical', 25)
            ->assertJsonPath('selisih', -5)
            // Kartu ringkas ikut dikirim supaya angkanya tidak diam-diam basi
            // sementara halamannya tidak pernah dimuat ulang.
            ->assertJsonPath('ringkasan.dihitung', 1)
            ->assertJsonPath('ringkasan.belum', 1)
            ->assertJsonPath('ringkasan.selisih', 1);
    }

    /** Penolakan aturan stocktake harus terbaca juga oleh layar yang memakai fetch. */
    public function test_penolakan_hitungan_lewat_json_menjawab_422_beserta_alasannya(): void
    {
        $this->loginAs();
        $this->stok(4, 6);
        $this->bukaSesi();

        $this->postJson(route('wms.stocktake.count', StockTakeItem::firstOrFail()), ['qty_physical' => 3])
            ->assertStatus(422)
            ->assertJsonPath('pesan', fn (?string $p) => $p !== null && str_contains($p, 'dicadangkan'));

        $this->assertNull(StockTakeItem::firstOrFail()->qty_physical);
    }

    /**
     * Formulirnya tetap formulir sungguhan.
     *
     * Kalau JavaScript-nya gagal dimuat, penghitungan harus tetap berjalan —
     * hanya kembali ke perilaku muat ulang. Operator yang berdiri di depan rak
     * dengan tombol yang tidak melakukan apa-apa adalah kegagalan yang paling
     * mahal di fitur ini.
     */
    public function test_submit_formulir_biasa_tetap_menyimpan_hitungan(): void
    {
        $this->loginAs();
        $this->stok(30);
        $this->bukaSesi();

        $this->hitung(StockTakeItem::firstOrFail(), 25)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(25, StockTakeItem::firstOrFail()->qty_physical);
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

    /* =========================================== Penyaring deret & cari SKU */

    private function rakDi(string $kode, string $deret, int $level = 1, int $cell = 1): Location
    {
        return Location::factory()->create([
            'warehouse_id' => $this->gudang->id,
            'code' => $kode, 'rack' => $deret, 'level' => $level, 'cell' => $cell,
            'zone' => Location::ZONE_FAST,
        ]);
    }

    /**
     * Tiga orang membagi deret. Yang kebagian deret C tidak perlu menggulir
     * melewati pekerjaan orang lain — di situ baris orang lain gampang terisi
     * tanpa sengaja.
     */
    public function test_penyaring_deret_hanya_menampilkan_deret_itu(): void
    {
        $this->loginAs();

        $this->stok(10, lokasi: $this->rakDi('B-02-01', 'B-02'));
        $this->stok(20, lokasi: $this->rakDi('C-01-01', 'C-01'));

        $this->bukaSesi();
        $sesi = StockTake::first();

        $deret = $this->get(route('wms.stocktake.show', $sesi).'?rak=C-01')
            ->assertOk()
            ->viewData('deret');

        $this->assertSame(['C-01'], $deret->keys()->all());
    }

    /** Satu SKU bisa tersebar di banyak palet dan banyak rak. */
    public function test_pencarian_sku_menyaring_lintas_deret(): void
    {
        $this->loginAs();

        $lain = Product::factory()->create(['sku' => 'ZZZ-999', 'name' => 'Produk Lain', 'uom' => 'TIN']);

        $this->stok(10, lokasi: $this->rakDi('B-02-01', 'B-02'));

        InventoryStock::factory()->create([
            'product_id' => $lain->id,
            'warehouse_id' => $this->gudang->id,
            'location_id' => $this->rakDi('C-01-01', 'C-01')->id,
            'batch_no' => 'BT-LAIN',
            'production_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYears(2)->toDateString(),
            'qty_available' => 5,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);

        $this->bukaSesi();
        $sesi = StockTake::first();

        $deret = $this->get(route('wms.stocktake.show', $sesi).'?q=ZZZ')
            ->assertOk()
            ->viewData('deret');

        $this->assertSame(['C-01'], $deret->keys()->all());
    }

    /**
     * Angka ringkas SELALU untuk seluruh sesi.
     *
     * "3 dari 3 baris dihitung" yang diam-diam berarti "3 dari 3 di deret B"
     * akan membuat orang menutup sesi yang belum selesai.
     */
    public function test_ringkasan_tidak_ikut_tersaring(): void
    {
        $this->loginAs();

        $this->stok(10, lokasi: $this->rakDi('B-02-01', 'B-02'));
        $this->stok(20, lokasi: $this->rakDi('C-01-01', 'C-01'));

        $this->bukaSesi();
        $sesi = StockTake::first();

        $ringkasan = $this->get(route('wms.stocktake.show', $sesi).'?rak=C-01')
            ->viewData('ringkasan');

        $this->assertSame(2, $ringkasan['baris'], 'Ringkasan menghitung seluruh sesi, bukan yang tampil.');
    }

    /** Pilihan deret dibaca dari seluruh sesi, bukan dari hasil yang tersaring. */
    public function test_daftar_deret_tidak_menyusut_saat_disaring(): void
    {
        $this->loginAs();

        $this->stok(10, lokasi: $this->rakDi('B-02-01', 'B-02'));
        $this->stok(20, lokasi: $this->rakDi('C-01-01', 'C-01'));

        $this->bukaSesi();
        $sesi = StockTake::first();

        $daftar = $this->get(route('wms.stocktake.show', $sesi).'?rak=C-01')
            ->viewData('daftarDeret');

        $this->assertEqualsCanonicalizing(['B-02', 'C-01'], $daftar->all());
    }

    /* ================================================ Baris nol & temuan */

    /**
     * Baris nol tidak menghabiskan waktu orang.
     *
     * Sisa batch yang sudah habis bukan stok. Menyuruh orang berjalan ke rak
     * untuk memastikan nol memang nol memakai waktu yang seharusnya dipakai
     * menghitung barang sungguhan.
     */
    public function test_baris_stok_nol_tidak_ikut_dibekukan(): void
    {
        $this->loginAs();

        $this->stok(0, 0);
        $this->stok(25);

        $this->bukaSesi();

        $this->assertSame(1, StockTakeItem::count());
        $this->assertSame(25, StockTakeItem::first()->qty_system);
    }

    /** Baris yang habis dicadangkan tetap dihitung — barangnya masih di rak. */
    public function test_baris_yang_habis_dicadangkan_tetap_dibekukan(): void
    {
        $this->loginAs();
        $this->stok(0, 12);

        $this->bukaSesi();

        $this->assertSame(1, StockTakeItem::count());
        $this->assertSame(12, StockTakeItem::first()->qty_system);
    }

    private function catatTemuan(StockTake $sesi, array $ubah = [])
    {
        return $this->post(route('wms.stocktake.found', $sesi), array_merge([
            'location_id' => $this->rak->id,
            'product_id' => $this->produk->id,
            'batch_no' => 'BT-TEMUAN',
            'production_date' => now()->subMonths(2)->toDateString(),
            'qty' => 40,
            'note' => 'palet terselip di belakang',
        ], $ubah));
    }

    public function test_temuan_tercatat_sebagai_baris_sesi(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $sesi = StockTake::first();

        $this->catatTemuan($sesi)->assertSessionHas('success');

        $temuan = StockTakeItem::where('is_found', true)->firstOrFail();

        $this->assertSame(0, $temuan->qty_system);
        $this->assertSame(40, $temuan->qty_physical);
        $this->assertNull($temuan->inventory_stock_id);
        $this->assertSame('BT-TEMUAN', $temuan->batch_no);
    }

    /** Mencatat temuan BELUM mengubah stok, sama seperti hitungan lain. */
    public function test_temuan_belum_mengubah_stok_sebelum_disahkan(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $this->catatTemuan(StockTake::first());

        $this->assertSame(1, InventoryStock::count(), 'Belum ada baris stok baru sebelum pengesahan.');
    }

    /**
     * Pengesahan MELAHIRKAN baris stoknya.
     *
     * Tanpa ini formulir temuan cuma hiasan: barangnya tercatat di laporan
     * tetapi tidak pernah masuk gudang.
     */
    public function test_pengesahan_mewujudkan_temuan_menjadi_stok(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $sesi = StockTake::first();
        $this->catatTemuan($sesi);
        $this->hitung(StockTakeItem::where('is_found', false)->first(), 10);

        $this->post(route('wms.stocktake.finalize', $sesi))->assertSessionHasNoErrors();

        $baru = InventoryStock::where('batch_no', 'BT-TEMUAN')->firstOrFail();

        $this->assertSame(40, $baru->qty_available);
        $this->assertSame($this->rak->id, $baru->location_id);
        $this->assertSame(InventoryStock::STATUS_ACTIVE, $baru->status);
        // Kedaluwarsa dihitung dari tanggal produksi yang dibaca dari palet,
        // bukan dari hari ini — kalau tidak, palet lama justru dijual terakhir.
        $this->assertSame(
            now()->subMonths(2)->toDateString(),
            $baru->production_date->toDateString(),
        );
    }

    /** Temuan meninggalkan jejak di ledger seperti pergerakan stok lainnya. */
    public function test_temuan_tercatat_di_ledger(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $sesi = StockTake::first();
        $this->catatTemuan($sesi);
        $this->post(route('wms.stocktake.finalize', $sesi));

        $gerak = StockMovement::where('batch_no', 'BT-TEMUAN')->firstOrFail();

        $this->assertSame(40, $gerak->qty_change);
        $this->assertSame(0, $gerak->qty_before);
        $this->assertSame(40, $gerak->qty_after);
        $this->assertStringContainsString('TEMUAN', $gerak->notes);
    }

    /**
     * Batch yang sudah ada di daftar BUKAN temuan.
     *
     * Membiarkan keduanya berdiri berdampingan akan menjumlahkan barang yang
     * sama dua kali saat pengesahan.
     */
    public function test_batch_yang_sudah_ada_di_daftar_ditolak_sebagai_temuan(): void
    {
        $this->loginAs();
        $stok = $this->stok(10);
        $this->bukaSesi();

        $this->catatTemuan(StockTake::first(), ['batch_no' => $stok->batch_no])
            ->assertSessionHas('error');

        $this->assertSame(0, StockTakeItem::where('is_found', true)->count());
    }

    /** Rak di luar cakupan sesi ditolak. */
    public function test_temuan_di_luar_cakupan_sesi_ditolak(): void
    {
        $this->loginAs();

        $this->stok(10, lokasi: $this->rakDi('B-02-01', 'B-02'));
        $luar = $this->rakDi('C-09-01', 'C-09');

        $this->bukaSesi(['scope_type' => StockTake::SCOPE_RACK, 'scope_value' => 'B-02']);

        $this->catatTemuan(StockTake::first(), ['location_id' => $luar->id])
            ->assertSessionHas('error');

        $this->assertSame(0, StockTakeItem::where('is_found', true)->count());
    }

    /** Tanggal produksi di masa depan memberi umur simpan yang tidak pernah ada. */
    public function test_tanggal_produksi_temuan_tidak_boleh_di_masa_depan(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $this->catatTemuan(StockTake::first(), ['production_date' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors('production_date');
    }

    /**
     * Batch yang sama muncul lewat inbound di tengah sesi digabung, tidak
     * diduplikasi — satu tumpukan fisik tidak boleh jadi dua baris sistem.
     */
    public function test_temuan_digabung_bila_batchnya_muncul_sebelum_pengesahan(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $sesi = StockTake::first();
        $this->catatTemuan($sesi);

        // Inbound menaruh batch yang sama di rak itu setelah sesinya dibuka.
        InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->gudang->id,
            'location_id' => $this->rak->id,
            'batch_no' => 'BT-TEMUAN',
            'production_date' => now()->subMonths(2)->toDateString(),
            'expiry_date' => now()->addYears(2)->toDateString(),
            'qty_available' => 5,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);

        $this->post(route('wms.stocktake.finalize', $sesi));

        $this->assertSame(1, InventoryStock::where('batch_no', 'BT-TEMUAN')->count());
        $this->assertSame(45, InventoryStock::where('batch_no', 'BT-TEMUAN')->first()->qty_available);
    }

    /** Sesi yang dibatalkan tidak melahirkan stok apa pun. */
    public function test_temuan_pada_sesi_yang_dibatalkan_tidak_menjadi_stok(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $sesi = StockTake::first();
        $this->catatTemuan($sesi);
        $this->post(route('wms.stocktake.cancel', $sesi));

        $this->assertSame(0, InventoryStock::where('batch_no', 'BT-TEMUAN')->count());
    }

    /** Operator boleh mencatat temuan — sama dengan mengisi hitungan biasa. */
    public function test_operator_boleh_mencatat_temuan(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $sesi = StockTake::first();

        $this->loginAs(Role::WAREHOUSE_OPERATOR);

        $this->catatTemuan($sesi)->assertSessionHas('success');
    }

    /** Temuan masuk laporan sesi, bukan menghilang setelah disahkan. */
    public function test_temuan_muncul_di_laporan_sesi(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $sesi = StockTake::first();
        $this->catatTemuan($sesi);
        $this->hitung(StockTakeItem::where('is_found', false)->first(), 10);
        $this->post(route('wms.stocktake.finalize', $sesi));

        $baris = collect($this->get(route('wms.stocktake.report', $sesi))->viewData('baris'));

        $this->assertSame(50, $baris->firstWhere('sku', 'APKO-001')['sesudah']);
        $this->assertSame(40, $baris->firstWhere('sku', 'APKO-001')['selisih']);
    }

    /* ================================ Dua langkah: periksa lalu sahkan */

    /**
     * Layar penghitungan TIDAK lagi punya tombol pengesahan.
     *
     * Pengesahan dipisah dari layar penghitungan, supaya yang
     * menekannya tidak mengesahkan angka yang belum pernah ia lihat berjejer.
     * Stocktake lazim dikerjakan beberapa orang, dan kesalahan satu orang baru
     * kelihatan saat seluruh SKU berbaris dalam satu halaman.
     */
    public function test_layar_penghitungan_mengarah_ke_laporan_bukan_pengesahan(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $sesi = StockTake::first();

        $html = $this->get(route('wms.stocktake.show', $sesi))->assertOk()->getContent();

        $this->assertStringContainsString(route('wms.stocktake.report', $sesi), $html);
        $this->assertStringNotContainsString(route('wms.stocktake.finalize', $sesi), $html);
    }

    /** Pengesahannya pindah ke kaki laporan, setelah angkanya bisa diperiksa. */
    public function test_tombol_pengesahan_ada_di_laporan(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $sesi = StockTake::first();

        $this->get(route('wms.stocktake.report', $sesi))
            ->assertOk()
            ->assertSee(route('wms.stocktake.finalize', $sesi), false)
            ->assertSee('Sudah diperiksa?');
    }

    /** Yang hanya boleh menghitung tidak melihat tombol pengesahan di laporan. */
    public function test_operator_tidak_melihat_tombol_pengesahan_di_laporan(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $sesi = StockTake::first();

        $this->loginAs(Role::WAREHOUSE_OPERATOR);

        $html = $this->get(route('wms.stocktake.report', $sesi))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('wms.stocktake.finalize', $sesi), $html);
    }

    /** Laporan yang sudah disahkan tidak menawarkan pengesahan lagi. */
    public function test_laporan_yang_sudah_disahkan_tidak_menawarkan_pengesahan(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $sesi = StockTake::first();
        $this->hitung(StockTakeItem::first(), 10);
        $this->post(route('wms.stocktake.finalize', $sesi));

        $html = $this->get(route('wms.stocktake.report', $sesi))->assertOk()->getContent();

        $this->assertStringNotContainsString('Sudah diperiksa?', $html);
    }

    /* ================================= Laporan sebagai berkas Excel */

    /** @return array{teks:string, sheet:Worksheet} */
    private function unduhLaporan(StockTake $sesi): array
    {
        $respons = $this->get(route('wms.stocktake.report.download', $sesi))->assertOk();

        $berkas = tempnam(sys_get_temp_dir(), 'st').'.xlsx';
        file_put_contents($berkas, $respons->streamedContent());

        $sheet = IOFactory::load($berkas)->getActiveSheet();

        $teks = '';

        foreach ($sheet->toArray() as $b) {
            $teks .= implode('|', array_map(fn ($n) => (string) $n, $b))."\n";
        }

        @unlink($berkas);

        return ['teks' => $teks, 'sheet' => $sheet];
    }

    /** Laporan stocktake bisa diunduh sebagai Excel dengan angka bertipe bilangan. */
    public function test_laporan_bisa_diunduh_sebagai_excel(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $sesi = StockTake::first();
        $this->hitung(StockTakeItem::first(), 12);
        $this->post(route('wms.stocktake.finalize', $sesi));

        $isi = $this->unduhLaporan($sesi->fresh());

        $this->assertSame('Laporan Stocktake '.$sesi->reference, $isi['sheet']->getCell('A1')->getValue());
        $this->assertSame('SKU', $isi['sheet']->getCell('A4')->getValue());
        $this->assertStringContainsString('APKO-001', $isi['teks']);

        // Angka HARUS mendarat sebagai bilangan; kalau jadi teks, SUM() di
        // Excel mengembalikan nol tanpa keluhan apa pun.
        $this->assertSame(10, $isi['sheet']->getCell('D5')->getValue());
        $this->assertSame(12, $isi['sheet']->getCell('E5')->getValue());
        $this->assertSame(2, $isi['sheet']->getCell('F5')->getValue());
        $this->assertSame('n', $isi['sheet']->getCell('D5')->getDataType());
    }

    /**
     * Pratinjau boleh diunduh, tetapi MENGAKU di dalam berkasnya sendiri.
     *
     * Berkas Excel hidup lebih lama daripada layar yang melahirkannya: ia
     * di-forward dan dibuka lagi berbulan-bulan kemudian oleh orang yang tidak
     * pernah melihat layarnya.
     */
    public function test_unduhan_sebelum_disahkan_mengaku_belum_disahkan(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $isi = $this->unduhLaporan(StockTake::first());

        $this->assertStringContainsString('BELUM DISAHKAN', $isi['teks']);
    }

    /** Baris yang tidak sempat dihitung ikut mengaku di dalam berkasnya. */
    public function test_unduhan_menyebut_baris_yang_belum_dihitung(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->stok(20, lokasi: $this->rakDi('C-01-01', 'C-01'));
        $this->bukaSesi();

        $sesi = StockTake::first();
        $this->hitung(StockTakeItem::first(), 10);
        $this->post(route('wms.stocktake.finalize', $sesi));

        $isi = $this->unduhLaporan($sesi->fresh());

        $this->assertStringContainsString('PERHATIAN', $isi['teks']);
    }

    /** Unduhan meninggalkan jejak — berkasnya beredar lebih lama dari layarnya. */
    public function test_unduhan_laporan_tercatat_di_log(): void
    {
        $user = $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $this->unduhLaporan(StockTake::first());

        $log = ActivityLog::where('action', ActivityLog::REPORT_EXPORT)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('stocktake', $log->properties['laporan']);
    }

    /** Gudang lain tidak bisa mengunduh laporan yang bukan wilayahnya. */
    public function test_laporan_gudang_lain_tidak_bisa_diunduh(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $sesi = StockTake::first();

        // Manager gudang lain: sesi ini bukan wilayahnya.
        $lain = Warehouse::factory()->create(['code' => 'WH-88']);
        $penyusup = User::factory()->withRole(Role::MANAGER)->create(['warehouse_id' => $lain->id]);
        $this->withCredentials();
        $this->actingAs($penyusup);

        $this->get(route('wms.stocktake.report.download', $sesi))->assertForbidden();
    }

    /* ================================================= Pratinjau laporan */

    /*
     * Pratinjau laporan HARUS menunjukkan selisih yang sudah ditemukan.
     *
     * Dulu kolom "sesudah" membaca qty_after, padahal kolom itu baru terisi
     * saat pengesahan — jadi pratinjau selalu menunjukkan sesudah = sebelum
     * dan selisih 0, sekalipun layar penghitungan sudah menghitung 4 baris
     * berselisih. Justru di pratinjau itulah orang memeriksa sebelum menekan
     * "Sahkan", dan laporan yang selalu bersih membuat pemeriksaannya sia-sia.
     */
    public function test_pratinjau_laporan_menunjukkan_selisih_sebelum_disahkan(): void
    {
        $this->loginAs();
        $this->stok(30);
        $this->bukaSesi();
        $this->hitung(StockTakeItem::firstOrFail(), 25);

        $baris = $this->get(route('wms.stocktake.report', StockTake::firstOrFail()))
            ->assertOk()
            ->viewData('baris');

        $this->assertSame(30, $baris[0]['sebelum']);
        $this->assertSame(25, $baris[0]['sesudah'], 'Pratinjau harus meramalkan hasil hitungan.');
        $this->assertSame(-5, $baris[0]['selisih']);
    }

    /** Barang temuan adalah PENAMBAHAN, dan itu harus terbaca sejak pratinjau. */
    public function test_pratinjau_laporan_memuat_temuan_sebagai_penambahan(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $sesi = StockTake::firstOrFail();
        $this->catatTemuan($sesi);
        $this->hitung(StockTakeItem::where('is_found', false)->firstOrFail(), 10);

        $baris = collect($this->get(route('wms.stocktake.report', $sesi))->viewData('baris'))
            ->firstWhere('sku', 'APKO-001');

        $this->assertSame(10, $baris['sebelum']);
        $this->assertSame(50, $baris['sesudah']);
        $this->assertSame(40, $baris['selisih']);
    }

    /**
     * Baris yang belum dihitung menyumbang angka sistemnya, bukan nol.
     *
     * Kalau ia dihitung nol, pratinjau akan melaporkan kekurangan besar yang
     * tidak pernah terjadi — dan orang mengejar barang yang sebenarnya ada di
     * rak yang belum sempat diperiksa.
     */
    public function test_pratinjau_tidak_menganggap_baris_yang_belum_dihitung_sebagai_nol(): void
    {
        $this->loginAs();
        $this->stok(30);
        $this->stok(40);
        $this->bukaSesi();

        $this->hitung(StockTakeItem::orderBy('id')->firstOrFail(), 28);

        $baris = $this->get(route('wms.stocktake.report', StockTake::firstOrFail()))->viewData('baris');

        $this->assertSame(70, $baris[0]['sebelum']);
        $this->assertSame(68, $baris[0]['sesudah'], '40 unit yang belum dihitung tetap dihitung utuh.');
        $this->assertSame(-2, $baris[0]['selisih']);
    }

    /** Angka pratinjau dan angka sesudah pengesahan harus sama. */
    public function test_angka_pratinjau_sama_dengan_angka_setelah_disahkan(): void
    {
        $this->loginAs();
        $this->stok(30);
        $this->bukaSesi();
        $this->hitung(StockTakeItem::firstOrFail(), 25);

        $sesi = StockTake::firstOrFail();
        $pratinjau = $this->get(route('wms.stocktake.report', $sesi))->viewData('baris');

        $this->post(route('wms.stocktake.finalize', $sesi));

        $this->assertSame($pratinjau, $this->get(route('wms.stocktake.report', $sesi))->viewData('baris'));
    }

    /* ======================================= Tanggal produksi dari batch */

    /**
     * Nomor batch pabrik memuat tahun dan bulan produksinya: I1|26|08|0071.
     * Selama tanggalnya diketik terpisah, dua keterangan tentang palet yang
     * sama bisa saling bertentangan — dan yang salah justru yang menentukan
     * kedaluwarsa serta urutan FIFO.
     */
    public function test_tanggal_produksi_dibaca_dari_nomor_batch(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $this->catatTemuan(StockTake::firstOrFail(), [
            'batch_no' => 'I126080071',
            'production_date' => null,
        ])->assertSessionHas('success');

        $this->assertSame(
            '2026-08-01',
            StockTakeItem::where('is_found', true)->firstOrFail()->found_production_date->toDateString(),
        );
    }

    /** Nomor batch menang atas isian manual: satu palet, satu keterangan. */
    public function test_nomor_batch_mengalahkan_tanggal_yang_diketik(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $this->catatTemuan(StockTake::firstOrFail(), [
            'batch_no' => 'I126080071',
            'production_date' => '2020-01-01',
        ])->assertSessionHas('success');

        $this->assertSame(
            '2026-08-01',
            StockTakeItem::where('is_found', true)->firstOrFail()->found_production_date->toDateString(),
        );
    }

    /**
     * Batch lama yang tidak berpola (mis. "642346774") tetap bisa dicatat
     * lewat isian manual. Menolak barangnya sama sekali akan mengembalikan
     * operator ke catatan kertas yang hilang.
     */
    public function test_batch_tak_berpola_masih_bisa_memakai_tanggal_manual(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $this->catatTemuan(StockTake::firstOrFail(), [
            'batch_no' => '642346774',
            'production_date' => '2026-07-05',
        ])->assertSessionHas('success');

        $this->assertSame(
            '2026-07-05',
            StockTakeItem::where('is_found', true)->firstOrFail()->found_production_date->toDateString(),
        );
    }

    public function test_batch_tak_berpola_tanpa_tanggal_ditolak_dengan_alasan(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->bukaSesi();

        $this->catatTemuan(StockTake::firstOrFail(), [
            'batch_no' => '642346774',
            'production_date' => null,
        ])->assertSessionHas('error');

        $this->assertSame(0, StockTakeItem::where('is_found', true)->count());
    }

    public function test_pembacaan_tanggal_dari_nomor_batch(): void
    {
        $this->assertSame('2026-08-01', BatchProduksi::tanggal('I126080071'));
        $this->assertSame('2026-09-01', BatchProduksi::tanggal('I126090015'));
        $this->assertSame('2026-08-01', BatchProduksi::tanggal('  i126080145  '));

        foreach ([
            '642346774',        // tanpa awalan huruf
            'I126130071',       // bulan 13
            'I126000071',       // bulan 00
            'I199010001',       // 2099: masih di masa depan
            'BT-TEMUAN',
            'I12608',           // terlalu pendek
            '',
            null,
            ['I126080071'],
        ] as $salah) {
            $this->assertNull(BatchProduksi::tanggal($salah), var_export($salah, true));
        }
    }
}
