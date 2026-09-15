<?php

namespace Tests\Feature\Auth;

use App\Models\LoginAttempt;
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
 *
 * Bagian lockout ditulis ulang pada audit keamanan pra-go-live (PRD v1.5):
 * kunci email+IP, kunci akun hanya dari IP asing, captcha tidak menyentuh akun.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Kunci rahasia diisi di sini, bukan dibaca dari .env: secret kosong
        // membuat verifikasi dianggap lulus di luar production, sehingga test
        // penolakan captcha dulu merah di mesin developer yang .env-nya kosong
        // dan hijau di CI. Hasil test tidak boleh bergantung pada .env siapa pun.
        config(['services.recaptcha.secret_key' => 'kunci-uji']);

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
        $this->post('/login', $this->loginPayload('tidak-ada@berger.co.id', 'apasaja'))
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
            ->assertSessionHasErrors(['email' => 'Akun Anda tidak aktif. Hubungi Administrator.']);

        $this->assertGuest();
    }

    /**
     * "Akun Anda tidak aktif" hanya boleh sampai ke orang yang TAHU sandinya.
     * Sebelumnya pesan itu dijawab kepada siapa pun yang mengetik emailnya,
     * sehingga daftar karyawan — termasuk yang sudah keluar — bisa dipetakan.
     */
    public function test_akun_nonaktif_dengan_sandi_salah_dijawab_pesan_generik(): void
    {
        $user = $this->makeUser(Role::SALES, ['is_active' => false]);

        $this->post('/login', $this->loginPayload($user->email, 'tebakan'))
            ->assertSessionHasErrors(['email' => 'Email atau Password salah.']);
    }

    /* ------------------------------------------------------- Verifikasi anti-bot */

    /**
     * PRD §6.1 F-AUTH-02 (v1.5): token kosong ditolak, tetapi TIDAK menaikkan
     * penghitung kunci akun — kalau menaikkan, tiga POST tanpa centang cukup
     * untuk mengunci akun siapa pun.
     */
    public function test_login_gagal_jika_recaptcha_kosong(): void
    {
        $user = $this->makeUser(Role::SALES);

        $this->post('/login', $this->loginPayload($user->email, 'rahasia123', ['g-recaptcha-response' => '']))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(0, $user->fresh()->failed_login_attempts);
        $this->assertDatabaseHas('login_attempts', [
            'email' => $user->email,
            'failure_reason' => 'recaptcha_failed',
        ]);
    }

    public function test_login_gagal_jika_recaptcha_ditolak_google(): void
    {
        $user = $this->makeUser(Role::SALES);

        $this->post('/login', $this->loginPayload($user->email, 'rahasia123', [
            'g-recaptcha-response' => 'fake-token-invalid',
        ]))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(0, $user->fresh()->failed_login_attempts);
        $this->assertDatabaseHas('login_attempts', [
            'email' => $user->email,
            'failure_reason' => 'recaptcha_failed',
        ]);
    }

    /** Serangan yang ditutup: mengunci akun orang lain tanpa tahu sandinya dan tanpa captcha. */
    public function test_penyerang_tanpa_captcha_tidak_bisa_mengunci_akun_orang_lain(): void
    {
        $korban = $this->makeUser(Role::LOGISTICS);

        for ($i = 0; $i < 10; $i++) {
            $this->dariIp('203.0.113.9')->post('/login', ['email' => $korban->email, 'password' => 'x']);
        }

        $this->dariIp('127.0.0.1')
            ->post('/login', $this->loginPayload($korban->email, 'rahasia123'))
            ->assertRedirect('/wms/dashboard/admin');
    }

    /* ---------------------------------------------------- Progressive lockout */

    private function dariIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    /** PRD §6.1 F-AUTH-03 (v1.5): 3 kali gagal mengunci pasangan email + IP itu. */
    public function test_tiga_kali_gagal_mengunci_email_dari_ip_itu(): void
    {
        $user = $this->makeUser(Role::SALES);

        for ($i = 0; $i < 3; $i++) {
            $this->dariIp('198.51.100.7')->post('/login', $this->loginPayload($user->email, 'salah'));
        }

        // Sandi benar sekalipun ditolak dari IP yang terkunci.
        $this->dariIp('198.51.100.7')
            ->post('/login', $this->loginPayload($user->email, 'rahasia123'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertStringContainsString('Terlalu banyak percobaan gagal', session('errors')->first('email'));

        // Akunnya sendiri TIDAK terkunci: pemiliknya masih bisa masuk dari tempat lain.
        $this->assertFalse($user->fresh()->isCurrentlyLocked());
        $this->dariIp('127.0.0.1')
            ->post('/login', $this->loginPayload($user->email, 'rahasia123'))
            ->assertRedirect('/sales/dashboard');
    }

    public function test_kunci_email_ip_berlangsung_5_menit_lalu_naik_jadi_10(): void
    {
        $user = $this->makeUser(Role::SALES);
        $gagalTiga = function () use ($user) {
            for ($i = 0; $i < 3; $i++) {
                $this->dariIp('198.51.100.7')->post('/login', $this->loginPayload($user->email, 'salah'));
            }
        };

        $gagalTiga();
        $this->travel(4)->minutes();
        $this->dariIp('198.51.100.7')->post('/login', $this->loginPayload($user->email, 'rahasia123'));
        $this->assertGuest();

        $this->travel(2)->minutes();
        $gagalTiga();
        $this->travel(9)->minutes();
        $this->dariIp('198.51.100.7')->post('/login', $this->loginPayload($user->email, 'rahasia123'));
        $this->assertGuest();

        $this->travel(2)->minutes();
        $this->dariIp('198.51.100.7')
            ->post('/login', $this->loginPayload($user->email, 'rahasia123'))
            ->assertRedirect('/sales/dashboard');
    }

    /**
     * Email yang tidak terdaftar dikunci dengan cara dan kalimat yang sama.
     * Kalau hanya email yang ada yang bisa "terkunci", pesan kunci itu sendiri
     * menjadi alat memeriksa email mana yang terdaftar.
     */
    public function test_email_terdaftar_dan_tidak_terdaftar_dijawab_sama_persis(): void
    {
        $user = $this->makeUser(Role::SALES);
        $jawaban = [];

        foreach ([$user->email, 'bukan-karyawan@berger.co.id'] as $email) {
            $pesan = [];
            for ($i = 0; $i < 4; $i++) {
                $this->post('/login', $this->loginPayload($email, 'salah'));
                $pesan[] = session('errors')->first('email');
            }
            $jawaban[] = $pesan;
        }

        $this->assertSame($jawaban[0], $jawaban[1]);
        $this->assertSame('Email atau Password salah.', $jawaban[0][0]);
        $this->assertStringContainsString('Terlalu banyak percobaan gagal', $jawaban[0][3]);
    }

    /** Tebak-sandi yang disebar ke banyak IP tetap berujung kunci AKUN. */
    public function test_gagal_dari_banyak_ip_asing_mengunci_akun(): void
    {
        $user = $this->makeUser(Role::SALES);

        for ($i = 0; $i < User::AMBANG_KUNCI_AKUN; $i++) {
            $this->dariIp('203.0.113.'.$i)->post('/login', $this->loginPayload($user->email, 'salah'));
        }

        $user->refresh();
        $this->assertTrue($user->isCurrentlyLocked());
        $this->assertSame(1, $user->lockout_count);
        $this->assertEqualsWithDelta(5, now()->diffInMinutes($user->locked_until), 1);

        // IP asing baru dengan sandi benar tetap ditolak.
        $this->dariIp('203.0.113.200')
            ->post('/login', $this->loginPayload($user->email, 'rahasia123'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /**
     * Pemilik akun yang masuk dari IP yang biasa ia pakai tidak ikut terkunci
     * oleh penyerang dari luar — itulah yang membuat kunci akun tidak bisa
     * dipakai untuk menghentikan gudang.
     */
    public function test_akun_terkunci_tetap_bisa_dimasuki_dari_ip_yang_dikenal(): void
    {
        $user = $this->makeUser(Role::SALES, [
            'failed_login_attempts' => User::AMBANG_KUNCI_AKUN,
            'lockout_count' => 1,
            'locked_until' => now()->addMinutes(5),
        ]);
        LoginAttempt::create([
            'email' => $user->email, 'ip_address' => '127.0.0.1', 'user_agent' => 'PHPUnit',
            'is_successful' => true, 'created_at' => now()->subDays(3),
        ]);

        $this->post('/login', $this->loginPayload($user->email, 'rahasia123'))
            ->assertRedirect('/sales/dashboard');
    }

    public function test_akun_terkunci_menolak_password_benar_dari_ip_asing(): void
    {
        $user = $this->makeUser(Role::SALES, [
            'failed_login_attempts' => User::AMBANG_KUNCI_AKUN,
            'lockout_count' => 1,
            'locked_until' => now()->addMinutes(5),
        ]);

        $this->post('/login', $this->loginPayload($user->email, 'rahasia123'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /** Gagal dari IP yang dikenal tidak menaikkan penghitung kunci AKUN. */
    public function test_gagal_dari_ip_dikenal_tidak_menaikkan_penghitung_akun(): void
    {
        $user = $this->makeUser(Role::SALES);
        LoginAttempt::create([
            'email' => $user->email, 'ip_address' => '127.0.0.1', 'user_agent' => 'PHPUnit',
            'is_successful' => true, 'created_at' => now()->subDay(),
        ]);

        $this->post('/login', $this->loginPayload($user->email, 'salah'));

        $this->assertSame(0, $user->fresh()->failed_login_attempts);
    }

    /**
     * PRD §6.1 F-AUTH-03: durasi kunci akun meningkat tiap terkunci LAGI
     * setelah unlock — tetapi tiap putaran tetap menuntut ambang penuh.
     */
    public function test_lockout_meningkat_progresif_setelah_unlock(): void
    {
        $user = $this->makeUser(Role::SALES, [
            'failed_login_attempts' => User::AMBANG_KUNCI_AKUN,
            'lockout_count' => 1,
            'locked_until' => now()->subMinute(), // sudah lewat, akun sudah unlock
        ]);

        for ($i = 0; $i < User::AMBANG_KUNCI_AKUN - 1; $i++) {
            $this->dariIp('203.0.113.'.$i)->post('/login', $this->loginPayload($user->email, 'salah'));
        }

        $user->refresh();
        $this->assertSame(1, $user->lockout_count, 'Putaran baru belum mencapai ambang.');
        $this->assertTrue($user->locked_until === null || $user->locked_until->isPast());

        $this->dariIp('203.0.113.250')->post('/login', $this->loginPayload($user->email, 'salah'));

        $user->refresh();
        $this->assertSame(2, $user->lockout_count);
        $this->assertEqualsWithDelta(10, now()->diffInMinutes($user->locked_until), 1);
    }

    /**
     * Cacat yang ditemukan saat audit: sesudah kunci berakhir, penghitung
     * gagal tetap di ambang — sehingga satu kali salah langsung mengunci akun
     * lagi, dengan durasi yang terus naik.
     */
    public function test_penghitung_gagal_dimulai_ulang_setelah_kunci_berakhir(): void
    {
        $user = $this->makeUser(Role::SALES, [
            'failed_login_attempts' => User::AMBANG_KUNCI_AKUN,
            'lockout_count' => 1,
            'locked_until' => now()->subMinute(),
        ]);

        $this->post('/login', $this->loginPayload($user->email, 'salah'));

        $user->refresh();
        $this->assertSame(1, $user->failed_login_attempts, 'Putaran baru harus dimulai dari satu.');
        $this->assertSame(1, $user->lockout_count, 'Belum boleh terkunci lagi.');
    }

    public function test_satu_kali_salah_setelah_unlock_masih_bisa_login_dengan_sandi_benar(): void
    {
        $user = $this->makeUser(Role::SALES, [
            'failed_login_attempts' => User::AMBANG_KUNCI_AKUN,
            'lockout_count' => 1,
            'locked_until' => now()->subMinute(),
        ]);

        $this->post('/login', $this->loginPayload($user->email, 'salah'));
        $this->post('/login', $this->loginPayload($user->email, 'rahasia123'))
            ->assertRedirect('/sales/dashboard');

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame(0, $user->fresh()->failed_login_attempts);
    }

    /** Batas POST /login per IP di Laravel sendiri, tidak bergantung pada nginx. */
    public function test_post_login_dibatasi_per_ip(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->dariIp('192.0.2.1')->post('/login', ['email' => "x{$i}@contoh.id", 'password' => 'x']);
        }

        $this->dariIp('192.0.2.1')->post('/login', ['email' => 'lagi@contoh.id', 'password' => 'x'])
            ->assertStatus(429);

        $this->dariIp('192.0.2.2')->post('/login', ['email' => 'lain@contoh.id', 'password' => 'x'])
            ->assertStatus(302);
    }

    /* ------------------------------------------------------- Session tracking */

    /** Akun yang dinonaktifkan di tengah shift berhenti di permintaan berikutnya. */
    public function test_akun_yang_dinonaktifkan_dipaksa_keluar_pada_request_berikutnya(): void
    {
        $user = $this->makeUser(Role::LOGISTICS);
        $this->loginAsDevice($user);

        $user->forceFill(['is_active' => false])->save();

        $this->get('/wms/dashboard/admin')
            ->assertRedirect('/login')
            ->assertSessionHas('status', 'Akun Anda dinonaktifkan. Hubungi Administrator.');

        $this->assertSame(0, UserSession::where('user_id', $user->id)->count());
    }

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
