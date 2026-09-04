<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Autentikasi — PRD §6.1 F-AUTH-01/02/03/04/05.
 *
 * Verifikasi Anti-Bot (F-AUTH-02, reCAPTCHA) menyatu di POST /login yang sama
 * — tidak ada halaman verifikasi terpisah setelah password. Google siteverify
 * di-fake di setUp() supaya suite ini TIDAK PERNAH memanggil jaringan
 * sungguhan, terlepas dari RECAPTCHA_SECRET_KEY terisi atau tidak di .env
 * developer yang menjalankannya.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Satu fake untuk seluruh suite (Http::fake() memakai aturan pertama
        // yang cocok — memanggilnya lagi di dalam test untuk "override" TIDAK
        // menimpa ini, cuma menambah aturan yang tidak pernah tercapai).
        // Token 'fake-token-invalid' sengaja dianggap ditolak Google, supaya
        // test kegagalan cukup ganti nilai token, bukan mendefinisikan fake baru.
        Http::fake([
            'www.google.com/recaptcha/*' => fn ($request) => Http::response([
                'success' => ($request->data()['response'] ?? null) !== 'fake-token-invalid',
            ]),
        ]);
    }

    private function makeUser(string $roleSlug, array $overrides = []): User
    {
        return User::factory()
            ->withRole($roleSlug)
            ->create(array_merge(['password' => Hash::make('rahasia123')], $overrides));
    }

    /** Payload POST /login lengkap dengan token reCAPTCHA "valid" (di-fake di setUp()). */
    private function loginPayload(string $email, string $password, array $overrides = []): array
    {
        return array_merge([
            'email' => $email,
            'password' => $password,
            'g-recaptcha-response' => 'fake-token-valid',
        ], $overrides);
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

        $this->post('/login', $this->loginPayload($user->email, 'rahasia123'))
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

            $this->post('/login', $this->loginPayload($user->email, 'rahasia123'))
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

        $this->post('/login', $this->loginPayload($user->email, 'salah'))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, $user->fresh()->failed_login_attempts);
        $this->assertDatabaseHas('login_attempts', [
            'email' => $user->email,
            'failure_reason' => 'wrong_password',
        ]);
    }

    public function test_akun_nonaktif_tidak_bisa_login(): void
    {
        $user = $this->makeUser(Role::SALES, ['is_active' => false]);

        $this->post('/login', $this->loginPayload($user->email, 'rahasia123'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /* ------------------------------------------------------- Verifikasi anti-bot */

    /** PRD §6.1 F-AUTH-02: token kosong/tidak dicentang diperlakukan sama seperti kredensial salah. */
    public function test_login_gagal_jika_recaptcha_kosong(): void
    {
        $user = $this->makeUser(Role::SALES);

        $this->post('/login', $this->loginPayload($user->email, 'rahasia123', ['g-recaptcha-response' => '']))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(1, $user->fresh()->failed_login_attempts);
        $this->assertDatabaseHas('login_attempts', [
            'email' => $user->email,
            'failure_reason' => 'recaptcha_failed',
        ]);
    }

    /** Google menolak token (kedaluwarsa/tidak valid) -> diperlakukan sama seperti kredensial salah. */
    public function test_login_gagal_jika_recaptcha_ditolak_google(): void
    {
        $user = $this->makeUser(Role::SALES);

        $this->post('/login', $this->loginPayload($user->email, 'rahasia123', [
            'g-recaptcha-response' => 'fake-token-invalid',
        ]))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(1, $user->fresh()->failed_login_attempts);
        $this->assertDatabaseHas('login_attempts', [
            'email' => $user->email,
            'failure_reason' => 'recaptcha_failed',
        ]);
    }

    /* ---------------------------------------------------- Progressive lockout */

    public function test_akun_terkunci_setelah_3_kali_gagal(): void
    {
        $user = $this->makeUser(Role::SALES);

        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', $this->loginPayload($user->email, 'salah'));
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

    /**
     * PRD §6.1 F-AUTH-03: durasi lockout meningkat tiap terkunci LAGI setelah
     * unlock — tetapi tiap putaran tetap menuntut 3 kali gagal.
     *
     * Versi awal test ini hanya mengirim SATU kali salah lalu berharap akun
     * terkunci, sehingga ia mengunci cacat sebagai perilaku yang benar:
     * penghitung tidak pernah dimulai ulang, jadi sisa hitungan 3 dari putaran
     * sebelumnya membuat satu kesalahan ketik langsung mengunci akun lagi.
     */
    public function test_lockout_meningkat_progresif_setelah_unlock(): void
    {
        $user = $this->makeUser(Role::SALES, [
            'failed_login_attempts' => 3,
            'lockout_count' => 1,
            'locked_until' => now()->subMinute(), // sudah lewat, akun sudah unlock
        ]);

        // Dua kali salah BELUM boleh mengunci: putaran barunya dimulai dari nol.
        $this->post('/login', $this->loginPayload($user->email, 'salah'));
        $this->post('/login', $this->loginPayload($user->email, 'salah'));

        $user->refresh();
        $this->assertSame(1, $user->lockout_count, 'Dua kali gagal belum boleh mengunci akun.');
        $this->assertTrue($user->locked_until === null || $user->locked_until->isPast());

        // Yang ketiga barulah mengunci, dan durasinya naik jadi 10 menit.
        $this->post('/login', $this->loginPayload($user->email, 'salah'));

        $user->refresh();
        $this->assertSame(2, $user->lockout_count);
        $this->assertEqualsWithDelta(10, now()->diffInMinutes($user->locked_until), 1);
    }

    /**
     * Cacat yang ditemukan saat audit: sesudah kunci berakhir, penghitung
     * gagal tetap 3 — sehingga satu kali salah ketik langsung menyentuh ambang
     * dan mengunci akun lagi, dengan durasi yang terus naik. Bagi pengguna,
     * "3 kali salah" berubah jadi "sekali salah" selamanya.
     */
    public function test_penghitung_gagal_dimulai_ulang_setelah_kunci_berakhir(): void
    {
        $user = $this->makeUser(Role::SALES, [
            'failed_login_attempts' => 3,
            'lockout_count' => 1,
            'locked_until' => now()->subMinute(),
        ]);

        $this->post('/login', $this->loginPayload($user->email, 'salah'));

        $user->refresh();
        $this->assertSame(1, $user->failed_login_attempts, 'Putaran baru harus dimulai dari satu, bukan empat.');
        $this->assertSame(1, $user->lockout_count, 'Belum boleh terkunci lagi.');
    }

    public function test_satu_kali_salah_setelah_unlock_masih_bisa_login_dengan_sandi_benar(): void
    {
        $user = $this->makeUser(Role::SALES, [
            'failed_login_attempts' => 3,
            'lockout_count' => 1,
            'locked_until' => now()->subMinute(),
        ]);

        $this->post('/login', $this->loginPayload($user->email, 'salah'));
        $this->post('/login', $this->loginPayload($user->email, 'rahasia123'))
            ->assertRedirect('/sales/dashboard');

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame(0, $user->fresh()->failed_login_attempts);
    }

    /* ------------------------------------------------------- Session tracking */

    public function test_login_berhasil_membuat_baris_user_sessions(): void
    {
        $user = $this->makeUser(Role::SUPER_ADMIN);

        $response = $this->post('/login', $this->loginPayload($user->email, 'rahasia123'));

        $token = $response->getCookie('device_token', decrypt: false)?->getValue();

        $this->assertNotNull($token);
        $this->assertDatabaseHas('user_sessions', ['user_id' => $user->id, 'session_id' => $token]);
    }

    /** PRD §6.1 F-AUTH-04: device ke-3 mengevict sesi paling tua. */
    public function test_login_device_ketiga_mengevict_sesi_tertua(): void
    {
        $user = $this->makeUser(Role::SUPER_ADMIN);

        $firstToken = $this->post('/login', $this->loginPayload($user->email, 'rahasia123'))
            ->getCookie('device_token', decrypt: false)?->getValue();

        $this->post('/login', $this->loginPayload($user->email, 'rahasia123'));
        $this->post('/login', $this->loginPayload($user->email, 'rahasia123'));

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
