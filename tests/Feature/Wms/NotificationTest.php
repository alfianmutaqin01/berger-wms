<?php

namespace Tests\Feature\Wms;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Notifier;
use App\Support\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lonceng notifikasi & pembersihan log — Fase 9.
 *
 * EMPAT HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * ---------------------------------------------------
 * 1. PENERIMA DIPILIH LEWAT IZIN, bukan nama peran. Menuliskan daftar peran
 *    kedua kalinya di Notifier berarti suatu hari ada orang yang halamannya
 *    bisa dibuka tetapi loncengnya tidak pernah berbunyi.
 * 2. BATAS GUDANG BERLAKU. Notifikasi memuat nomor pesanan dan nama
 *    pelanggan; satu kelalaian berarti gudang lain ikut membacanya.
 * 3. TIDAK MENGIRIM KE DIRI SENDIRI. Orang yang baru menekan tombolnya sudah
 *    melihat pesan hijau; lonceng untuk pekerjaan sendiri hanya melatih orang
 *    mengabaikannya.
 * 4. PEMBERSIHAN 90 HARI TIDAK BOLEH BISA MENGHAPUS SATU BARIS TERTENTU.
 *    Yang dijaga bukan pembersihan berkala, melainkan orang yang
 *    menghilangkan jejak dirinya sendiri.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $karawang;

    private Warehouse $pekanbaru;

    protected function setUp(): void
    {
        parent::setUp();

        $this->karawang = Warehouse::factory()->create(['code' => 'WH-01', 'name' => 'Karawang']);
        $this->pekanbaru = Warehouse::factory()->create(['code' => 'WH-02', 'name' => 'Pekanbaru']);
    }

    /* ------------------------------------------------------------ Perkakas */

    private function buat(string $slug, ?Warehouse $gudang = null): User
    {
        return User::factory()->withRole($slug)->create([
            'warehouse_id' => $gudang?->id,
            'is_active' => true,
        ]);
    }

    private function masuk(User $user): User
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

        return $user;
    }

    private function kirim(?int $userId = null): Notification
    {
        return Notification::create([
            'user_id' => $userId ?? $this->buat(Role::LOGISTICS, $this->karawang)->id,
            'type' => Notification::ORDER_PENDING,
            'title' => 'Pesanan baru menunggu diterima',
            'body' => 'PO260909001 dari PT Contoh diajukan Budi.',
            'url' => '/wms/outbound/approval',
            'warehouse_id' => $this->karawang->id,
            'created_at' => now(),
        ]);
    }

    /* ------------------------------------------------------ Pemilihan penerima */

    public function test_penerima_dipilih_dari_matriks_izin(): void
    {
        $logistik = $this->buat(Role::LOGISTICS, $this->karawang);
        $operator = $this->buat(Role::WAREHOUSE_OPERATOR, $this->karawang);

        Notifier::toPermission(
            Permission::OUTBOUND_APPROVAL,
            $this->karawang->id,
            Notification::ORDER_PENDING,
            'Pesanan baru',
            'Ada pesanan menunggu.',
        );

        // Logistik memegang OUTBOUND_APPROVAL, Operator tidak.
        $this->assertSame(1, Notification::where('user_id', $logistik->id)->count());
        $this->assertSame(0, Notification::where('user_id', $operator->id)->count());
    }

    public function test_gudang_lain_tidak_ikut_menerima(): void
    {
        $karawang = $this->buat(Role::LOGISTICS, $this->karawang);
        $pekanbaru = $this->buat(Role::LOGISTICS, $this->pekanbaru);

        Notifier::toPermission(
            Permission::OUTBOUND_APPROVAL,
            $this->karawang->id,
            Notification::ORDER_PENDING,
            'Pesanan baru',
            'Ada pesanan menunggu.',
        );

        $this->assertSame(1, Notification::where('user_id', $karawang->id)->count());
        $this->assertSame(0, Notification::where('user_id', $pekanbaru->id)->count());
    }

    /** Akun tanpa gudang = Super Admin; ia memang melihat seluruh gudang. */
    public function test_akun_tanpa_gudang_menerima_dari_gudang_mana_pun(): void
    {
        $super = $this->buat(Role::SUPER_ADMIN, null);

        Notifier::toPermission(
            Permission::OUTBOUND_APPROVAL,
            $this->pekanbaru->id,
            Notification::ORDER_PENDING,
            'Pesanan baru',
            'Ada pesanan menunggu.',
        );

        $this->assertSame(1, Notification::where('user_id', $super->id)->count());
    }

    public function test_akun_nonaktif_tidak_menerima(): void
    {
        $mati = $this->buat(Role::LOGISTICS, $this->karawang);
        $mati->forceFill(['is_active' => false])->save();

        Notifier::toPermission(
            Permission::OUTBOUND_APPROVAL,
            $this->karawang->id,
            Notification::ORDER_PENDING,
            'Pesanan baru',
            'Ada pesanan menunggu.',
        );

        $this->assertSame(0, Notification::where('user_id', $mati->id)->count());
    }

    public function test_pelakunya_sendiri_tidak_dikirimi(): void
    {
        $logistik = $this->masuk($this->buat(Role::LOGISTICS, $this->karawang));
        $rekan = $this->buat(Role::LOGISTICS, $this->karawang);

        Notifier::toPermission(
            Permission::OUTBOUND_APPROVAL,
            $this->karawang->id,
            Notification::ORDER_PENDING,
            'Pesanan baru',
            'Ada pesanan menunggu.',
        );

        $this->assertSame(0, Notification::where('user_id', $logistik->id)->count());
        $this->assertSame(1, Notification::where('user_id', $rekan->id)->count());
    }

    public function test_to_user_juga_tidak_mengirim_ke_diri_sendiri(): void
    {
        $sales = $this->masuk($this->buat(Role::SALES, $this->karawang));

        Notifier::toUser($sales->id, Notification::ORDER_APPROVED, 'Diterima', 'Pesanan Anda diterima.');

        $this->assertSame(0, Notification::count());
    }

    /* ---------------------------------------------------------- Halaman & lonceng */

    public function test_hanya_melihat_notifikasi_sendiri(): void
    {
        $saya = $this->masuk($this->buat(Role::LOGISTICS, $this->karawang));
        $orangLain = $this->buat(Role::LOGISTICS, $this->karawang);

        $this->kirim($saya->id);

        $punyaOrangLain = $this->kirim($orangLain->id);
        // Isi yang khas, supaya yang diperiksa benar-benar barisnya — bukan
        // nomor pesanan yang kebetulan sama pada keduanya.
        $punyaOrangLain->forceFill(['body' => 'RAHASIA MILIK ORANG LAIN'])->save();

        $this->get(route('wms.notifications.index'))
            ->assertOk()
            ->assertSee('PO260909001')
            ->assertDontSee('RAHASIA MILIK ORANG LAIN');

        // 404, bukan 403: nomor notifikasi orang lain tidak perlu diakui ada.
        $this->get(route('wms.notifications.open', $punyaOrangLain))->assertNotFound();
    }

    public function test_membuka_notifikasi_menandainya_dibaca_lalu_mengantar(): void
    {
        $saya = $this->masuk($this->buat(Role::LOGISTICS, $this->karawang));
        $n = $this->kirim($saya->id);

        $this->get(route('wms.notifications.open', $n))
            ->assertRedirect('/wms/outbound/approval');

        $this->assertNotNull($n->fresh()->read_at);
    }

    public function test_tandai_semua_dibaca_benar_benar_menyimpan(): void
    {
        $saya = $this->masuk($this->buat(Role::LOGISTICS, $this->karawang));

        $this->kirim($saya->id);
        $this->kirim($saya->id);

        // Versi lama hanya memunculkan jendela "Berhasil" tanpa menyentuh
        // apa pun, dan loncengnya tetap merah sesudah ditekan.
        $this->post(route('wms.notifications.read-all'))->assertRedirect();

        $this->assertSame(0, Notification::milik($saya->id)->belumDibaca()->count());
    }

    public function test_lonceng_di_navbar_menampilkan_jumlah_belum_dibaca(): void
    {
        $saya = $this->masuk($this->buat(Role::LOGISTICS, $this->karawang));

        $this->kirim($saya->id);
        $this->kirim($saya->id);

        $html = $this->get('/wms/dashboard/admin')->assertOk()->getContent();

        $this->assertStringContainsString('bi-bell-fill', $html);
        $this->assertStringContainsString('Pesanan baru menunggu diterima', $html);
    }

    /** Tidak ada notifikasi = tidak ada titik merah. Itu inti loncengnya. */
    public function test_lonceng_kosong_tidak_menyalakan_penanda(): void
    {
        $this->masuk($this->buat(Role::LOGISTICS, $this->karawang));

        $html = $this->get('/wms/dashboard/admin')->assertOk()->getContent();

        $this->assertStringContainsString('Belum ada notifikasi', $html);
        $this->assertStringNotContainsString('bi-bell-fill', $html);
    }

    /**
     * Rutenya sengaja DI LUAR kedua portal. Sebelumnya di dalam prefix /wms,
     * sehingga Tim Sales — yang dipagari keluar oleh middleware portal:wms —
     * tidak akan pernah bisa membuka loncengnya sendiri.
     */
    public function test_sales_bisa_membuka_loncengnya_sendiri(): void
    {
        $sales = $this->masuk($this->buat(Role::SALES, $this->karawang));

        $this->kirim($sales->id);

        $this->get(route('wms.notifications.index'))
            ->assertOk()
            ->assertSee('PO260909001');
    }

    /* ------------------------------------------------- Pembersihan 90 hari */

    public function test_log_lebih_tua_dari_90_hari_dibuang(): void
    {
        $lama = ActivityLog::create([
            'action' => ActivityLog::ORDER_APPROVE,
            'description' => 'Menerima pesanan lama.',
            'created_at' => now()->subDays(ActivityLog::UMUR_SIMPAN_HARI + 1),
        ]);

        $baru = ActivityLog::create([
            'action' => ActivityLog::ORDER_APPROVE,
            'description' => 'Menerima pesanan baru.',
            'created_at' => now()->subDays(ActivityLog::UMUR_SIMPAN_HARI - 1),
        ]);

        $this->artisan('activity:purge')->assertSuccessful();

        $this->assertDatabaseMissing('activity_logs', ['id' => $lama->id]);
        $this->assertDatabaseHas('activity_logs', ['id' => $baru->id]);
    }

    public function test_dry_run_tidak_menghapus_apa_pun(): void
    {
        ActivityLog::create([
            'action' => ActivityLog::ORDER_APPROVE,
            'description' => 'Menerima pesanan lama.',
            'created_at' => now()->subDays(ActivityLog::UMUR_SIMPAN_HARI + 1),
        ]);

        $this->artisan('activity:purge --dry-run')->assertSuccessful();

        $this->assertSame(1, ActivityLog::count());
    }

    /**
     * Pembersihan menurut UMUR boleh; menghapus satu baris tertentu tidak.
     * Itulah yang membedakan retensi dari menghilangkan jejak.
     */
    public function test_satu_baris_log_tetap_tidak_bisa_dihapus_lewat_model(): void
    {
        $log = ActivityLog::create([
            'action' => ActivityLog::ORDER_APPROVE,
            'description' => 'Menerima pesanan.',
            'created_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);

        $log->delete();
    }

    public function test_log_tetap_tidak_bisa_diubah(): void
    {
        $log = ActivityLog::create([
            'action' => ActivityLog::ORDER_APPROVE,
            'description' => 'Menerima pesanan.',
            'created_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);

        $log->update(['description' => 'Bukan saya.']);
    }
}
