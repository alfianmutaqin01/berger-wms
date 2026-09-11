<?php

namespace Tests\Feature\Wms;

use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ganti kata sandi & kelola sesi perangkat — PRD §6.1.
 *
 * DITEMUKAN SAAT AUDIT, dan keduanya soal keamanan akun:
 *
 * 1. updatePassword() menjawab "Kata sandi berhasil diperbarui" tanpa
 *    menyentuh basis data. Isian "kata sandi saat ini" bahkan tidak punya
 *    atribut name, jadi tidak pernah terkirim. Orang yang menggantinya akan
 *    menghafal sandi baru lalu terkunci di luar — sandi lamanya masih hidup.
 *
 * 2. Daftar sesi adalah dua baris HTML mati dengan tombol Cabut Akses yang
 *    hanya menghapus barisnya dari layar. Orang yang curiga akunnya dipakai
 *    orang lain akan mencabut akses lalu percaya dirinya aman.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $slug = Role::LOGISTICS, array $ubah = []): array
    {
        $user = User::factory()->withRole($slug)->create(array_merge([
            'password' => Hash::make('rahasia123'),
        ], $ubah));

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

        return [$user, $token];
    }

    private function sesiLain(User $user, string $agent = 'Android'): UserSession
    {
        return UserSession::create([
            'user_id' => $user->id,
            'session_id' => Str::random(64),
            'ip_address' => '10.0.0.9',
            'user_agent' => $agent,
            'last_activity_at' => now()->subMinutes(5),
            'created_at' => now()->subHours(2),
        ]);
    }

    /* -------------------------------------------------------- Semua role */

    /**
     * @return array<string, array{0:string}>
     */
    public static function semuaRole(): array
    {
        return [
            'super admin' => [Role::SUPER_ADMIN],
            'manager' => [Role::MANAGER],
            'logistik' => [Role::LOGISTICS],
            'produksi' => [Role::PRODUCTION],
            'operator gudang' => [Role::WAREHOUSE_OPERATOR],
            'sales' => [Role::SALES],
        ];
    }

    /**
     * Profil MILIK SEMUA ROLE, bukan hanya penghuni Portal WMS.
     *
     * Sebelumnya rutenya berada di dalam prefix /wms, yang dipagari middleware
     * `portal:wms`. Tim Sales adalah satu-satunya role yang ditolak pagar itu
     * — sehingga justru role yang paling sering berpindah perangkat tidak
     * punya cara mengganti sandinya sendiri maupun mengusir perangkat asing.
     */
    #[DataProvider('semuaRole')]
    public function test_setiap_role_dapat_membuka_profilnya(string $slug): void
    {
        [$user] = $this->login($slug);

        $this->get(route('profile'))
            ->assertOk()
            ->assertSee($user->full_name)
            ->assertSee('Ubah Kata Sandi');
    }

    #[DataProvider('semuaRole')]
    public function test_setiap_role_dapat_mengganti_sandinya(string $slug): void
    {
        [$user] = $this->login($slug);

        $this->post(route('profile.password'), [
            'current_password' => 'rahasia123',
            'new_password' => 'sandibaru9',
            'new_password_confirmation' => 'sandibaru9',
        ])->assertSessionHas('success');

        $this->assertTrue(Hash::check('sandibaru9', $user->fresh()->password));
    }

    public function test_sales_memakai_kerangka_portalnya_sendiri(): void
    {
        $this->login(Role::SALES);

        // Layout SOMS, bukan WMS: sidebar WMS memuat menu gudang yang tidak
        // boleh disentuh Sales, dan memunculkannya di layarnya adalah tawaran
        // menuju halaman yang pasti menjawab 403.
        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('Pesanan Saya')
            ->assertDontSee('Master Produk');
    }

    public function test_alamat_lama_dialihkan(): void
    {
        $this->login();

        // /wms/profile sudah tersebar di bookmark dan tautan lama.
        $this->get('/wms/profile')->assertRedirect(route('profile'));
    }

    public function test_tamu_tidak_bisa_membuka_profil(): void
    {
        $this->get(route('profile'))->assertRedirect(route('login'));
    }

    /* --------------------------------------------------------- Ganti sandi */

    public function test_kata_sandi_benar_benar_berubah(): void
    {
        [$user] = $this->login();

        $this->post(route('profile.password'), [
            'current_password' => 'rahasia123',
            'new_password' => 'sandibaru9',
            'new_password_confirmation' => 'sandibaru9',
        ])->assertSessionHas('success');

        $user->refresh();

        $this->assertTrue(Hash::check('sandibaru9', $user->password), 'Sandi baru harus berlaku.');
        $this->assertFalse(Hash::check('rahasia123', $user->password), 'Sandi lama TIDAK boleh masih berlaku.');
    }

    public function test_sandi_lama_salah_ditolak(): void
    {
        [$user] = $this->login();

        $this->post(route('profile.password'), [
            'current_password' => 'bukan-ini',
            'new_password' => 'sandibaru9',
            'new_password_confirmation' => 'sandibaru9',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('rahasia123', $user->fresh()->password));
    }

    public function test_ulangan_sandi_harus_sama(): void
    {
        [$user] = $this->login();

        $this->post(route('profile.password'), [
            'current_password' => 'rahasia123',
            'new_password' => 'sandibaru9',
            'new_password_confirmation' => 'sandilain9',
        ])->assertSessionHasErrors('new_password');

        $this->assertTrue(Hash::check('rahasia123', $user->fresh()->password));
    }

    public function test_kebijakan_sandi_sama_dengan_yang_dibuat_admin(): void
    {
        [$user] = $this->login();

        // Tanpa angka. Kalau jalur ini lebih longgar daripada StoreUserRequest,
        // kebijakan sandi organisasi bisa dilewati hanya dengan mengganti
        // sandi sendiri sesudah dibuatkan Admin.
        $this->post(route('profile.password'), [
            'current_password' => 'rahasia123',
            'new_password' => 'hurufsaja',
            'new_password_confirmation' => 'hurufsaja',
        ])->assertSessionHasErrors('new_password');

        $this->assertTrue(Hash::check('rahasia123', $user->fresh()->password));
    }

    public function test_sandi_baru_tidak_boleh_sama_dengan_yang_lama(): void
    {
        $this->login();

        $this->post(route('profile.password'), [
            'current_password' => 'rahasia123',
            'new_password' => 'rahasia123',
            'new_password_confirmation' => 'rahasia123',
        ])->assertSessionHasErrors('new_password');
    }

    public function test_ganti_sandi_mengeluarkan_perangkat_lain(): void
    {
        [$user, $token] = $this->login();
        $lain = $this->sesiLain($user);

        $this->post(route('profile.password'), [
            'current_password' => 'rahasia123',
            'new_password' => 'sandibaru9',
            'new_password_confirmation' => 'sandibaru9',
        ]);

        // Alasan orang mengganti sandi hampir selalu curiga akunnya dipakai
        // orang lain. Mengganti sandi tanpa memutus sesi yang sedang berjalan
        // tidak mengusir siapa pun.
        $this->assertNull(UserSession::find($lain->id));
        $this->assertNotNull(
            UserSession::where('session_id', $token)->first(),
            'Perangkat yang sedang dipakai tidak boleh ikut terputus.'
        );
    }

    public function test_ganti_sandi_membersihkan_penghitung_gagal(): void
    {
        [$user] = $this->login(Role::LOGISTICS, [
            'failed_login_attempts' => 2,
            'locked_until' => now()->subMinute(),
        ]);

        $this->post(route('profile.password'), [
            'current_password' => 'rahasia123',
            'new_password' => 'sandibaru9',
            'new_password_confirmation' => 'sandibaru9',
        ]);

        $user->refresh();
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
    }

    /* ------------------------------------------------------------- Sesi */

    public function test_daftar_sesi_menampilkan_perangkat_sebenarnya(): void
    {
        [$user] = $this->login();
        $this->sesiLain($user, 'Mozilla/5.0 Android Chrome');

        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('Android')
            ->assertSee('10.0.0.9')
            // Dua baris karangan yang dulu tertanam di HTML.
            ->assertDontSee('192.168.1.45')
            ->assertDontSee('Berger WMS Mobile');
    }

    public function test_mencabut_satu_perangkat(): void
    {
        [$user] = $this->login();
        $lain = $this->sesiLain($user);

        $this->delete(route('profile.sessions.revoke', $lain))
            ->assertSessionHas('success');

        $this->assertNull(UserSession::find($lain->id));
    }

    public function test_tidak_bisa_mencabut_sesi_yang_sedang_dipakai(): void
    {
        [, $token] = $this->login();
        $ini = UserSession::where('session_id', $token)->firstOrFail();

        $this->delete(route('profile.sessions.revoke', $ini))
            ->assertSessionHas('error');

        $this->assertNotNull(UserSession::find($ini->id));
    }

    public function test_sesi_milik_orang_lain_dijawab_404(): void
    {
        $this->login();

        $orangLain = User::factory()->withRole(Role::SALES)->create();
        $sesiOrangLain = $this->sesiLain($orangLain);

        // 404, BUKAN 403: 403 mengakui bahwa barisnya ada.
        $this->delete(route('profile.sessions.revoke', $sesiOrangLain))->assertNotFound();

        $this->assertNotNull(UserSession::find($sesiOrangLain->id));
    }

    public function test_mengeluarkan_semua_perangkat_lain(): void
    {
        [$user, $token] = $this->login();
        $this->sesiLain($user, 'Android');
        $this->sesiLain($user, 'iPad');

        $this->post(route('profile.sessions.revoke-others'))->assertSessionHas('success');

        $this->assertSame(1, UserSession::where('user_id', $user->id)->count());
        $this->assertNotNull(UserSession::where('session_id', $token)->first());
    }
}
