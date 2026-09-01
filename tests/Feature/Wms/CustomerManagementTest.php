<?php

namespace Tests\Feature\Wms;

use App\Models\Customer;
use App\Models\PaymentTerm;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use Database\Seeders\PaymentTermSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Master Pelanggan — PRD §6.2 F-MASTER-06.
 *
 * Dua keputusan desain yang dijaga di sini:
 *   1. Tidak ada alur pengajuan/persetujuan pelanggan (dihapus di PRD v1.1).
 *   2. Syarat pembayaran & limit kredit TIDAK menempel di pelanggan — keduanya
 *      tinggal di `payment_terms` karena dipilih per-pesanan oleh Sales.
 */
class CustomerManagementTest extends TestCase
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

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'IDI10101',
            'ship_to_code' => '1061600017',
            'name' => 'PT PANDU BIO POLIMER',
            'phone' => '6289531435435',
            'contact_name' => '',
            'email' => 'MARKETING@PANDUBIOPOLIMER.COM',
            'address' => 'JL RAYA PONDOK GEDE NO. 17 A, RT 002 RW 002, DUKUH KRAMAT JATI',
            'address_2' => 'JAKARTA TIMUR, DKI JAKARTA',
            'territory_code' => 'PROJECT',
            'is_active' => 1,
        ], $overrides);
    }

    /* ---------------------------------------------------------------- Akses */

    public function test_super_admin_dan_manager_dapat_membuka_master_pelanggan(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::MANAGER] as $slug) {
            $this->loginAs($slug);
            $this->get('/wms/master/customers')->assertOk()->assertViewHas('customers');
        }
    }

    public function test_role_operasional_ditolak(): void
    {
        foreach ([Role::LOGISTICS, Role::PRODUCTION, Role::WAREHOUSE_OPERATOR] as $slug) {
            $this->loginAs($slug);
            $this->get('/wms/master/customers')->assertForbidden();
        }
    }

    /* --------------------------------------------------------------- Create */

    public function test_membuat_pelanggan_baru(): void
    {
        $actor = $this->loginAs(Role::SUPER_ADMIN);

        $this->post('/wms/master/customers', $this->validPayload())
            ->assertRedirect(route('wms.customers.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('customers', [
            'code' => 'IDI10101',
            'ship_to_code' => '1061600017',
            'name' => 'PT PANDU BIO POLIMER',
            'created_by' => $actor->id,
            'is_active' => true,
        ]);
    }

    /** PRD v1.1: pelanggan langsung aktif, tanpa antrean persetujuan. */
    public function test_pelanggan_baru_langsung_aktif_tanpa_persetujuan(): void
    {
        $this->loginAs(Role::MANAGER);

        $this->post('/wms/master/customers', $this->validPayload());

        $this->assertTrue(Customer::where('code', 'IDI10101')->value('is_active'));
    }

    public function test_kode_pelanggan_harus_unik(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);
        Customer::factory()->create(['code' => 'IDI10101']);

        $this->post('/wms/master/customers', $this->validPayload())
            ->assertSessionHasErrors('code');
    }

    /** Ship-to Code hanya dimiliki sebagian pelanggan (4 dari 9 pada data ERP). */
    public function test_ship_to_code_boleh_kosong(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        $this->post('/wms/master/customers', $this->validPayload(['ship_to_code' => '']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', ['code' => 'IDI10101', 'ship_to_code' => null]);
    }

    public function test_alamat_wajib_diisi(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        $this->post('/wms/master/customers', $this->validPayload(['address' => '']))
            ->assertSessionHasErrors('address');
    }

    public function test_email_harus_valid(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        $this->post('/wms/master/customers', $this->validPayload(['email' => 'bukan-email']))
            ->assertSessionHasErrors('email');
    }

    /** Nomor dari ERP kadang mengandung spasi/strip; disimpan sebagai digit saja. */
    public function test_nomor_telepon_dinormalkan_jadi_digit(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        $this->post('/wms/master/customers', $this->validPayload(['phone' => '+62 895-3143-5435']))
            ->assertSessionHasNoErrors();

        $this->assertSame('6289531435435', Customer::where('code', 'IDI10101')->value('phone'));
    }

    /* --------------------------------------------------------------- Alamat */

    /** Address & Address 2 disimpan terpisah, tapi tampil sebagai satu alamat. */
    public function test_alamat_tersimpan_terpisah_tapi_tampil_digabung(): void
    {
        $customer = Customer::factory()->create([
            'address' => 'JL. PAMOYANAN NO. 15 RT 01 RW 01',
            'address_2' => 'MEKARMANIK, CIMENYAN',
        ]);

        $this->assertSame('JL. PAMOYANAN NO. 15 RT 01 RW 01', $customer->address);
        $this->assertSame('MEKARMANIK, CIMENYAN', $customer->address_2);
        $this->assertSame(
            'JL. PAMOYANAN NO. 15 RT 01 RW 01, MEKARMANIK, CIMENYAN',
            $customer->full_address
        );
    }

    public function test_alamat_gabungan_tidak_menyisakan_koma_saat_alamat_2_kosong(): void
    {
        $customer = Customer::factory()->create([
            'address' => 'JL KAMAL MUARA VII SENTRA INDUSTRI TERPADU TAHAP 3',
            'address_2' => null,
        ]);

        $this->assertSame('JL KAMAL MUARA VII SENTRA INDUSTRI TERPADU TAHAP 3', $customer->full_address);
    }

    /* --------------------------------------------------------------- Update */

    public function test_menyunting_pelanggan(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);
        $customer = Customer::factory()->create(['code' => 'IDI10101']);

        $this->put("/wms/master/customers/{$customer->id}", $this->validPayload([
            'name' => 'PT NAMA DIPERBARUI',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('PT NAMA DIPERBARUI', $customer->fresh()->name);
    }

    /* -------------------------------------------------------- Toggle status */

    public function test_menonaktifkan_pelanggan_tidak_menghapus_datanya(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);
        $customer = Customer::factory()->create();

        $this->patch("/wms/master/customers/{$customer->id}/status")
            ->assertSessionHas('success');

        $customer->refresh();

        $this->assertFalse($customer->is_active);
        // Masih direferensikan pesanan/surat jalan/tagihan lama.
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
    }

    /** Hanya pelanggan aktif yang boleh muncul di form Buat Pesanan (PRD §5.2). */
    public function test_scope_active_menyaring_pelanggan_nonaktif(): void
    {
        Customer::factory()->create(['name' => 'PT AKTIF']);
        Customer::factory()->inactive()->create(['name' => 'PT NONAKTIF']);

        $names = Customer::active()->pluck('name');

        $this->assertContains('PT AKTIF', $names);
        $this->assertNotContains('PT NONAKTIF', $names);
    }

    /* ------------------------------------------------------- Filter & cari */

    public function test_pencarian_menyaring_berdasarkan_nama_dan_kode(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);

        Customer::factory()->create(['code' => 'IDI10101', 'name' => 'PT PANDU BIO POLIMER']);
        Customer::factory()->create(['code' => 'IDI10102', 'name' => 'CV PUTRI JAYA MANDIRI']);

        $names = $this->get('/wms/master/customers?search=PANDU')
            ->viewData('customers')->pluck('name');

        $this->assertContains('PT PANDU BIO POLIMER', $names);
        $this->assertNotContains('CV PUTRI JAYA MANDIRI', $names);
    }

    /* ------------------------------- Termin pembayaran di tabel terpisah */

    /**
     * Syarat pembayaran & limit kredit TIDAK boleh menempel di pelanggan.
     *
     * Keputusan bisnis: termin dipilih Sales per-pesanan, bukan sifat tetap
     * pelanggan — satu pelanggan bisa memakai termin berbeda antar pesanan.
     * Test ini menjaga agar kolom tersebut tidak menyelinap kembali.
     */
    public function test_tabel_pelanggan_tidak_menyimpan_termin_dan_limit_kredit(): void
    {
        $columns = Schema::getColumnListing('customers');

        foreach (['payment_term', 'default_payment_term', 'credit_limit'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $columns,
                "Kolom [{$forbidden}] tidak boleh ada di tabel customers — termin tinggal di payment_terms."
            );
        }
    }

    public function test_termin_pembayaran_tersedia_untuk_dropdown_sales(): void
    {
        $this->seed(PaymentTermSeeder::class);

        $terms = PaymentTerm::active()->get();

        $this->assertCount(5, $terms);
        $this->assertSame('cash', $terms->first()->code);
        $this->assertSame(90, $terms->firstWhere('code', 'tempo_90')->days);
        $this->assertTrue($terms->firstWhere('code', 'cash')->isImmediate());
        $this->assertFalse($terms->firstWhere('code', 'tempo_30')->isImmediate());
    }
}
