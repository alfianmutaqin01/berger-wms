<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Penanda "sistem sedang bekerja" — resources/views/partials/pemuat.blade.php.
 *
 * KENAPA INI DIUJI PADAHAL HANYA TAMPILAN
 * ---------------------------------------
 * Penanda ini ada supaya orang tidak menekan tombol dua kali sementara
 * permintaan pertamanya masih berjalan, dan di gudang tombol yang ditekan dua
 * kali berarti dokumen kembar — persis kejadian yang melahirkan
 * DuplikatProduksi. Jadi ia bukan hiasan, ia pagar.
 *
 * Yang dijaga di sini adalah hal yang RUSAK TANPA SUARA. Sebuah @include yang
 * hilang saat layout dirapikan tidak menggagalkan apa pun: halamannya tetap
 * terbuka, tetap benar, hanya diam lagi seperti dulu. Tidak ada yang akan
 * melaporkannya, karena diam memang keadaan yang dianggap normal sebelum ini
 * ada.
 *
 * Perilaku di perambannya sendiri — jeda 250 ms, tirai 700 ms, tombol yang
 * mati — tidak bisa dijangkau dari sini dan memang tidak dicoba.
 */
class PemuatTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $slug = Role::LOGISTICS): User
    {
        $user = User::factory()->withRole($slug)->create();

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

    /** Layar WMS — yang paling sering dipakai, dan yang paling sering menunggu. */
    public function test_layar_wms_membawa_penanda_pemuat(): void
    {
        $this->login();

        $this->get(route('wms.inventory.index'))
            ->assertOk()
            ->assertSee('pemuatGaris')
            ->assertSee('pemuatTirai')
            ->assertSee('window.Pemuat', false);
    }

    /** Portal Sales memakai layout yang berbeda, jadi ia bisa tertinggal sendiri. */
    public function test_layar_sales_membawa_penanda_pemuat(): void
    {
        $this->login(Role::SALES);

        $this->get(route('sales.dashboard'))
            ->assertOk()
            ->assertSee('pemuatGaris');
    }

    /**
     * Halaman di luar layout ikut terlindungi.
     *
     * Login, formulir MRF publik, dan ePOD supir menulis <body> sendiri —
     * masing-masing adalah tempat yang gampang terlupakan justru karena tidak
     * mewarisi apa pun. Login-lah yang paling sering ditekan dua kali: orang
     * mengira klik pertamanya tidak masuk.
     */
    public function test_halaman_login_membawa_penanda_pemuat(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('pemuatGaris');
    }

    /**
     * Tombol unduh DIKECUALIKAN, dan itu bukan kelalaian.
     *
     * Berkas Excel dikirim sebagai lampiran: halamannya tidak berpindah ke mana
     * pun, dan tidak ada satu peristiwa peramban pun yang memberi tahu bahwa
     * unduhannya selesai. Tanpa pengecualian ini, menekan Export akan menutup
     * layar dengan tirai yang tidak pernah terbuka lagi sampai dimuat ulang.
     */
    public function test_tombol_unduh_tidak_memicu_pemuat(): void
    {
        $this->login(Role::SUPER_ADMIN);

        $halaman = $this->get(route('wms.admin.activity-log'))->assertOk();

        $isi = $halaman->getContent();
        $baris = route('wms.admin.activity-log.unduh');

        $this->assertStringContainsString($baris, $isi);

        // Atributnya harus menempel pada tautan unduh itu sendiri, bukan
        // sekadar ada di suatu tempat pada halaman.
        $potongan = substr($isi, (int) strpos($isi, $baris), 200);
        $this->assertStringContainsString('data-tanpa-pemuat', $potongan);
    }
}
