<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\Notification;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\CurrentActor;
use App\Support\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit hak akses SELURUH rute — pengerasan sebelum go-live (Fase 13).
 *
 * SmokeRouteTest memastikan tidak ada halaman yang meledak; test modul
 * memastikan aturan bisnis per fitur. Yang belum dijaga siapa pun adalah
 * pertanyaan yang lebih dasar: APAKAH SETIAP RUTE BERPAGAR? Satu rute baru
 * yang lupa diberi `can:` tidak membuat test mana pun merah — ia hanya
 * terbuka untuk semua orang yang sudah login.
 *
 * Rutenya dibaca dari router sungguhan, jadi rute yang ditambahkan kelak
 * otomatis ikut diperiksa. Pengecualian ditulis EKSPLISIT beserta alasannya;
 * menambah pengecualian berarti menulis alasan di sini.
 */
class RouteSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rute yang SENGAJA bisa dibuka tanpa login.
     *
     * epod & mrf: dibuka supir dan atasan lewat tautan WhatsApp; kuncinya
     * token acak di URL (dan throttle), bukan akun.
     */
    private const PUBLIK = [
        '/', 'login', 'logout', 'health', 'up',
        'epod/{token}', 'epod/{token}/confirm',
        'mrf/{token}', 'mrf/{token}/approve', 'mrf/{token}/reject',
    ];

    /**
     * Rute login yang sah TANPA gate `can:`.
     *
     * Profil & notifikasi: milik setiap akun; controller membatasi ke baris
     * milik penggunanya sendiri (diuji di bawah). Tiga rute wms/* ini hanya
     * redirect. Rute sales/* tidak memakai gate: portal:sales sudah menutupnya
     * bagi role lain, dan kepemilikan pesanan ditegakkan di controller.
     */
    private const TANPA_GATE = [
        'profile', 'profile/password', 'profile/sessions/revoke-others', 'profile/sessions/{session}',
        'notifications', 'notifications/read-all', 'notifications/{notification}',
        'wms/dashboard', 'wms/notifications', 'wms/profile',
    ];

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create(['code' => 'WH-01']);
    }

    private function loginAs(string $slug): User
    {
        $user = User::factory()->withRole($slug)->create(['warehouse_id' => $this->warehouse->id]);
        $token = Str::random(64);

        UserSession::create([
            'user_id' => $user->id, 'session_id' => $token, 'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit', 'last_activity_at' => now(), 'created_at' => now(),
        ]);

        $this->withUnencryptedCookies(['device_token' => $token]);
        $this->actingAs($user);

        return $user;
    }

    /** @return list<RoutingRoute> */
    private function ruteAplikasi(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $r) => ! str_starts_with($r->uri(), '_'),
        ));
    }

    private function contohUri(RoutingRoute $rute): string
    {
        return '/'.ltrim(preg_replace('/\{\w+\??\}/', '999999', $rute->uri()), '/');
    }

    private function metode(RoutingRoute $rute): string
    {
        return collect($rute->methods())->reject(fn ($m) => $m === 'HEAD')->first();
    }

    /* ------------------------------------------------------ Pagar statis */

    public function test_setiap_rute_selain_daftar_publik_wajib_login(): void
    {
        $terbuka = [];

        foreach ($this->ruteAplikasi() as $rute) {
            if (in_array($rute->uri(), self::PUBLIK, true)) {
                continue;
            }

            if (! in_array('auth', $rute->gatherMiddleware(), true)) {
                $terbuka[] = implode('|', $rute->methods()).' '.$rute->uri();
            }
        }

        $this->assertSame([], $terbuka, "Rute berikut bisa dibuka TANPA login:\n".implode("\n", $terbuka));
    }

    public function test_rute_portal_dijaga_middleware_portalnya(): void
    {
        $bocor = [];

        foreach ($this->ruteAplikasi() as $rute) {
            $mw = $rute->gatherMiddleware();

            foreach (['wms', 'sales'] as $portal) {
                if (str_starts_with($rute->uri(), $portal.'/') && ! in_array('portal:'.$portal, $mw, true)) {
                    $bocor[] = $rute->uri();
                }
            }
        }

        $this->assertSame([], $bocor, "Rute portal tanpa middleware portal:\n".implode("\n", $bocor));
    }

    /**
     * Setiap rute WMS berpagar gate — kecuali yang tercantum di TANPA_GATE.
     */
    public function test_setiap_rute_wms_dijaga_gate_hak_akses(): void
    {
        $tanpaGate = [];

        foreach ($this->ruteAplikasi() as $rute) {
            if (! str_starts_with($rute->uri(), 'wms/') || in_array($rute->uri(), self::TANPA_GATE, true)) {
                continue;
            }

            $gate = collect($rute->gatherMiddleware())->first(fn ($m) => str_starts_with($m, 'can:'));

            if ($gate === null) {
                $tanpaGate[] = implode('|', $rute->methods()).' '.$rute->uri();
            }
        }

        $this->assertSame([], $tanpaGate, "Rute WMS tanpa gate `can:`:\n".implode("\n", $tanpaGate));
    }

    /**
     * Nama gate yang salah ketik tidak menghasilkan galat — Gate yang tidak
     * terdaftar selalu menolak, sehingga fiturnya diam-diam mati bagi SEMUA
     * orang dan baru ketahuan saat pengguna mengeluh.
     */
    public function test_setiap_gate_yang_dipakai_rute_terdaftar_di_matriks(): void
    {
        $asing = [];

        foreach ($this->ruteAplikasi() as $rute) {
            foreach ($rute->gatherMiddleware() as $mw) {
                if (str_starts_with($mw, 'can:')) {
                    $fitur = explode(',', substr($mw, 4))[0];

                    if (! in_array($fitur, Permission::features(), true)) {
                        $asing[] = "{$rute->uri()} -> {$fitur}";
                    }
                }
            }
        }

        $this->assertSame([], $asing, "Gate yang tidak ada di Permission::MATRIX:\n".implode("\n", $asing));
    }

    /**
     * Rute /storage/{path} bawaan Laravel (disk local 'serve') tidak boleh
     * ada: ia melayani berkas privat — foto Surat Jalan, dokumen PO — di luar
     * pemeriksaan hak akses aplikasi.
     */
    public function test_berkas_privat_tidak_dilayani_rute_bawaan_storage(): void
    {
        $storage = collect($this->ruteAplikasi())->filter(fn ($r) => str_starts_with($r->uri(), 'storage/'));

        $this->assertCount(0, $storage);
    }

    /* ---------------------------------------------------- Pagar lewat HTTP */

    /** Tamu diarahkan ke login di SEMUA rute terlindungi, apa pun metodenya. */
    public function test_tamu_tidak_bisa_menjalankan_rute_terlindungi(): void
    {
        $lolos = [];

        foreach ($this->ruteAplikasi() as $rute) {
            if (in_array($rute->uri(), self::PUBLIK, true)) {
                continue;
            }

            $metode = $this->metode($rute);
            $respons = $this->call($metode, $this->contohUri($rute), [], $this->prepareCookiesForRequest());

            if (! $respons->isRedirect(url('/login'))) {
                $lolos[] = "{$metode} {$rute->uri()} -> {$respons->getStatusCode()}";
            }
        }

        $this->assertSame([], $lolos, "Tamu tidak diarahkan ke login:\n".implode("\n", $lolos));
    }

    /**
     * Matriks hak akses ditegakkan di HTTP, untuk tiap role.
     *
     * Hanya rute tanpa parameter: pada rute berparameter, pengikatan model
     * (404 untuk id contoh) berjalan lebih dulu daripada gate, sehingga
     * jawabannya tidak membuktikan apa-apa tentang gate itu sendiri. Pagarnya
     * sudah dijamin ada oleh test statis di atas.
     */
    public function test_role_yang_tidak_berhak_ditolak_di_setiap_rute_bergate(): void
    {
        $lolos = [];

        foreach ([Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS, Role::PRODUCTION, Role::WAREHOUSE_OPERATOR, Role::SALES] as $slug) {
            $user = $this->loginAs($slug)->load('role');

            foreach ($this->ruteAplikasi() as $rute) {
                if (str_contains($rute->uri(), '{')) {
                    continue;
                }

                $gate = collect($rute->gatherMiddleware())->first(fn ($m) => str_starts_with($m, 'can:'));

                if ($gate === null || Permission::allows($user, explode(',', substr($gate, 4))[0])) {
                    continue;
                }

                $metode = $this->metode($rute);
                $respons = $this->call($metode, $this->contohUri($rute), [], $this->prepareCookiesForRequest());
                $status = $respons->getStatusCode();

                if ($status !== 403) {
                    $lolos[] = "{$slug}: {$metode} {$rute->uri()} -> {$status} ".$respons->headers->get('Location');
                }
            }
        }

        $this->assertSame([], $lolos, "Role tanpa hak akses TIDAK ditolak 403:\n".implode("\n", $lolos));
    }

    /* ------------------------------------------------ Milik orang lain */

    /**
     * Sales tidak bisa menyentuh pesanan Sales lain lewat aksi APA PUN.
     * Dijawab 404 — mengakui nomornya ada sudah membocorkan sesuatu.
     */
    public function test_sales_tidak_bisa_menyentuh_pesanan_sales_lain_lewat_aksi_apa_pun(): void
    {
        Storage::fake('local');

        $pemilik = $this->loginAs(Role::SALES);
        $draft = SalesOrder::factory()->create([
            'user_id' => $pemilik->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => SalesOrder::STATUS_DRAFT,
        ]);

        $this->loginAs(Role::SALES);

        $this->put('/sales/orders/'.$draft->id, ['action' => 'draft'])->assertNotFound();
        $this->post('/sales/orders/'.$draft->id.'/submit')->assertNotFound();
        $this->get('/sales/orders/'.$draft->id.'/document')->assertNotFound();
        $this->post('/sales/orders/'.$draft->id.'/proofs', [
            'photos' => [UploadedFile::fake()->image('surat.jpg')],
        ])->assertNotFound();
        $this->delete('/sales/orders/'.$draft->id)->assertNotFound();

        $this->assertDatabaseHas('sales_orders', ['id' => $draft->id, 'status' => SalesOrder::STATUS_DRAFT]);
    }

    public function test_notifikasi_milik_orang_lain_tidak_bisa_dibuka(): void
    {
        $pemilik = User::factory()->withRole(Role::LOGISTICS)->create(['warehouse_id' => $this->warehouse->id]);
        $notifikasi = Notification::create([
            'user_id' => $pemilik->id, 'type' => Notification::BILLING_OVERDUE,
            'title' => 'Rahasia', 'body' => 'Isi notifikasi milik orang lain.', 'created_at' => now(),
        ]);

        $this->loginAs(Role::LOGISTICS);

        $this->get('/notifications/'.$notifikasi->id)->assertNotFound();
        $this->assertNull($notifikasi->fresh()->read_at);
    }

    /* ------------------------------------------------------ Sisa dev */

    /**
     * `?as=<role>` dan Super Admin cadangan untuk tamu sudah dihapus.
     * Dulu hanya dipagari APP_ENV=production — satu salah isi .env di server
     * cukup untuk membuat tamu bertindak sebagai Super Admin.
     */
    public function test_tamu_tidak_pernah_menjadi_aktor_walau_meminta_lewat_parameter(): void
    {
        User::factory()->withRole(Role::SUPER_ADMIN)->create(['is_active' => true]);

        $this->app['request']->query->set('as', Role::SUPER_ADMIN);

        $this->assertNull(CurrentActor::get());
    }

    /* ------------------------------------------------------- Header */

    public function test_halaman_membawa_header_keamanan(): void
    {
        $respons = $this->get('/login')->assertOk();

        $csp = $respons->headers->get('Content-Security-Policy');

        $this->assertSame(implode('; ', SecurityHeaders::CSP), $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString('camera=(self)', (string) $respons->headers->get('Permissions-Policy'));
        $this->assertSame('nosniff', $respons->headers->get('X-Content-Type-Options'));
    }

    /**
     * Setiap skrip dan stylesheet dari CDN membawa hash integritas.
     *
     * Tanpa hash, berkas yang diubah di CDN (akun paket dibobol, CDN disusupi)
     * langsung berjalan di halaman login dan halaman stok. Dengan hash,
     * browser menolaknya.
     */
    public function test_aset_cdn_membawa_hash_integritas(): void
    {
        $tanpaHash = [];
        $diperiksa = 0;

        $berkasView = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($berkasView as $berkas) {
            if (! str_ends_with($berkas->getFilename(), '.blade.php')) {
                continue;
            }

            preg_match_all('/<(?:script|link)\b[^>]*https:\/\/cdn\.jsdelivr\.net[^>]*>/', (string) file_get_contents($berkas->getPathname()), $cocok);

            foreach ($cocok[0] as $tag) {
                $diperiksa++;

                // Versi WAJIB dikunci: hash untuk "versi terbaru" pasti
                // meleset begitu paketnya merilis versi baru.
                if (! str_contains($tag, 'integrity="sha384-') || ! preg_match('/npm\/[\w.-]+@\d/', $tag)) {
                    $tanpaHash[] = $berkas->getFilename().': '.$tag;
                }
            }
        }

        $this->assertGreaterThan(0, $diperiksa, 'Tidak ada aset CDN yang ditemukan — pola pencariannya rusak?');
        $this->assertSame([], $tanpaHash, "Aset CDN tanpa hash integritas atau tanpa versi terkunci:\n".implode("\n", $tanpaHash));
    }

    public function test_health_melaporkan_detak_penjadwal_dan_antrean(): void
    {
        $this->getJson('/health')
            ->assertJsonStructure(['status', 'checks' => ['database', 'redis', 'storage', 'penjadwal', 'antrean']]);
    }
}
