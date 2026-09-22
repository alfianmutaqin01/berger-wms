<?php

namespace Tests\Feature\Auth;

use App\Mail\PermintaanLupaSandi;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\CurrentActor;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lupa sandi lewat admin, dan sandi dari admin sebagai sandi sementara.
 *
 * DUA HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * -------------------------------------------------
 * 1. FORMULIR LUPA SANDI TIDAK BOLEH MEMBEDAKAN EMAIL. Jawaban untuk email
 *    terdaftar dan tidak terdaftar harus identik; bedanya — sekecil apa pun —
 *    memetakan daftar email karyawan dari luar. PRD v1.5 menutup celah itu
 *    di halaman login.
 * 2. SANDI DARI ADMIN TIDAK BOLEH BERLAKU SELAMANYA. Kalau berlaku, admin
 *    tahu sandi orang itu tanpa batas waktu, dan log aktivitas tidak bisa
 *    lagi membedakan tindakan pemilik akun dari orang yang memakai sandinya.
 */
class LupaSandiTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $karawang;

    private Warehouse $surabaya;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->karawang = Warehouse::factory()->create(['code' => 'WH-01']);
        $this->surabaya = Warehouse::factory()->create(['code' => 'WH-03']);
    }

    private function akun(string $peran, ?Warehouse $gudang, array $ubah = []): User
    {
        return User::factory()->withRole($peran)->create(array_merge([
            'warehouse_id' => $gudang?->id,
            'is_active' => true,
        ], $ubah));
    }

    private function masuk(User $user): User
    {
        CurrentActor::fake($user->load('role'));

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

    private function minta(string $email)
    {
        return $this->post(route('password.lupa.kirim'), ['email' => $email]);
    }

    /* ======================================================== (a) Lupa sandi */

    public function test_halaman_login_menawarkan_lupa_sandi(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('password.lupa'), false)
            ->assertSee('Lupa sandi?');
    }

    /**
     * Yang dikabari: Super Admin seluruh gudang dan Manager di gudang akun
     * itu — cakupan yang sama dengan siapa yang boleh mengubah akunnya.
     * Manager gudang lain tidak berwenang, jadi tidak ikut dikabari.
     */
    public function test_permintaan_sampai_ke_admin_yang_berwenang_saja(): void
    {
        Mail::fake();

        $superAdmin = $this->akun(Role::SUPER_ADMIN, null);
        $managerKarawang = $this->akun(Role::MANAGER, $this->karawang);
        $managerSurabaya = $this->akun(Role::MANAGER, $this->surabaya);

        $pemohon = $this->akun(Role::LOGISTICS, $this->karawang, ['email' => 'dwi@berger.co.id']);

        $this->minta('dwi@berger.co.id')
            ->assertRedirect(route('password.lupa'))
            ->assertSessionHas('terkirim');

        $dikabari = Notification::where('type', Notification::PASSWORD_RESET_REQUESTED)->pluck('user_id')->all();

        $this->assertEqualsCanonicalizing([$superAdmin->id, $managerKarawang->id], $dikabari);
        $this->assertNotContains($managerSurabaya->id, $dikabari);

        // Lonceng mengantar ke baris akun pemohon, di host mana pun admin
        // membukanya. Permintaan ini diproses di antrean, tempat route()
        // memakai APP_URL — alamat lengkap yang menuju IP lama berarti admin
        // tiba di halaman kosong.
        $this->assertSame(
            '/wms/admin/users?search=dwi%40berger.co.id',
            Notification::where('type', Notification::PASSWORD_RESET_REQUESTED)->value('url'),
        );

        $this->assertNotNull($pemohon->fresh()->password_reset_requested_at);

        Mail::assertQueued(PermintaanLupaSandi::class);
    }

    /** Inti anti-pemetaan: jawaban untuk email tak terdaftar identik. */
    public function test_email_tidak_terdaftar_dijawab_sama_persis_dan_diam(): void
    {
        Mail::fake();

        $this->akun(Role::SUPER_ADMIN, null);
        $this->akun(Role::LOGISTICS, $this->karawang, ['email' => 'ada@berger.co.id']);

        $terdaftar = $this->minta('ada@berger.co.id');
        $asing = $this->minta('tidak.ada@berger.co.id');

        $this->assertSame($terdaftar->getStatusCode(), $asing->getStatusCode());
        $this->assertSame($terdaftar->headers->get('Location'), $asing->headers->get('Location'));
        $asing->assertSessionHas('terkirim');

        // Hanya satu permintaan yang sampai — milik email yang terdaftar.
        $this->assertSame(1, Notification::where('type', Notification::PASSWORD_RESET_REQUESTED)->count());
        $this->assertSame(1, ActivityLog::where('action', ActivityLog::PASSWORD_RESET_REQUEST)->count());
    }

    /**
     * Pelaku di log SENGAJA KOSONG: yang menekan tombolnya belum membuktikan
     * dirinya pemilik akun. IP-nya yang disimpan — itulah yang membedakan
     * pemilik dari orang yang mencoba mengambil alih akunnya.
     */
    public function test_permintaan_dicatat_tanpa_pelaku_dengan_ip(): void
    {
        Mail::fake();

        $this->akun(Role::SUPER_ADMIN, null);
        $pemohon = $this->akun(Role::LOGISTICS, $this->karawang, ['email' => 'dwi@berger.co.id']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.10.11.77'])->minta('dwi@berger.co.id');

        $log = ActivityLog::where('action', ActivityLog::PASSWORD_RESET_REQUEST)->firstOrFail();

        $this->assertNull($log->user_id);
        $this->assertSame($pemohon->id, $log->subject_id);
        $this->assertSame('10.10.11.77', $log->properties['ip']);
    }

    /** Menekan lima kali cukup membunyikan lonceng admin sekali. */
    public function test_permintaan_berulang_tidak_membanjiri_admin(): void
    {
        Mail::fake();

        $this->akun(Role::SUPER_ADMIN, null);
        $this->akun(Role::LOGISTICS, $this->karawang, ['email' => 'dwi@berger.co.id']);

        $this->minta('dwi@berger.co.id');
        $this->minta('dwi@berger.co.id');
        $this->minta('DWI@berger.co.id');

        $this->assertSame(1, Notification::where('type', Notification::PASSWORD_RESET_REQUESTED)->count());
    }

    /** Formulir tanpa login tidak boleh bisa ditekan tanpa batas. */
    public function test_formulir_dibatasi_per_ip(): void
    {
        Mail::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->minta('siapa.saja@berger.co.id')->assertRedirect();
        }

        $this->minta('siapa.saja@berger.co.id')->assertStatus(429);
    }

    /* ==================================================== (b) Sandi sementara */

    public function test_sandi_yang_direset_admin_menjadi_sandi_sementara(): void
    {
        $admin = $this->masuk($this->akun(Role::SUPER_ADMIN, null));

        $target = $this->akun(Role::LOGISTICS, $this->karawang, [
            'password_reset_requested_at' => now()->subMinutes(5),
        ]);

        $this->put(route('wms.users.update', $target), [
            'employee_id' => $target->employee_id,
            'full_name' => $target->full_name,
            'email' => $target->email,
            'password' => 'Sementara123',
            'role_id' => $target->role_id,
            'department_id' => $target->department_id ?? Department::factory()->create()->id,
            'warehouse_id' => $target->warehouse_id,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $target->refresh();

        $this->assertTrue($target->must_change_password);
        // Permintaannya dianggap selesai; tanda di Manajemen Pengguna padam.
        $this->assertNull($target->password_reset_requested_at);
        $this->assertNotSame($admin->id, $target->id);
    }

    /** Sandi akunnya sendiri tidak diketahui orang lain — tidak dipaksa. */
    public function test_admin_yang_mengganti_sandinya_sendiri_tidak_dipaksa(): void
    {
        $admin = $this->masuk($this->akun(Role::SUPER_ADMIN, null, [
            'department_id' => Department::factory()->create()->id,
        ]));

        $this->put(route('wms.users.update', $admin), [
            'employee_id' => $admin->employee_id,
            'full_name' => $admin->full_name,
            'email' => $admin->email,
            'password' => 'SandiSaya123',
            'role_id' => $admin->role_id,
            'department_id' => $admin->department_id,
            'warehouse_id' => null,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertFalse($admin->fresh()->must_change_password);
    }

    public function test_pemilik_sandi_sementara_tertahan_di_halaman_ganti(): void
    {
        $this->masuk($this->akun(Role::LOGISTICS, $this->karawang, ['must_change_password' => true]));

        $this->get('/wms/dashboard')->assertRedirect(route('password.wajib-ganti'));
        $this->get(route('password.wajib-ganti'))->assertOk()->assertSee('sandi sementara');

        // fetch() menerima jawaban JSON yang jujur, bukan halaman HTML.
        $this->getJson('/wms/dashboard')->assertForbidden()->assertJsonStructure(['message']);
    }

    /** Jalan keluar wajib ada — tidak boleh terperangkap di satu halaman. */
    public function test_pemilik_sandi_sementara_tetap_bisa_keluar(): void
    {
        $this->masuk($this->akun(Role::LOGISTICS, $this->karawang, ['must_change_password' => true]));

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_mengganti_sandi_sementara_membuka_sistem(): void
    {
        $user = $this->masuk($this->akun(Role::LOGISTICS, $this->karawang, [
            'password' => 'Sementara123',
            'must_change_password' => true,
        ]));

        $this->post(route('profile.password'), [
            'current_password' => 'Sementara123',
            'new_password' => 'MilikSaya456',
            'new_password_confirmation' => 'MilikSaya456',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertSame(1, ActivityLog::where('action', ActivityLog::PASSWORD_FORCED_CHANGE)->count());

        // Pintunya sekarang terbuka: apa pun jawaban dashboard-nya, ia tidak
        // lagi mengarah ke halaman ganti sandi.
        $this->assertNotSame(
            route('password.wajib-ganti'),
            $this->get('/wms/dashboard')->headers->get('Location'),
        );
        $this->get(route('wms.approval.index'))->assertOk();
    }

    /**
     * AKUN YANG SUDAH ADA TIDAK TERSENTUH. Penandanya default false; yang
     * terkena hanya sandi yang diisi admin sesudah fitur ini ada.
     */
    public function test_akun_biasa_tidak_terpengaruh(): void
    {
        $this->masuk($this->akun(Role::LOGISTICS, $this->karawang));

        $this->get(route('wms.approval.index'))->assertOk();

        // Dan halaman ganti wajib mengantarnya pergi — tidak ada urusan di sana.
        $this->get(route('password.wajib-ganti'))->assertRedirect();
    }
}
