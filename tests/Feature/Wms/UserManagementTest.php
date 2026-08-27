<?php

namespace Tests\Feature\Wms;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\CurrentActor;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Manajemen User — PRD §6.2 F-MASTER-01.
 *
 * Fokus utama: memastikan batas wewenang Manager vs Super Admin ditegakkan di
 * server, bukan hanya disembunyikan di tampilan.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->department = Department::factory()->create();
        $this->warehouse = Warehouse::factory()->create();
    }

    private function actingAsRole(string $slug): User
    {
        $user = User::factory()
            ->withRole($slug)
            ->create(['department_id' => $this->department->id]);

        CurrentActor::fake($user->load('role'));

        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'employee_id' => 'EMP-2026-777',
            'full_name' => 'Karyawan Baru',
            'email' => 'karyawan.baru@berger.co.id',
            'password' => 'rahasia123',
            'phone_number' => '081234000111',
            'role_id' => Role::where('slug', Role::LOGISTICS)->value('id'),
            'department_id' => $this->department->id,
            'warehouse_id' => $this->warehouse->id,
            'is_active' => 1,
        ], $overrides);
    }

    /* ---------------------------------------------------------------- Akses */

    public function test_super_admin_dapat_membuka_halaman_manajemen_user(): void
    {
        $this->actingAsRole(Role::SUPER_ADMIN);

        $this->get('/wms/admin/users')
            ->assertOk()
            ->assertViewHas('users');
    }

    public function test_manager_dapat_membuka_halaman_manajemen_user(): void
    {
        $this->actingAsRole(Role::MANAGER);

        $this->get('/wms/admin/users')->assertOk();
    }

    public function test_role_selain_super_admin_dan_manager_ditolak(): void
    {
        $this->actingAsRole(Role::LOGISTICS);

        $this->get('/wms/admin/users')->assertForbidden();
    }

    /* --------------------------------------------------------------- Create */

    public function test_super_admin_dapat_membuat_user_baru(): void
    {
        $actor = $this->actingAsRole(Role::SUPER_ADMIN);

        $this->post('/wms/admin/users', $this->validPayload())
            ->assertRedirect(route('wms.users.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'employee_id' => 'EMP-2026-777',
            'email' => 'karyawan.baru@berger.co.id',
            'full_name' => 'Karyawan Baru',
            'created_by' => $actor->id,
            'is_active' => true,
        ]);
    }

    public function test_password_disimpan_dalam_bentuk_hash(): void
    {
        $this->actingAsRole(Role::SUPER_ADMIN);

        $this->post('/wms/admin/users', $this->validPayload());

        $created = User::where('email', 'karyawan.baru@berger.co.id')->firstOrFail();

        $this->assertNotSame('rahasia123', $created->password);
        $this->assertTrue(Hash::check('rahasia123', $created->password));
    }

    public function test_email_dan_nik_harus_unik(): void
    {
        $this->actingAsRole(Role::SUPER_ADMIN);

        User::factory()->create([
            'email' => 'karyawan.baru@berger.co.id',
            'employee_id' => 'EMP-2026-777',
            'department_id' => $this->department->id,
        ]);

        $this->post('/wms/admin/users', $this->validPayload())
            ->assertSessionHasErrors(['email', 'employee_id']);
    }

    public function test_password_wajib_kombinasi_huruf_dan_angka(): void
    {
        $this->actingAsRole(Role::SUPER_ADMIN);

        $this->post('/wms/admin/users', $this->validPayload(['password' => 'abcdefgh']))
            ->assertSessionHasErrors('password');

        $this->post('/wms/admin/users', $this->validPayload(['password' => '12345678']))
            ->assertSessionHasErrors('password');
    }

    public function test_warehouse_boleh_kosong_yang_berarti_akses_semua_gudang(): void
    {
        $this->actingAsRole(Role::SUPER_ADMIN);

        $this->post('/wms/admin/users', $this->validPayload(['warehouse_id' => '']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'karyawan.baru@berger.co.id',
            'warehouse_id' => null,
        ]);
    }

    public function test_avatar_tersimpan_saat_diunggah(): void
    {
        Storage::fake('public');
        $this->actingAsRole(Role::SUPER_ADMIN);

        $this->post('/wms/admin/users', $this->validPayload([
            'avatar' => UploadedFile::fake()->image('foto.jpg'),
        ]))->assertSessionHasNoErrors();

        $created = User::where('email', 'karyawan.baru@berger.co.id')->firstOrFail();

        $this->assertNotNull($created->avatar_path);
        Storage::disk('public')->assertExists($created->avatar_path);
    }

    /* --------------------------------------------- Batas wewenang Manager */

    public function test_manager_tidak_dapat_membuat_akun_super_admin(): void
    {
        $this->actingAsRole(Role::MANAGER);

        $this->post('/wms/admin/users', $this->validPayload([
            'role_id' => Role::where('slug', Role::SUPER_ADMIN)->value('id'),
        ]))->assertSessionHasErrors('role_id');

        $this->assertDatabaseMissing('users', ['email' => 'karyawan.baru@berger.co.id']);
    }

    public function test_manager_tidak_dapat_mengubah_akun_super_admin(): void
    {
        $this->actingAsRole(Role::MANAGER);

        $superAdmin = User::factory()->superAdmin()->create([
            'department_id' => $this->department->id,
        ]);

        $this->put("/wms/admin/users/{$superAdmin->id}", $this->validPayload([
            'employee_id' => $superAdmin->employee_id,
            'email' => $superAdmin->email,
            'full_name' => 'Nama Dibajak',
            'password' => '',
        ]))->assertForbidden();

        $this->assertNotSame('Nama Dibajak', $superAdmin->fresh()->full_name);
    }

    public function test_manager_tidak_dapat_menonaktifkan_akun_super_admin(): void
    {
        $this->actingAsRole(Role::MANAGER);

        $superAdmin = User::factory()->superAdmin()->create([
            'department_id' => $this->department->id,
        ]);

        $this->patch("/wms/admin/users/{$superAdmin->id}/status")->assertForbidden();

        $this->assertTrue($superAdmin->fresh()->is_active);
    }

    public function test_manager_tetap_dapat_mengelola_role_non_super_admin(): void
    {
        $this->actingAsRole(Role::MANAGER);

        $this->post('/wms/admin/users', $this->validPayload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'karyawan.baru@berger.co.id']);
    }

    /* --------------------------------------------------------------- Update */

    public function test_update_tanpa_password_tidak_mengubah_password_lama(): void
    {
        $this->actingAsRole(Role::SUPER_ADMIN);

        $target = User::factory()->withRole(Role::SALES)->create([
            'department_id' => $this->department->id,
            'password' => Hash::make('sandilama123'),
        ]);

        $originalHash = $target->password;

        $this->put("/wms/admin/users/{$target->id}", $this->validPayload([
            'employee_id' => $target->employee_id,
            'email' => $target->email,
            'full_name' => 'Nama Diperbarui',
            'password' => '',
        ]))->assertSessionHasNoErrors();

        $target->refresh();

        $this->assertSame('Nama Diperbarui', $target->full_name);
        $this->assertSame($originalHash, $target->password);
        $this->assertTrue(Hash::check('sandilama123', $target->password));
    }

    public function test_user_tidak_boleh_menjadi_atasan_dirinya_sendiri(): void
    {
        $this->actingAsRole(Role::SUPER_ADMIN);

        $target = User::factory()->withRole(Role::SALES)->create([
            'department_id' => $this->department->id,
        ]);

        $this->put("/wms/admin/users/{$target->id}", $this->validPayload([
            'employee_id' => $target->employee_id,
            'email' => $target->email,
            'password' => '',
            'manager_id' => $target->id,
        ]))->assertSessionHasErrors('manager_id');
    }

    /* --------------------------------------------------------- Toggle status */

    public function test_menonaktifkan_user_tidak_menghapus_datanya(): void
    {
        $this->actingAsRole(Role::SUPER_ADMIN);

        $target = User::factory()->withRole(Role::SALES)->create([
            'department_id' => $this->department->id,
        ]);

        $this->patch("/wms/admin/users/{$target->id}/status")
            ->assertSessionHas('success');

        $target->refresh();

        $this->assertFalse($target->is_active);
        // Baris tetap ada — riwayat kerja karyawan harus dapat ditelusuri.
        $this->assertDatabaseHas('users', ['id' => $target->id, 'deleted_at' => null]);
    }

    public function test_tidak_dapat_menonaktifkan_akun_sendiri(): void
    {
        $actor = $this->actingAsRole(Role::SUPER_ADMIN);

        $this->patch("/wms/admin/users/{$actor->id}/status")
            ->assertSessionHas('error');

        $this->assertTrue($actor->fresh()->is_active);
    }

    public function test_super_admin_terakhir_tidak_dapat_dinonaktifkan(): void
    {
        // Aktor adalah Manager agar aturan "tidak boleh menonaktifkan diri sendiri"
        // tidak ikut terpicu; yang diuji murni aturan Super Admin terakhir.
        // Manager memang tidak berwenang atas Super Admin, jadi aktor di sini
        // haruslah Super Admin lain — dibuat dulu, lalu dinonaktifkan.
        $actor = $this->actingAsRole(Role::SUPER_ADMIN);

        $lastActive = User::factory()->superAdmin()->create([
            'department_id' => $this->department->id,
        ]);

        // Nonaktifkan aktor lewat model agar hanya tersisa satu Super Admin aktif.
        $actor->update(['is_active' => false]);

        $this->patch("/wms/admin/users/{$lastActive->id}/status")
            ->assertSessionHas('error');

        $this->assertTrue($lastActive->fresh()->is_active);
    }

    /* -------------------------------------------------------- Filter & cari */

    public function test_pencarian_menyaring_berdasarkan_nama_email_dan_nik(): void
    {
        $this->actingAsRole(Role::SUPER_ADMIN);

        User::factory()->withRole(Role::SALES)->create([
            'full_name' => 'Siti Aminah',
            'employee_id' => 'EMP-2022-555',
            'department_id' => $this->department->id,
        ]);
        User::factory()->withRole(Role::SALES)->create([
            'full_name' => 'Joko Widodo',
            'employee_id' => 'EMP-2022-666',
            'department_id' => $this->department->id,
        ]);

        $response = $this->get('/wms/admin/users?search=Siti');

        $names = $response->viewData('users')->pluck('full_name');

        $this->assertContains('Siti Aminah', $names);
        $this->assertNotContains('Joko Widodo', $names);
    }

    public function test_filter_status_nonaktif_hanya_menampilkan_user_nonaktif(): void
    {
        $this->actingAsRole(Role::SUPER_ADMIN);

        User::factory()->withRole(Role::SALES)->inactive()->create([
            'full_name' => 'Sudah Resign',
            'department_id' => $this->department->id,
        ]);

        $response = $this->get('/wms/admin/users?status=inactive');

        $this->assertTrue(
            $response->viewData('users')->every(fn (User $u) => $u->is_active === false)
        );
    }
}
