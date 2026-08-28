<?php

namespace Tests\Feature\Wms;

use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Master Lokasi Rak — PRD §5.2, §6.2.
 *
 * Kode berpola [Rak]-[Level]-[Sel]. Yang paling dijaga di sini: komponen
 * rak/level/sel selalu sinkron dengan kodenya, dan pengurutan mengikuti angka
 * (bukan string) supaya B-01-02 tidak jatuh setelah B-01-10.
 */
class LocationManagementTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create(['code' => 'WH-01']);
    }

    private function loginAs(string $roleSlug = Role::SUPER_ADMIN): User
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
            'warehouse_id' => $this->warehouse->id,
            'code' => 'B-01-01',
            'zone' => Location::ZONE_FAST,
            'is_active' => 1,
        ], $overrides);
    }

    /* ---------------------------------------------------------------- Akses */

    public function test_super_admin_dan_manager_dapat_membuka_master_lokasi(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::MANAGER] as $slug) {
            $this->loginAs($slug);
            $this->get('/wms/master/locations')->assertOk()->assertViewHas('locations');
        }
    }

    public function test_role_operasional_ditolak(): void
    {
        foreach ([Role::LOGISTICS, Role::PRODUCTION, Role::WAREHOUSE_OPERATOR] as $slug) {
            $this->loginAs($slug);
            $this->get('/wms/master/locations')->assertForbidden();
        }
    }

    /* ---------------------------------------------------- Kode & komponen */

    /** Rak/level/sel diturunkan dari kode, sehingga mustahil tidak sinkron. */
    public function test_komponen_dibaca_otomatis_dari_kode(): void
    {
        $this->loginAs();

        $this->post('/wms/master/locations', $this->validPayload(['code' => 'ZD-04-21']))
            ->assertSessionHasNoErrors();

        $location = Location::where('code', 'ZD-04-21')->firstOrFail();

        $this->assertSame('ZD', $location->rack);
        $this->assertSame(4, $location->level);
        $this->assertSame(21, $location->cell);
    }

    public function test_kode_dengan_format_salah_ditolak(): void
    {
        $this->loginAs();

        foreach (['B0101', 'B-1', 'ABC-01-01', '1-01-01'] as $bad) {
            $this->post('/wms/master/locations', $this->validPayload(['code' => $bad]))
                ->assertSessionHasErrors('code');
        }
    }

    /** Seluruh rak hanya punya 5 level. */
    public function test_level_di_atas_lima_ditolak(): void
    {
        $this->loginAs();

        $this->post('/wms/master/locations', $this->validPayload(['code' => 'B-06-01']))
            ->assertSessionHasErrors('level');
    }

    public function test_kode_huruf_kecil_dinormalkan(): void
    {
        $this->loginAs();

        $this->post('/wms/master/locations', $this->validPayload(['code' => 'zd-01-05']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('locations', ['code' => 'ZD-01-05', 'rack' => 'ZD']);
    }

    /* ------------------------------------------------------------ Keunikan */

    public function test_kode_harus_unik_dalam_satu_gudang(): void
    {
        $this->loginAs();
        Location::factory()->at('B', 1, 1)->create(['warehouse_id' => $this->warehouse->id]);

        $this->post('/wms/master/locations', $this->validPayload(['code' => 'B-01-01']))
            ->assertSessionHasErrors('code');
    }

    /**
     * Kode yang sama BOLEH ada di gudang berbeda.
     *
     * Penamaan rak A/B/C lazim berulang antar gudang; memaksa unik global akan
     * menolak gudang kedua yang memakai penamaan sama.
     */
    public function test_kode_sama_boleh_di_gudang_berbeda(): void
    {
        $this->loginAs();
        $lain = Warehouse::factory()->create(['code' => 'WH-02']);

        Location::factory()->at('B', 1, 1)->create(['warehouse_id' => $this->warehouse->id]);

        $this->post('/wms/master/locations', $this->validPayload([
            'warehouse_id' => $lain->id,
            'code' => 'B-01-01',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(2, Location::where('code', 'B-01-01')->count());
    }

    /* ---------------------------------------------------------------- Zona */

    /** Ekspor ERP menulis "Midle Moving Area"; ejaannya dinormalkan. */
    public function test_ejaan_zona_dari_erp_dinormalkan(): void
    {
        $this->assertSame(Location::ZONE_MIDDLE, Location::normalizeZone('Midle Moving Area'));
        $this->assertSame(Location::ZONE_MIDDLE, Location::normalizeZone('Middle Moving Area'));
        $this->assertSame(Location::ZONE_FAST, Location::normalizeZone('fast moving area'));
        $this->assertNull(Location::normalizeZone(''));
    }

    /* ------------------------------------------------------------ Urutan */

    /**
     * Diurutkan secara angka, bukan string.
     *
     * Mengurutkan lewat kode akan menaruh "B-01-10" sebelum "B-01-02" karena
     * perbandingan teks — keliru saat operator menyusuri rak berurutan.
     */
    public function test_urutan_mengikuti_angka_bukan_teks(): void
    {
        foreach ([1, 2, 10, 11] as $cell) {
            Location::factory()->at('B', 1, $cell)->create(['warehouse_id' => $this->warehouse->id]);
        }

        $codes = Location::inStorageOrder()->pluck('code')->all();

        $this->assertSame(['B-01-01', 'B-01-02', 'B-01-10', 'B-01-11'], $codes);
    }

    /* --------------------------------------------------------------- Update */

    public function test_menyunting_lokasi(): void
    {
        $this->loginAs();
        $location = Location::factory()->at('B', 1, 1)->create([
            'warehouse_id' => $this->warehouse->id,
            'zone' => Location::ZONE_FAST,
        ]);

        $this->put("/wms/master/locations/{$location->id}", $this->validPayload([
            'code' => 'B-01-01',
            'zone' => Location::ZONE_SLOW,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(Location::ZONE_SLOW, $location->fresh()->zone);
    }

    /* -------------------------------------------------------- Toggle status */

    public function test_menonaktifkan_lokasi_tidak_menghapus_datanya(): void
    {
        $this->loginAs();
        $location = Location::factory()->create(['warehouse_id' => $this->warehouse->id]);

        $this->patch("/wms/master/locations/{$location->id}/status")
            ->assertSessionHas('success');

        $location->refresh();

        $this->assertFalse($location->is_active);
        // Masih direferensikan riwayat stok & pergerakan barang.
        $this->assertDatabaseHas('locations', ['id' => $location->id, 'deleted_at' => null]);
    }

    /* --------------------------------------------------------------- Seeder */

    /**
     * Seeder membangkitkan denah gudang sesuai pendataan: 2.264 bin, 29 rak,
     * dengan pembagian zona 826 / 476 / 962.
     */
    public function test_seeder_menghasilkan_denah_gudang_yang_benar(): void
    {
        $this->seed(LocationSeeder::class);

        $this->assertSame(2264, Location::count());
        $this->assertSame(29, Location::distinct()->count('rack'));

        $this->assertSame(826, Location::where('zone', Location::ZONE_FAST)->count());
        $this->assertSame(476, Location::where('zone', Location::ZONE_SLOW)->count());
        $this->assertSame(962, Location::where('zone', Location::ZONE_MIDDLE)->count());

        // Tidak ada Rak "A" pada denah gudang.
        $this->assertSame(0, Location::where('rack', 'A')->count());

        // Seluruh rak punya tepat 5 level.
        $this->assertSame(5, Location::distinct()->count('level'));
        $this->assertSame(0, Location::where('level', '>', Location::MAX_LEVEL)->count());
    }

    /** Level 4–5 memuat lebih banyak sel daripada Level 1–3 pada sebagian besar rak. */
    public function test_seeder_membuat_level_atas_lebih_panjang(): void
    {
        $this->seed(LocationSeeder::class);

        $this->assertSame(11, Location::where('rack', 'B')->where('level', 1)->count());
        $this->assertSame(13, Location::where('rack', 'B')->where('level', 4)->count());

        $this->assertSame(19, Location::where('rack', 'ZD')->where('level', 3)->count());
        $this->assertSame(21, Location::where('rack', 'ZD')->where('level', 5)->count());

        // Rak P dan W–X rata di semua level.
        $this->assertSame(20, Location::where('rack', 'P')->where('level', 1)->count());
        $this->assertSame(20, Location::where('rack', 'P')->where('level', 5)->count());
    }

    /** Seeder tidak menggandakan data bila dijalankan ulang. */
    public function test_seeder_aman_dijalankan_ulang(): void
    {
        $this->seed(LocationSeeder::class);
        $this->seed(LocationSeeder::class);

        $this->assertSame(2264, Location::count());
    }
}
