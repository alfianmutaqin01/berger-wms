<?php

namespace Tests\Feature\Wms;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Master Kategori Produk — PRD §6.2 F-MASTER-03.
 *
 * Yang paling perlu dijaga di sini BUKAN CRUD-nya, melainkan satu hal:
 * kategori tidak boleh bisa dihapus. Tabelnya ditunjuk products.category_id,
 * dan produk lama harus tetap bisa menyebut kategorinya apa adanya. Jalan
 * keluar untuk berhenti memakai sebuah kategori adalah menonaktifkannya —
 * pilihannya hilang untuk produk baru, produk lama tidak tersentuh.
 */
class ProductCategoryManagementTest extends TestCase
{
    use RefreshDatabase;

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

    /* --------------------------------------------------------------- Akses */

    /** F-MASTER-03 menyebut dua role, dan hanya dua. */
    public function test_super_admin_dan_manager_boleh_membuka(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::MANAGER] as $role) {
            $this->loginAs($role);
            $this->get(route('wms.product-categories.index'))->assertOk();
        }
    }

    public function test_role_lain_ditolak(): void
    {
        foreach ([Role::LOGISTICS, Role::WAREHOUSE_OPERATOR, Role::SALES] as $role) {
            $this->loginAs($role);

            $this->get(route('wms.product-categories.index'))->assertForbidden();

            // Pintu tulisnya ikut dijaga — menyembunyikan menu bukan pengamanan.
            $this->post(route('wms.product-categories.store'), ['name' => 'Selundupan'])
                ->assertForbidden();
        }

        $this->assertSame(0, ProductCategory::count());
    }

    /* ---------------------------------------------------------------- CRUD */

    public function test_menambah_kategori(): void
    {
        $this->loginAs(Role::MANAGER);

        $this->post(route('wms.product-categories.store'), [
            'name' => 'Cat Tembok',
            'description' => 'Interior dan eksterior.',
            'is_active' => 1,
        ])->assertRedirect(route('wms.product-categories.index'))
            ->assertSessionHas('success');

        $kategori = ProductCategory::firstOrFail();

        $this->assertSame('Cat Tembok', $kategori->name);
        $this->assertTrue($kategori->is_active);
    }

    public function test_nama_kembar_ditolak(): void
    {
        ProductCategory::factory()->create(['name' => 'Thinner']);

        $this->loginAs(Role::MANAGER);

        $this->post(route('wms.product-categories.store'), ['name' => 'Thinner'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, ProductCategory::count());
    }

    /**
     * Keunikan dikecualikan untuk dirinya sendiri, kalau tidak menyunting
     * keterangan saja akan ditolak karena namanya "sudah terpakai" — oleh
     * baris yang sedang disunting itu juga.
     */
    public function test_menyunting_tanpa_mengganti_nama_tidak_ditolak(): void
    {
        $kategori = ProductCategory::factory()->create(['name' => 'Cat Kayu', 'description' => null]);

        $this->loginAs(Role::MANAGER);

        $this->put(route('wms.product-categories.update', $kategori), [
            'name' => 'Cat Kayu',
            'description' => 'Untuk permukaan kayu.',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Untuk permukaan kayu.', $kategori->fresh()->description);
    }

    /* -------------------------------------------------------------- Status */

    /**
     * INTI BERKAS INI. Kategori yang dipakai 2 produk tetap boleh
     * dinonaktifkan, dan kedua produk itu TIDAK BOLEH ikut berubah —
     * baik kategorinya maupun statusnya sendiri.
     */
    public function test_menonaktifkan_kategori_tidak_menyentuh_produk_yang_memakainya(): void
    {
        $kategori = ProductCategory::factory()->create(['name' => 'Thinner', 'is_active' => true]);

        $satu = Product::factory()->create(['category_id' => $kategori->id, 'is_active' => true]);
        $dua = Product::factory()->create(['category_id' => $kategori->id, 'is_active' => true]);

        $this->loginAs(Role::MANAGER);

        $this->patch(route('wms.product-categories.status', $kategori))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($kategori->fresh()->is_active);

        foreach ([$satu, $dua] as $produk) {
            $produk->refresh();
            $this->assertSame($kategori->id, $produk->category_id, 'Kategori produk tidak boleh ikut dilepas.');
            $this->assertTrue($produk->is_active, 'Produk tidak boleh ikut dinonaktifkan.');
        }
    }

    /** Dan yang non-aktif harus bisa dihidupkan lagi — bukan jalan satu arah. */
    public function test_kategori_non_aktif_bisa_diaktifkan_lagi(): void
    {
        $kategori = ProductCategory::factory()->create(['is_active' => false]);

        $this->loginAs(Role::SUPER_ADMIN);

        $this->patch(route('wms.product-categories.status', $kategori))->assertRedirect();

        $this->assertTrue($kategori->fresh()->is_active);
    }

    /**
     * TIDAK ADA RUTE HAPUS, dan itu disengaja — bukan kelalaian yang menunggu
     * dilengkapi. Menghapus kategori membuat produk lama kehilangan
     * penjelasan tentang dirinya sendiri.
     */
    public function test_tidak_ada_jalan_untuk_menghapus_kategori(): void
    {
        $kategori = ProductCategory::factory()->create();

        $this->loginAs(Role::SUPER_ADMIN);

        // 405, bukan 404: alamatnya memang ada (dipakai PUT dan PATCH),
        // yang tidak ada adalah metode DELETE-nya.
        $this->delete('/wms/master/product-categories/'.$kategori->id)
            ->assertStatus(405);

        $this->assertSame(1, ProductCategory::count());
    }
}
