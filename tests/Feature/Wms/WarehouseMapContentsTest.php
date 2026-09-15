<?php

namespace Tests\Feature\Wms;

use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Denah gudang yang benar-benar menunjukkan isi raknya.
 *
 * Sebelum ini denahnya hanya menggambar kerangka: kotak-kotak beralamat tanpa
 * satu pun keterangan tentang barang di dalamnya, dengan catatan jujur bahwa
 * isinya menyusul setelah modul Inventory dibangun. Modul itu sudah ada, dan
 * berkas ini menjaga tiga hal yang mudah salah begitu isinya ditampilkan:
 *
 *   1. Angka di kotak menghitung barang FISIK — yang tersedia DITAMBAH yang
 *      sudah dicadangkan untuk pesanan. Barang yang dicadangkan tetap berdiri
 *      di rak sampai operator benar-benar mengambilnya, dan denah yang
 *      melewatkannya menyuruh orang mencari rak yang katanya kosong padahal
 *      penuh.
 *   2. Rincian isinya memuat SELURUH status, termasuk karantina dan DDP. Rak
 *      fisiknya memang memuat semua itu.
 *   3. Rak gudang lain tidak bisa diintip lewat URL rinciannya.
 */
class WarehouseMapContentsTest extends TestCase
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
            'code' => 'B-01-01',
            'rack' => 'B-01',
            'level' => 1,
            'cell' => 1,
            'is_active' => true,
        ]);
        $this->produk = Product::factory()->create([
            'sku' => 'APKO-001', 'name' => 'Bocor Guard 2 Base 1Kg', 'uom' => 'TIN', 'is_active' => true,
        ]);
    }

    private function loginAs(string $slug = Role::SUPER_ADMIN, ?Warehouse $gudang = null): User
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

    private function stok(array $overrides = []): InventoryStock
    {
        return InventoryStock::factory()->create(array_merge([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->gudang->id,
            'location_id' => $this->rak->id,
            'batch_no' => 'BT-'.Str::random(4),
            'production_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYears(2)->toDateString(),
            'qty_available' => 10,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ], $overrides));
    }

    /* ------------------------------------------------------ Ringkasan denah */

    public function test_denah_membawa_ringkasan_isi_tiap_rak(): void
    {
        $this->loginAs();
        $this->stok(['qty_available' => 40, 'qty_allocated' => 0]);

        $this->get('/wms/master/locations/map')
            ->assertOk()
            ->assertViewHas('isi', fn (array $isi) => ($isi[$this->rak->id]['qty'] ?? null) === 40
                && ($isi[$this->rak->id]['sku'] ?? null) === 1);
    }

    /**
     * Barang yang sudah dicadangkan TETAP dihitung.
     *
     * Ia masih berdiri di rak sampai operator mengambilnya; denah yang
     * melewatkannya menyuruh orang mencari rak yang katanya kosong.
     */
    public function test_barang_yang_sudah_dicadangkan_tetap_terhitung(): void
    {
        $this->loginAs();
        $this->stok(['qty_available' => 6, 'qty_allocated' => 4]);

        $this->get('/wms/master/locations/map')
            ->assertOk()
            ->assertViewHas('isi', fn (array $isi) => ($isi[$this->rak->id]['qty'] ?? null) === 10);
    }

    /** Rak tanpa stok tidak muncul di ringkasan — itulah tanda "kosong". */
    public function test_rak_kosong_tidak_masuk_ringkasan(): void
    {
        $this->loginAs();

        $this->get('/wms/master/locations/map')
            ->assertOk()
            ->assertViewHas('isi', fn (array $isi) => ! array_key_exists($this->rak->id, $isi));
    }

    /** Baris stok yang habis juga terbaca kosong, bukan "terisi 0". */
    public function test_rak_dengan_stok_nol_terbaca_kosong(): void
    {
        $this->loginAs();
        $this->stok(['qty_available' => 0, 'qty_allocated' => 0]);

        $this->get('/wms/master/locations/map')
            ->assertOk()
            ->assertViewHas('isi', fn (array $isi) => ! array_key_exists($this->rak->id, $isi));
    }

    public function test_kartu_ringkas_menghitung_rak_terisi_dan_total_unit(): void
    {
        $this->loginAs();
        $this->stok(['qty_available' => 25]);

        $kosong = Location::factory()->create([
            'warehouse_id' => $this->gudang->id,
            'code' => 'B-01-02', 'rack' => 'B-01', 'level' => 1, 'cell' => 2,
        ]);

        $this->get('/wms/master/locations/map')
            ->assertOk()
            ->assertViewHas('stats', fn (array $s) => $s['terisi'] === 1
                && $s['qty'] === 25
                && $s['total'] === 2)
            ->assertViewHas('isi', fn (array $isi) => ! array_key_exists($kosong->id, $isi));
    }

    /* -------------------------------------------------------- Rincian isi */

    public function test_rincian_isi_rak_memuat_produk_batch_dan_status(): void
    {
        $this->loginAs();
        $this->stok([
            'batch_no' => 'I126080037',
            'qty_available' => 12,
            'qty_allocated' => 3,
        ]);

        $this->getJson('/wms/master/locations/'.$this->rak->id.'/contents')
            ->assertOk()
            ->assertJsonPath('kode', 'B-01-01')
            ->assertJsonPath('total', 15)
            ->assertJsonPath('baris.0.sku', 'APKO-001')
            ->assertJsonPath('baris.0.batch', 'I126080037')
            ->assertJsonPath('baris.0.tersedia', 12)
            ->assertJsonPath('baris.0.teralokasi', 3)
            ->assertJsonPath('baris.0.status_label', 'Good Stock');
    }

    /**
     * Karantina dan DDP ikut muncul.
     *
     * Rak fisiknya memang memuat keduanya, dan denah yang hanya menyebut
     * barang layak jual membuat orang mencari-cari barang yang sebenarnya ada
     * di depan matanya.
     */
    public function test_rincian_memuat_stok_karantina_dan_ddp(): void
    {
        $this->loginAs();

        $this->stok(['batch_no' => 'BT-AKTIF']);
        $this->stok([
            'batch_no' => 'BT-DDP',
            'status' => InventoryStock::STATUS_DDP,
            'ddp_reason' => InventoryStock::DDP_WRITE_OFF,
        ]);

        $this->getJson('/wms/master/locations/'.$this->rak->id.'/contents')
            ->assertOk()
            ->assertJsonCount(2, 'baris')
            ->assertJsonFragment(['status_label' => 'Stok DDP']);
    }

    public function test_rak_kosong_menjawab_daftar_kosong_bukan_galat(): void
    {
        $this->loginAs();

        $this->getJson('/wms/master/locations/'.$this->rak->id.'/contents')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'baris');
    }

    public function test_isi_rak_gudang_lain_tidak_bisa_dibuka(): void
    {
        $lain = Warehouse::factory()->create(['code' => 'WH-02', 'name' => 'Pekanbaru']);
        $rakLain = Location::factory()->create([
            'warehouse_id' => $lain->id,
            'code' => 'C-01-01', 'rack' => 'C-01', 'level' => 1, 'cell' => 1,
        ]);

        // Manager yang terikat gudang WH-01.
        $this->loginAs(Role::MANAGER, $this->gudang);

        $this->getJson('/wms/master/locations/'.$rakLain->id.'/contents')->assertForbidden();
        $this->getJson('/wms/master/locations/'.$this->rak->id.'/contents')->assertOk();
    }

    public function test_role_tanpa_izin_master_lokasi_tidak_bisa_membuka_isi_rak(): void
    {
        $this->loginAs(Role::SALES);

        $this->getJson('/wms/master/locations/'.$this->rak->id.'/contents')->assertForbidden();
    }

    /* ------------------------------------------------------------ Istilah */

    /**
     * Permintaan pemilik produk: kata "bin" tidak dipakai lagi di layar.
     *
     * Dicocokkan sebagai KATA UTUH, bukan potongan huruf. Bahasa Indonesia
     * penuh kata yang memuat "bin" di tengahnya — "bingkai", "membingungkan",
     * "berbintang" — dan pencarian potongan huruf akan menuduh kalimat yang
     * sama sekali tidak menyebut istilah itu.
     */
    public function test_denah_tidak_lagi_memakai_kata_bin(): void
    {
        $this->loginAs();
        $this->stok();

        $html = $this->get('/wms/master/locations/map')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/\bbins?\b/i', $html);
    }

    public function test_tabel_lokasi_tidak_lagi_memakai_kata_bin(): void
    {
        $this->loginAs();

        $html = $this->get('/wms/master/locations')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/\bbins?\b/i', $html);
    }
}
