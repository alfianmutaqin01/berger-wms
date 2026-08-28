<?php

namespace Tests\Feature\Wms;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Support\PalletCapacity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Master Produk — PRD §6.2 F-MASTER-02.
 *
 * Dua hal yang paling dijaga di sini:
 *   1. Kapasitas palet dihitung dari aturan gudang (PRD §7.1), dan ukuran yang
 *      TIDAK terdaftar dibiarkan kosong — bukan ditebak.
 *   2. Tabel produk tidak pernah menyimpan jumlah stok.
 */
class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = ProductCategory::factory()->create(['name' => 'Royale Emulsion']);
    }

    private function loginAs(string $roleSlug): User
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

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'product_code' => '0011',
            'shade_code' => '3202',
            'pack_code' => '225',
            'sku' => '',
            'name' => 'Royale Smart Clean White 2.5Ltr',
            'category_id' => $this->category->id,
            'uom' => 'TIN',
            'pack_size' => '2.5',
            'pack_unit' => PalletCapacity::UNIT_LITER,
            'unit_volume' => '2.5',
            'net_weight' => '',
            'gross_weight' => '4.05',
            'max_qty_per_pallet' => '',
            'shelf_life_months' => 30,
            'stock_threshold_low' => 50,
            'is_active' => 1,
        ], $overrides);
    }

    /* ---------------------------------------------------------------- Akses */

    public function test_super_admin_dan_manager_dapat_membuka_master_produk(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::MANAGER] as $slug) {
            $this->loginAs($slug);
            $this->get('/wms/master/products')->assertOk()->assertViewHas('products');
        }
    }

    public function test_role_operasional_ditolak(): void
    {
        foreach ([Role::LOGISTICS, Role::PRODUCTION, Role::WAREHOUSE_OPERATOR] as $slug) {
            $this->loginAs($slug);
            $this->get('/wms/master/products')->assertForbidden();
        }
    }

    /* --------------------------------------------------------------- Create */

    public function test_membuat_produk_baru(): void
    {
        $actor = $this->loginAs(Role::SUPER_ADMIN);

        $this->post('/wms/master/products', $this->validPayload())
            ->assertRedirect(route('wms.products.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('products', [
            'sku' => 'ID1-F00113202225',
            'name' => 'Royale Smart Clean White 2.5Ltr',
            'created_by' => $actor->id,
            'is_active' => true,
        ]);
    }

    /** SKU dibentuk dari product_code + shade_code + pack_code bila tidak diisi. */
    public function test_sku_terbentuk_otomatis_dari_tiga_kode(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        $this->post('/wms/master/products', $this->validPayload([
            'product_code' => '0011',
            'shade_code' => 'B050',
            'pack_code' => '320',
            'sku' => '',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['sku' => 'ID1-F0011B050320']);
    }

    /** SKU dari ERP boleh berbeda pola, jadi isian manual harus dihormati. */
    public function test_sku_manual_tidak_ditimpa(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        $this->post('/wms/master/products', $this->validPayload(['sku' => 'LEGACY-999']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['sku' => 'LEGACY-999']);
    }

    public function test_sku_harus_unik(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);
        Product::factory()->create(['sku' => 'ID1-F00113202225']);

        $this->post('/wms/master/products', $this->validPayload())
            ->assertSessionHasErrors('sku');
    }

    /* --------------------------------------------------- Kapasitas palet */

    /** PRD §7.1: 2.5 L -> 180 pcs, 20 L -> 27 pcs. */
    public function test_kapasitas_palet_dihitung_otomatis_untuk_kemasan_liter(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        $this->post('/wms/master/products', $this->validPayload([
            'pack_code' => '225', 'pack_size' => '2.5',
        ]));
        $this->assertSame(180, Product::where('sku', 'ID1-F00113202225')->value('max_qty_per_pallet'));

        $this->post('/wms/master/products', $this->validPayload([
            'pack_code' => '320', 'pack_size' => '20',
        ]));
        $this->assertSame(27, Product::where('sku', 'ID1-F00113202320')->value('max_qty_per_pallet'));
    }

    /**
     * Kapasitas palet memakai ukuran WADAH, bukan volume isi.
     *
     * Regresi: pail "20Ltr" pada data ERP punya unit_volume 19.4 (menyisakan
     * ruang untuk pewarna). Bila kapasitas dihitung dari volume isi, produk ini
     * salah dianggap tidak punya aturan palet — padahal tetap 27 pcs per palet.
     */
    public function test_kapasitas_palet_memakai_ukuran_wadah_bukan_volume_isi(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        $this->post('/wms/master/products', $this->validPayload([
            'shade_code' => 'B128',
            'pack_code' => '320',
            'name' => 'Royale Smart Clean Blue Smoke 20Ltr',
            'pack_size' => '20',
            'unit_volume' => '19.4',
        ]))->assertSessionHasNoErrors();

        $product = Product::where('shade_code', 'B128')->firstOrFail();

        $this->assertSame(27, $product->max_qty_per_pallet);
        $this->assertSame('19.400', $product->unit_volume);
    }

    /** Ukuran kemasan dibaca dari nama produk bila tidak diisi. */
    public function test_ukuran_kemasan_dibaca_otomatis_dari_nama_produk(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        $this->post('/wms/master/products', $this->validPayload([
            'shade_code' => 'B137',
            'pack_code' => '320',
            'name' => 'Royale Smart Clean Solitaire 8500 20Ltr',
            'pack_size' => '',
            'pack_unit' => '',
            'unit_volume' => '18.4',
        ]))->assertSessionHasNoErrors();

        $product = Product::where('shade_code', 'B137')->firstOrFail();

        // "8500" tidak boleh tertangkap sebagai ukuran — hanya "20Ltr" di ujung nama.
        $this->assertSame('20.000', $product->pack_size);
        $this->assertSame(PalletCapacity::UNIT_LITER, $product->pack_unit);
        $this->assertSame(27, $product->max_qty_per_pallet);
    }

    /** Satuan menentukan hasil: 20 Kg -> 36 pcs, berbeda dari 20 L -> 27 pcs. */
    public function test_kapasitas_palet_membedakan_liter_dan_kilogram(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        $this->post('/wms/master/products', $this->validPayload([
            'pack_code' => '820',
            'name' => 'Trucare Alkali Resist Primer White 20Kg',
            'pack_size' => '20',
            'pack_unit' => PalletCapacity::UNIT_KILOGRAM,
            'unit_volume' => '',
            'net_weight' => '20',
        ]));

        $this->assertSame(36, Product::where('pack_code', '820')->value('max_qty_per_pallet'));
    }

    /** Ukuran di luar aturan gudang dibiarkan kosong, tidak ditebak. */
    public function test_ukuran_tidak_dikenal_tidak_mengisi_kapasitas_palet(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        $this->post('/wms/master/products', $this->validPayload([
            'pack_code' => '203',
            'name' => 'Royale Smart Clean White 0.25Ltr',
            'pack_size' => '0.25',
        ]))->assertSessionHasNoErrors();

        $product = Product::where('pack_code', '203')->firstOrFail();

        $this->assertNull($product->max_qty_per_pallet);
        $this->assertTrue($product->needsPalletCapacity());
    }

    public function test_kapasitas_palet_boleh_diisi_manual(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        $this->post('/wms/master/products', $this->validPayload([
            'pack_code' => '203', 'pack_size' => '0.25', 'max_qty_per_pallet' => '900',
        ]));

        $this->assertSame(900, Product::where('pack_code', '203')->value('max_qty_per_pallet'));
    }

    /** Angka dari ERP memakai koma sebagai pemisah desimal ("4,05"). */
    public function test_angka_desimal_berkoma_diterima(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        $this->post('/wms/master/products', $this->validPayload([
            'unit_volume' => '2,5', 'gross_weight' => '4,05',
        ]))->assertSessionHasNoErrors();

        $product = Product::where('sku', 'ID1-F00113202225')->firstOrFail();

        $this->assertSame('2.500', $product->unit_volume);
        $this->assertSame('4.050', $product->gross_weight);
        $this->assertSame(180, $product->max_qty_per_pallet);
    }

    /* --------------------------------------------------------------- Update */

    public function test_menyunting_produk(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);
        $product = Product::factory()->create(['sku' => 'ID1-F00113202225']);

        $this->put("/wms/master/products/{$product->id}", $this->validPayload([
            'sku' => 'ID1-F00113202225',
            'name' => 'Nama Diperbarui',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Nama Diperbarui', $product->fresh()->name);
    }

    /* -------------------------------------------------------- Toggle status */

    public function test_menonaktifkan_produk_tidak_menghapus_datanya(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);
        $product = Product::factory()->create();

        $this->patch("/wms/master/products/{$product->id}/status")
            ->assertSessionHas('success');

        $product->refresh();

        $this->assertFalse($product->is_active);
        // SKU masih direferensikan riwayat inbound/stok/pesanan lama.
        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
    }

    /* ------------------------------------------------------- Filter & cari */

    public function test_pencarian_menyaring_berdasarkan_sku_dan_nama(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        Product::factory()->create(['sku' => 'ID1-F0011B050320', 'name' => 'Vanilla Sky 20Ltr']);
        Product::factory()->create(['sku' => 'ID1-F00113202225', 'name' => 'Smart Clean White']);

        $names = $this->get('/wms/master/products?search=Vanilla')
            ->viewData('products')->pluck('name');

        $this->assertContains('Vanilla Sky 20Ltr', $names);
        $this->assertNotContains('Smart Clean White', $names);
    }

    public function test_filter_status_nonaktif(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        Product::factory()->inactive()->create();
        Product::factory()->create();

        $this->assertTrue(
            $this->get('/wms/master/products?status=inactive')
                ->viewData('products')->every(fn (Product $p) => $p->is_active === false)
        );
    }

    /* ------------------------------------------- Produk vs stok terpisah */

    /**
     * Tabel produk tidak boleh punya kolom jumlah stok.
     *
     * Kolom "Inventory" pada ekspor ERP adalah hasil penjumlahan; stok
     * sebenarnya tinggal di inventory_stocks (per gudang/lokasi/batch) agar
     * FIFO dan aturan kedaluwarsa bisa berjalan. Test ini menjaga agar kolom
     * semacam itu tidak menyelinap masuk di kemudian hari.
     */
    public function test_tabel_produk_tidak_menyimpan_jumlah_stok(): void
    {
        $columns = Schema::getColumnListing('products');

        foreach (['stock', 'qty', 'quantity', 'inventory', 'qty_available'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $columns,
                "Kolom [{$forbidden}] tidak boleh ada di tabel products — stok tinggal di inventory_stocks."
            );
        }
    }
}
