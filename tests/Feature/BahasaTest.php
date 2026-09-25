<?php

namespace Tests\Feature;

use App\Models\DeliveryNote;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Support\Bahasa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pilihan bahasa tampilan — Fase 0.
 *
 * LIMA HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * --------------------------------------------------
 * 1. CADANGAN TERBALIK. Terjemahan di sini berbasis kalimat: yang tertulis di
 *    Blade adalah kalimat Indonesia-nya. Kalau fallback_locale 'en', layar
 *    berbahasa INDONESIA akan menemukan kalimatnya di lang/en.json dan
 *    menampilkan bahasa Inggris kepada orang yang tidak pernah memintanya.
 * 2. PILIHAN YANG TIDAK TERKUNCI. Bahasa adalah keadaan satu permintaan.
 *    Kalau tidak dibaca ulang dari session tiap permintaan, halaman berikutnya
 *    kembali ke Indonesia — dan yang terlihat bukan "terjemahan belum lengkap"
 *    melainkan "sistemnya rusak".
 * 3. HALAMAN BERTAUTAN IKUT BERUBAH. ePOD dibuka supir dan pelanggan lewat
 *    peramban yang bisa saja punya session Logistik yang memilih English.
 * 4. TERJEMAHAN HILANG SAAT PERANNYA DIGANTI NAMANYA. Label peran dikunci pada
 *    slug, bukan pada kolom name yang sewaktu-waktu diperbaiki lewat seeder.
 * 5. KAMUS TERTINGGAL DARI KODE. Kalimat baru di Blade tanpa pasangannya di
 *    lang/en.json akan tampil berbahasa Indonesia di tengah layar Inggris,
 *    dan tidak ada yang menyadarinya sampai pengguna bertanya.
 */
class BahasaTest extends TestCase
{
    use RefreshDatabase;

    /* --------------------------------------------------------- Pondasinya */

    public function test_bawaan_sistem_bahasa_indonesia(): void
    {
        $this->assertSame(Bahasa::INDONESIA, config('app.locale'));
    }

    /**
     * Cadangan WAJIB 'id', bukan 'en'.
     *
     * Inilah satu baris config yang kalau keliru membuat seluruh sistem
     * berbahasa Inggris untuk orang yang memilih Indonesia — dan galatnya
     * tidak terlihat sebagai galat, hanya sebagai bahasa yang salah.
     */
    public function test_cadangan_bahasa_indonesia_bukan_inggris(): void
    {
        $this->assertSame(Bahasa::INDONESIA, config('app.fallback_locale'));
    }

    public function test_kalimat_indonesia_tidak_berubah_saat_bahasa_indonesia(): void
    {
        app()->setLocale(Bahasa::INDONESIA);

        $this->assertSame('Pemindahan DDP', __('Pemindahan DDP'));
    }

    public function test_kalimat_diterjemahkan_saat_bahasa_inggris(): void
    {
        app()->setLocale(Bahasa::INGGRIS);

        $this->assertSame('DDP Relocation', __('Pemindahan DDP'));
    }

    /**
     * Yang belum diterjemahkan jatuh ke bahasa Indonesia, BUKAN ke kunci mentah.
     *
     * Pada pekerjaan ribuan kalimat yang dikerjakan bertahap, inilah yang
     * membuat sistem setengah jadi tetap aman dipakai: yang tertinggal terbaca
     * sebagai kalimat Indonesia, bukan sebagai "wms.delivery.title" bocor ke
     * layar pengguna.
     */
    public function test_kalimat_belum_diterjemahkan_jatuh_ke_indonesia(): void
    {
        app()->setLocale(Bahasa::INGGRIS);

        $this->assertSame(
            'Kalimat Ini Sengaja Tidak Ada Di Kamus',
            __('Kalimat Ini Sengaja Tidak Ada Di Kamus'),
        );
    }

    public function test_pesan_validasi_bawaan_ikut_bahasa_indonesia(): void
    {
        app()->setLocale(Bahasa::INDONESIA);

        // Sebelum Fase 0 ini keluar sebagai "The nama supir field is required"
        // di tengah layar yang seluruhnya berbahasa Indonesia.
        $this->assertSame(
            'Kolom nama supir wajib diisi.',
            trans('validation.required', ['attribute' => 'nama supir']),
        );
    }

    /* ------------------------------------------------------- Lewat layarnya */

    public function test_pengguna_bisa_mengganti_bahasa_ke_inggris(): void
    {
        $this->login();

        $this->from('/wms/dashboard')
            ->post(route('bahasa.ubah'), ['bahasa' => Bahasa::INGGRIS])
            ->assertRedirect('/wms/dashboard');

        $this->assertSame(Bahasa::INGGRIS, session(Bahasa::KUNCI_SESSION));
    }

    public function test_bahasa_asing_ditolak(): void
    {
        $this->login();

        $this->post(route('bahasa.ubah'), ['bahasa' => 'jp'])
            ->assertSessionHasErrors('bahasa');

        $this->assertNull(session(Bahasa::KUNCI_SESSION));
    }

    /**
     * PILIHANNYA TERKUNCI — ini inti permintaan pemilik produk.
     *
     * Bukan hanya halaman berikutnya: SETIAP permintaan sesudahnya, termasuk
     * yang dipicing latar belakang. Satu permintaan yang terlewat sudah cukup
     * membuat sepotong layar kembali ke bahasa Indonesia.
     */
    public function test_pilihan_bertahan_di_permintaan_berikutnya(): void
    {
        $this->login();
        $this->post(route('bahasa.ubah'), ['bahasa' => Bahasa::INGGRIS]);

        // /profile: dimiliki SEMUA role dan memakai layout ber-sidebar, jadi
        // test ini tidak ikut jatuh saat matriks hak akses dashboard berubah.
        $this->get('/profile')->assertOk()->assertSee('DDP Relocation');

        // Permintaan KEDUA, dan inilah yang sebenarnya diuji: pilihannya
        // terkunci, bukan sekadar bertahan satu halaman.
        $this->get('/profile')->assertOk()->assertSee('My Profile');

        $this->assertSame(Bahasa::INGGRIS, app()->getLocale());
    }

    public function test_menu_berbahasa_indonesia_sebelum_memilih(): void
    {
        $this->login();

        $this->get('/profile')
            ->assertOk()
            ->assertSee('Pemindahan DDP')
            ->assertDontSee('DDP Relocation');
    }

    /**
     * Logout mengembalikannya ke bahasa Indonesia.
     *
     * Tidak ada kode yang mengerjakannya: AuthController::logout() memanggil
     * session()->invalidate(), dan pilihannya ikut hilang bersama session.
     * Diuji justru karena tidak ada kodenya — kalau suatu saat penyimpanannya
     * dipindah ke kolom users, test ini yang akan jatuh lebih dulu.
     */
    public function test_pilihan_hilang_setelah_logout(): void
    {
        $this->login();
        $this->post(route('bahasa.ubah'), ['bahasa' => Bahasa::INGGRIS]);
        $this->assertSame(Bahasa::INGGRIS, session(Bahasa::KUNCI_SESSION));

        $this->post(route('logout'));

        $this->assertNull(session(Bahasa::KUNCI_SESSION));
    }

    /* ------------------------------------------------- Halaman bertautan */

    /**
     * ePOD SELALU bahasa Indonesia, walau session perambannya memilih English.
     *
     * Keadaan yang diuji nyata: Logistik memilih English, lalu membuka tautan
     * ePOD di tab sebelah untuk memeriksanya. Yang ia lihat harus sama persis
     * dengan yang dilihat supir — kalau tidak, tangkapan layar yang ia kirim
     * ke supir adalah halaman yang bukan halaman supirnya.
     */
    public function test_halaman_epod_tetap_indonesia_walau_session_memilih_inggris(): void
    {
        $this->login();
        $this->post(route('bahasa.ubah'), ['bahasa' => Bahasa::INGGRIS]);

        $note = DeliveryNote::factory()->create([
            'status' => DeliveryNote::STATUS_SHIPPED,
            'shipped_at' => now(),
            'epod_token' => Str::random(48),
            'epod_expires_at' => now()->addHours(72),
        ]);

        $this->get('/epod/'.$note->epod_token)->assertOk();

        $this->assertSame(Bahasa::INDONESIA, app()->getLocale());
    }

    /* ------------------------------------------------------- Nama peran */

    public function test_nama_peran_ikut_bahasa_inggris(): void
    {
        // Namanya ditulis seperti di RoleSeeder. Yang diuji justru bahwa
        // terjemahannya TIDAK bergantung pada nama ini: ia dikunci pada slug.
        $peran = Role::firstOrCreate(
            ['slug' => Role::LOGISTICS],
            ['name' => 'Tim Logistik', 'level' => 3],
        );

        app()->setLocale(Bahasa::INDONESIA);
        $this->assertSame('Tim Logistik', Bahasa::peran($peran));

        app()->setLocale(Bahasa::INGGRIS);
        $this->assertSame('Logistics Team', Bahasa::peran($peran));
    }

    /**
     * Tidak ada peran yang kehilangan labelnya.
     *
     * Peran ditambahkan lewat seeder, bukan lewat layar — jadi peran baru
     * datang tanpa ada yang mengingatkan bahwa labelnya perlu diisi. Test ini
     * yang mengingatkan.
     */
    public function test_setiap_peran_punya_label_bahasa_inggris(): void
    {
        $tanpaLabel = Role::query()
            ->pluck('slug')
            ->reject(fn (string $slug) => array_key_exists($slug, Bahasa::labelPeran()))
            ->values()
            ->all();

        $this->assertSame([], $tanpaLabel,
            'Peran ini belum punya label bahasa Inggris di App\Support\Bahasa: '
            .implode(', ', $tanpaLabel));
    }

    /* --------------------------------------------------- Penjaga kamusnya */

    /**
     * Setiap kalimat yang dipakai kode HARUS ada di lang/en.json.
     *
     * Tanpa ini, kalimat baru yang ditulis besok akan tampil berbahasa
     * Indonesia di tengah layar berbahasa Inggris — dan karena itu bukan galat,
     * tidak ada yang menyadarinya sampai pengguna bertanya. Test inilah yang
     * membuat pekerjaan bertahap ini bisa selesai, bukan sekadar dimulai.
     */
    public function test_semua_kalimat_yang_dipakai_ada_di_kamus_inggris(): void
    {
        $kamus = json_decode(file_get_contents(base_path('lang/en.json')), true);

        $this->assertIsArray($kamus, 'lang/en.json bukan JSON yang sah.');

        $hilang = array_values(array_diff($this->kalimatTerpakai(), array_keys($kamus)));

        $this->assertSame([], $hilang,
            "Kalimat ini dipakai kode tetapi belum ada di lang/en.json:\n- "
            .implode("\n- ", $hilang));
    }

    /**
     * ...dan sebaliknya: kamus tidak menyimpan kalimat yang sudah tidak dipakai.
     *
     * Kamus yang menumpuk kalimat mati membuat penerjemah berikutnya
     * mengerjakan layar yang sudah tidak ada.
     */
    public function test_kamus_tidak_menyimpan_kalimat_yang_sudah_tidak_dipakai(): void
    {
        $kamus = json_decode(file_get_contents(base_path('lang/en.json')), true);

        $menganggur = array_values(array_diff(array_keys($kamus), $this->kalimatTerpakai()));

        $this->assertSame([], $menganggur,
            "Kalimat ini ada di lang/en.json tetapi tidak dipakai kode mana pun:\n- "
            .implode("\n- ", $menganggur));
    }

    /* ------------------------------------------------------------ Perkakas */

    /**
     * Seluruh kalimat di dalam __('...') pada kode aplikasi.
     *
     * Hanya bentuk kutip tunggal berisi teks tetap — yang memakai variabel
     * tidak bisa diperiksa dari luar dan memang tidak seharusnya ada.
     *
     * @return list<string>
     */
    private function kalimatTerpakai(): array
    {
        $kalimat = [];

        foreach ([resource_path('views'), app_path()] as $akar) {
            $berkas = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($akar));

            foreach ($berkas as $berkasnya) {
                if (! $berkasnya->isFile() || $berkasnya->getExtension() !== 'php') {
                    continue;
                }

                preg_match_all(
                    "/__\('((?:[^'\\\\]|\\\\.)*)'/",
                    file_get_contents($berkasnya->getPathname()),
                    $cocok,
                );

                foreach ($cocok[1] as $satu) {
                    $kalimat[] = stripslashes($satu);
                }
            }
        }

        return array_values(array_unique($kalimat));
    }

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
}
