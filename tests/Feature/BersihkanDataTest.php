<?php

namespace Tests\Feature;

use App\Models\LoginAttempt;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `wms:bersihkan` — menyapu sisa yang tidak dibersihkan alur normal.
 *
 * KETIGANYA PUNYA SEBAB YANG SAMA: pekerjaan yang berakhir tanpa ada yang
 * menutup pintunya.
 *
 * - Sesi mati: TrackUserSession menghapusnya hanya kalau penggunanya KEMBALI.
 *   Yang menutup browser meninggalkan barisnya selamanya, dan baris mati itu
 *   ikut dihitung oleh batas "maksimal 2 perangkat".
 * - Berkas impor: dihapus saat impor dikonfirmasi ATAU dibatalkan. Yang
 *   menutup tab di halaman pratinjau tidak melakukan keduanya.
 * - Riwayat login: bertambah pada setiap percobaan, tidak pernah dipangkas.
 */
class BersihkanDataTest extends TestCase
{
    use RefreshDatabase;

    private function sesi(User $user, int $menitLalu): UserSession
    {
        return UserSession::create([
            'user_id' => $user->id,
            'session_id' => Str::random(64),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'last_activity_at' => now()->subMinutes($menitLalu),
            'created_at' => now()->subMinutes($menitLalu),
        ]);
    }

    public function test_sesi_yang_sudah_lewat_idle_timeout_dihapus(): void
    {
        $user = User::factory()->withRole(Role::LOGISTICS)->create();

        $mati = $this->sesi($user, 61);
        $hidup = $this->sesi($user, 30);

        $this->artisan('wms:bersihkan')->assertSuccessful();

        $this->assertNull(UserSession::find($mati->id));
        $this->assertNotNull(
            UserSession::find($hidup->id),
            'Sesi yang menurut aturan masih hidup tidak boleh ikut disapu.'
        );
    }

    public function test_berkas_impor_telantar_dihapus_sesuai_umurnya(): void
    {
        Storage::fake('local');

        Storage::disk('local')->putFileAs('imports', UploadedFile::fake()->create('lama.xlsx', 10), 'lama.xlsx');
        Storage::disk('local')->putFileAs('imports', UploadedFile::fake()->create('baru.xlsx', 10), 'baru.xlsx');

        // Umur berkas dibaca dari mtime; yang lama dimundurkan tiga jam.
        touch(Storage::disk('local')->path('imports/lama.xlsx'), now()->subHours(3)->getTimestamp());

        $this->artisan('wms:bersihkan')->assertSuccessful();

        Storage::disk('local')->assertMissing('imports/lama.xlsx');
        Storage::disk('local')->assertExists('imports/baru.xlsx');
    }

    public function test_ambang_umur_berkas_bisa_diatur(): void
    {
        Storage::fake('local');

        Storage::disk('local')->putFileAs('imports', UploadedFile::fake()->create('a.xlsx', 10), 'a.xlsx');
        touch(Storage::disk('local')->path('imports/a.xlsx'), now()->subHours(3)->getTimestamp());

        $this->artisan('wms:bersihkan', ['--jam-berkas' => 24])->assertSuccessful();

        Storage::disk('local')->assertExists('imports/a.xlsx');
    }

    public function test_riwayat_login_lama_dipangkas(): void
    {
        LoginAttempt::create([
            'email' => 'lama@berger.test', 'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit', 'is_successful' => false,
            'created_at' => now()->subDays(91),
        ]);
        LoginAttempt::create([
            'email' => 'baru@berger.test', 'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit', 'is_successful' => true,
            'created_at' => now()->subDays(30),
        ]);

        $this->artisan('wms:bersihkan')->assertSuccessful();

        $this->assertSame(0, LoginAttempt::where('email', 'lama@berger.test')->count());
        $this->assertSame(1, LoginAttempt::where('email', 'baru@berger.test')->count());
    }

    public function test_perintah_aman_dijalankan_saat_tidak_ada_apa_apa(): void
    {
        Storage::fake('local');

        // Folder imports belum tentu ada pada pemasangan baru; perintahnya
        // tidak boleh meledak karena itu.
        $this->artisan('wms:bersihkan')->assertSuccessful();
    }
}
