<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Autentikasi — PRD §6.1 F-AUTH-01/03/04/05.
 *
 * Verifikasi Anti-Bot (F-AUTH-02, reCAPTCHA) belum terpasang — lihat catatan di
 * AuthController. Login berhasil di sini langsung menuju dashboard; tidak ada
 * halaman verifikasi terpisah setelah password pada desain PRD v1.2.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $roleSlug, array $overrides = []): User
    {
        return User::factory()
            ->withRole($roleSlug)
            ->create(array_merge(['password' => Hash::make('rahasia123')], $overrides));
    }

    /**
     * Login "sebagai device tertentu": actingAs() + baris user_sessions +
     * cookie device_token yang cocok, meniru apa yang AuthController::login()
     * lakukan pada login sungguhan. Dipakai untuk test yang butuh sesi VALID
     * (bukan test terhadap endpoint /login itu sendiri).
     */
    private function loginAsDevice(User $user): string
    {
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

        return $token;
    }

    /* ---------------------------------------------------------------- Akses */

    public function test_tamu_dapat_melihat_halaman_login(): void
    {
        $this->get('/login')->assertOk()->assertSee('Masuk ke Sistem');
    }

    public function test_tamu_diarahkan_ke_login_saat_akses_wms_tanpa_login(): void
    {
        $this->get('/wms/dashboard/admin')->assertRedirect('/login');
    }

    public function test_tamu_diarahkan_ke_login_saat_akses_sales_tanpa_login(): void
    {
        $this->get('/sales/dashboard')->assertRedirect('/login');
    }

    public function test_user_yang_sudah_login_diarahkan_menjauh_dari_halaman_login(): void
    {
        $user = $this->makeUser(Role::SUPER_ADMIN);
        $this->loginAsDevice($user);

        $this->get('/login')->assertRedirect('/wms/dashboard/admin');
    }

    /* --------------------------------------------------------------- Login */

    public function test_login_berhasil_dengan_kredensial_benar(): void
    {
        $user = $this->makeUser(Role::SUPER_ADMIN);

        $this->post('/login', ['email' => $user->email, 'password' => 'rahasia123'])
            ->assertRedirect('/wms/dashboard/admin');

        $this->assertDatabaseHas('login_attempts', [
            'email' => $user->email,
            'is_successful' => true,
        ]);
    }

    /** PRD §6.1 F-AUTH-05: routing per role. */
    public function test_login_mengarahkan_ke_portal_sesuai_role(): void
    {
        $cases = [
            Role::SUPER_ADMIN => '/wms/dashboard/admin',
            Role::MANAGER => '/wms/dashboard/admin',
            Role::LOGISTICS => '/wms/dashboard/admin',
            Role::PRODUCTION => '/wms/dashboard/produksi',
            Role::WAREHOUSE_OPERATOR => '/wms/dashboard/operator',
            Role::SALES => '/sales/dashboard',
        ];

        foreach ($cases as $slug => $expectedPath) {
            $user = $this->makeUser($slug, ['email' => "{$slug}@berger.co.id"]);

            $this->post('/login', ['email' => $user->email, 'password' => 'rahasia123'])
                ->assertRedirect($expectedPath);
        }
    }

    public function test_login_gagal_email_tidak_terdaftar(): void
    {
        $this->post('/login', ['email' => 'tidak-ada@berger.co.id', 'password' => 'apasaja'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseHas('login_attempts', [
            'email' => 'tidak-ada@berger.co.id',
            'is_successful' => false,
        ]);
    }

    public function test_login_gagal_password_salah_menaikkan_counter(): void
    {
        $user = $this->makeUser(Role::SALES);

        $this->post('/login', ['email' => $user->email, 'password' => 'salah'])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, $user->fresh()->failed_login_attempts);
    }

    public function test_akun_nonaktif_tidak_bisa_login(): void
    {
        $user = $this->makeUser(Role::SALES, ['is_active' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'rahasia123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /* ---------------------------------------------------- Progressive lockout */

    public function test_akun_terkunci_setelah_3_kali_gagal(): void
    {
        $user = $this->makeUser(Role::SALES);

        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'salah']);
        }

        $user->refresh();

        $this->assertTrue($user->isCurrentlyLocked());
        $this->assertSame(1, $user->lockout_count);
        $this->assertEqualsWithDelta(5, now()->diffInMinutes($user->locked_until), 1);
    }

    public function test_akun_terkunci_menolak_password_benar_sekalipun(): void
    {
        $user = $this->makeUser(Role::SALES, [
            'failed_login_attempts' => 3,
            'lockout_count' => 1,
            'locked_until' => now()->addMinutes(5),
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'rahasia123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /** PRD §6.1 F-AUTH-03: durasi lockout meningkat tiap terkunci lagi setelah unlock. */
    public function test_lockout_meningkat_progresif_setelah_unlock(): void
    {
        $user = $this->makeUser(Role::SALES, [
            'failed_login_attempts' => 3,
            'lockout_count' => 1,
            'locked_until' => now()->subMinute(), // sudah lewat, akun sudah unlock
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'salah']);

        $user->refresh();
        $this->assertSame(2, $user->lockout_count);
        $this->assertEqualsWithDelta(10, now()->diffInMinutes($user->locked_until), 1);
    }

    /* ------------------------------------------------------- Session tracking */

    public function test_login_berhasil_membuat_baris_user_sessions(): void
    {
        $user = $this->makeUser(Role::SUPER_ADMIN);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'rahasia123']);

        $token = $response->getCookie('device_token', decrypt: false)?->getValue();

        $this->assertNotNull($token);
        $this->assertDatabaseHas('user_sessions', ['user_id' => $user->id, 'session_id' => $token]);
    }

    /** PRD §6.1 F-AUTH-04: device ke-3 mengevict sesi paling tua. */
    public function test_login_device_ketiga_mengevict_sesi_tertua(): void
    {
        $user = $this->makeUser(Role::SUPER_ADMIN);

        $firstToken = $this->post('/login', ['email' => $user->email, 'password' => 'rahasia123'])
            ->getCookie('device_token', decrypt: false)?->getValue();

        $this->post('/login', ['email' => $user->email, 'password' => 'rahasia123']);
        $this->post('/login', ['email' => $user->email, 'password' => 'rahasia123']);

        $this->assertSame(2, UserSession::where('user_id', $user->id)->count());
        $this->assertDatabaseMissing('user_sessions', ['session_id' => $firstToken]);
    }

    public function test_sesi_yang_sudah_dievict_memaksa_logout_pada_request_berikutnya(): void
    {
        $user = $this->makeUser(Role::SUPER_ADMIN);

        // actingAs tanpa baris user_sessions yang cocok = meniru device yang
        // baris pelacakannya sudah dihapus karena device lain login (evicted).
        $this->withUnencryptedCookies(['device_token' => Str::random(64)]);
        $this->actingAs($user);

        $this->get('/wms/dashboard/admin')
            ->assertRedirect('/login')
            ->assertSessionHas('status');
    }

    public function test_sesi_idle_lebih_dari_1_jam_dipaksa_logout(): void
    {
        $user = $this->makeUser(Role::SUPER_ADMIN);
        $token = $this->loginAsDevice($user);

        UserSession::where('session_id', $token)->update([
            'last_activity_at' => now()->subMinutes(61),
        ]);

        $this->get('/wms/dashboard/admin')
            ->assertRedirect('/login')
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('user_sessions', ['session_id' => $token]);
    }

    public function test_logout_menghapus_baris_user_sessions(): void
    {
        $user = $this->makeUser(Role::SUPER_ADMIN);
        $token = $this->loginAsDevice($user);

        $this->post('/logout')->assertRedirect('/login');

        $this->assertDatabaseMissing('user_sessions', ['session_id' => $token]);
    }

    /* -------------------------------------------------------- Batas portal */

    public function test_sales_tidak_bisa_akses_portal_wms(): void
    {
        $user = $this->makeUser(Role::SALES);
        $this->loginAsDevice($user);

        $this->get('/wms/dashboard/admin')->assertForbidden();
    }

    public function test_non_sales_tidak_bisa_akses_portal_sales(): void
    {
        $user = $this->makeUser(Role::SUPER_ADMIN);
        $this->loginAsDevice($user);

        $this->get('/sales/dashboard')->assertForbidden();
    }
}
