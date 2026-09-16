<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\FilterTanggal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Input rusak tidak boleh menjatuhkan halaman — temuan SQA pra-go-live.
 *
 * Tim SQA mengirim input rusak ke setiap rute (array di query string, tanggal
 * mustahil, id berupa teks) memakai data pengembangan. Yang diharapkan dari
 * input seperti itu adalah pesan validasi atau filter yang diabaikan — bukan
 * galat 500. Setiap test di sini adalah satu jenis temuan yang dulu 500.
 */
class InputRusakTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create(['code' => 'WH-01']);
    }

    private function loginAs(string $slug): User
    {
        $user = User::factory()->withRole($slug)->create(['warehouse_id' => $this->warehouse->id]);
        $token = Str::random(64);

        UserSession::create([
            'user_id' => $user->id, 'session_id' => $token, 'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit', 'last_activity_at' => now(), 'created_at' => now(),
        ]);

        $this->withUnencryptedCookies(['device_token' => $token]);
        $this->actingAs($user);

        return $user;
    }

    /* ------------------------------------------------ Array di query string */

    /** `?search[]=x` dulu dilempar ke scopeSearch(?string) dan menjatuhkan halaman. */
    public function test_parameter_array_di_query_string_diabaikan(): void
    {
        $this->loginAs(Role::SALES);

        $this->get('/sales/my-orders?search[]=x')->assertOk();
        $this->get('/sales/lookup/customers?q[]=x')->assertOk();
        $this->get('/sales/lookup/products?q[]=x')->assertOk();

        $this->loginAs(Role::SUPER_ADMIN);

        $this->get('/wms/inbound/history?search[]=x&status[]=y')->assertOk();
    }

    /** Nilai teks biasa tetap sampai ke controller — penjaganya hanya membuang array. */
    public function test_parameter_teks_tetap_diteruskan(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        $this->get('/wms/inbound/history?search=PRD-TIDAK-ADA&status[]=y')
            ->assertOk()
            ->assertViewHas('filters', fn ($f) => $f['search'] === 'PRD-TIDAK-ADA' && $f['status'] === null);
    }

    /** `?category_id=abc` dulu diteruskan ke kolom bigint dan ditolak PostgreSQL. */
    public function test_filter_id_yang_bukan_angka_diabaikan(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        foreach (['abc', '1.5', "' OR 1=1 --", '99999999999999999999'] as $id) {
            $q = rawurlencode($id);

            $this->get("/wms/master/products?category_id={$q}")->assertOk();
            $this->get("/wms/inventory?category_id={$q}&location_id={$q}")->assertOk();
            $this->get("/wms/admin/users?role_id={$q}")->assertOk();
            $this->get("/wms/admin/activity-log?user_id={$q}&warehouse_id={$q}")->assertOk();
        }
    }

    /** Kolom level bertipe tinyint: `?level=ZZZ` dulu ditolak PostgreSQL. */
    public function test_filter_level_rak_yang_bukan_angka_diabaikan(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        foreach (['ZZZ', '99999999999999999999', '-1', '0'] as $level) {
            $this->get('/wms/master/locations?level='.rawurlencode($level))
                ->assertOk()
                ->assertViewHas('filters', fn ($f) => $f['level'] === null);
        }

        $this->get('/wms/master/locations?level=3')
            ->assertOk()
            ->assertViewHas('filters', fn ($f) => $f['level'] === 3);
    }

    /* ------------------------------------------------------ Tanggal mustahil */

    public function test_tanggal_filter_yang_mustahil_diabaikan(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        foreach (['2026-13-45', 'abc', '-1', "' OR 1=1 --", '99999999999999999999'] as $tanggal) {
            $q = rawurlencode($tanggal);

            $this->get("/wms/inbound/history?from={$q}&to={$q}")->assertOk();
            $this->get("/wms/admin/activity-log?dari={$q}&sampai={$q}")->assertOk();
            $this->get("/wms/inventory?production_date={$q}")->assertOk();
        }
    }

    public function test_filter_tanggal_hanya_menerima_tanggal_yang_ada(): void
    {
        $this->assertSame('2026-09-15', FilterTanggal::bersih('2026-09-15'));
        $this->assertSame('2028-02-29', FilterTanggal::bersih('2028-02-29'));

        foreach (['2026-02-31', '2026-13-01', '15-09-2026', 'next monday', '', null, ['2026-09-15'], 20260915] as $salah) {
            $this->assertNull(FilterTanggal::bersih($salah), var_export($salah, true));
        }
    }

    /* ------------------------------------- Pemeriksaan lanjutan di FormRequest */

    /**
     * Pemeriksaan wilayah customer dulu tetap mencari `Customer::find('abc')`
     * walau aturan `integer` sudah gagal — PostgreSQL menolak 'abc' untuk
     * kolom bigint dan Sales melihat halaman 500, bukan pesan validasi.
     */
    public function test_customer_id_rusak_pada_pesanan_sales_dijawab_validasi(): void
    {
        $this->loginAs(Role::SALES);

        foreach (['abc', '1.5', '99999999999999999999'] as $id) {
            $this->post('/sales/new-order', ['customer_id' => $id, 'order_source' => 'manual'])
                ->assertStatus(302)
                ->assertSessionHasErrors('customer_id');
        }

        $this->post('/sales/new-order', ['customer_id' => ['x' => 'y'], 'order_source' => 'manual'])
            ->assertStatus(302)
            ->assertSessionHasErrors('customer_id');
    }

    /**
     * prepareForValidation() berjalan sebelum aturan `string`: isian array
     * dulu langsung sampai ke trim() dan menjatuhkan formulir master data.
     */
    public function test_isian_array_di_formulir_master_data_dijawab_validasi(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);
        $array = ['x' => ['y']];

        $this->post('/wms/master/customers', ['code' => $array, 'territory_code' => $array, 'phone' => $array, 'name' => 'Toko Uji'])
            ->assertStatus(302)->assertSessionHasErrors('code');

        $this->post('/wms/master/products', ['name' => $array, 'sku' => $array, 'pack_size' => $array])
            ->assertStatus(302)->assertSessionHasErrors('sku');

        $this->post('/wms/master/locations', ['code' => $array, 'zone' => $array])
            ->assertStatus(302)->assertSessionHasErrors('code');

        $this->post('/wms/inventory/stocks', ['sku' => $array, 'location_code' => $array, 'batch_no' => $array])
            ->assertStatus(302)->assertSessionHasErrors('sku');
    }

    public function test_id_rusak_pada_pesanan_internal_dijawab_validasi(): void
    {
        // Pesanan internal: Super Admin & Manager (Permission::OUTBOUND_ORDER_INTERNAL).
        $this->loginAs(Role::MANAGER);

        $this->post('/wms/outbound/new-order', [
            'action' => 'draft', 'customer_id' => 'abc', 'sales_user_id' => '1.5', 'order_source' => 'manual',
        ])->assertStatus(302)->assertSessionHasErrors(['customer_id', 'sales_user_id']);
    }
}
