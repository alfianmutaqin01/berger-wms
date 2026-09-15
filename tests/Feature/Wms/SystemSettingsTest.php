<?php

namespace Tests\Feature\Wms;

use App\Models\ActivityLog;
use App\Models\DeliveryProof;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\OrderCutoff;
use App\Support\Settings;
use App\Support\ShelfLife;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pengaturan Sistem & Penomoran Dokumen — Fase 10.
 *
 * EMPAT HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * ---------------------------------------------------
 * 1. SETELANNYA HARUS BENAR-BENAR BERLAKU. Halaman setelan yang menyimpan
 *    angka tetapi tidak mengubah perilaku apa pun adalah jenis kebohongan yang
 *    sama dengan tombol "Berhasil" yang tidak menyimpan.
 * 2. BATAS ATAS-BAWAH DITEGAKKAN DI SERVER. Jam cutoff 0 mengunci seluruh
 *    Sales sepanjang hari; retensi log 1 hari menghapus jejak yang belum
 *    sempat dibaca siapa pun.
 * 3. PERUBAHANNYA TERCATAT DI LOG — termasuk perubahan umur simpan log itu
 *    sendiri. Setelan yang menentukan berapa lama jejak disimpan justru yang
 *    paling perlu meninggalkan jejak saat diubah.
 * 4. PENOMORAN DOKUMEN TIDAK PUNYA RUTE TULIS. Bukan karena belum dibuat:
 *    mengganti prefix memecah riwayat, dan menggeser nomor mundur
 *    menghentikan pembuatan pesanan untuk semua orang.
 */
class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $gudang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->create(['code' => 'WH-01', 'name' => 'Karawang']);

        // Cache setelan hidup lintas permintaan; tanpa ini satu test bisa
        // membaca nilai yang ditulis test sebelumnya.
        Settings::lupakan();
    }

    private function login(string $slug = Role::SUPER_ADMIN): User
    {
        $user = User::factory()->withRole($slug)->create([
            'warehouse_id' => $slug === Role::SUPER_ADMIN ? null : $this->gudang->id,
        ]);

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

    /** Seluruh setelan pada nilai bawaannya, siap dikirim sebagai formulir. */
    private function bawaan(array $ubah = []): array
    {
        $nilai = [];

        foreach (Settings::daftar() as $key => $meta) {
            $nilai[$key] = $meta['bawaan'];
        }

        return array_merge($nilai, $ubah);
    }

    /* ------------------------------------------------------------- Akses */

    public function test_hanya_super_admin_yang_bisa_membuka_pengaturan(): void
    {
        // Setelan di sini berlaku SELURUH perusahaan, sementara kewenangan
        // Manager dibatasi ke gudangnya sendiri.
        foreach ([Role::MANAGER, Role::LOGISTICS, Role::WAREHOUSE_OPERATOR] as $slug) {
            $this->login($slug);
            $this->get(route('wms.admin.settings'))->assertForbidden();
            $this->post(route('wms.admin.settings.update'), $this->bawaan())->assertForbidden();
        }

        $this->login();
        $this->get(route('wms.admin.settings'))->assertOk();
    }

    /* --------------------------------------------- Setelannya benar berlaku */

    public function test_jam_cutoff_yang_disimpan_benar_benar_dipakai(): void
    {
        $this->login();

        $this->assertSame(15, OrderCutoff::hour());

        $this->post(route('wms.admin.settings.update'), $this->bawaan([
            Settings::ORDER_CUTOFF_HOUR => 17,
        ]))->assertSessionHas('success');

        // Halaman setelan yang menyimpan angka tetapi tidak mengubah perilaku
        // apa pun adalah kebohongan yang sama dengan tombol palsu.
        $this->assertSame(17, OrderCutoff::hour());
        $this->assertSame('17:00 WIB', OrderCutoff::label());
    }

    public function test_ambang_kedaluwarsa_dan_kuota_foto_ikut_berubah(): void
    {
        $this->login();

        $this->assertSame(90, ShelfLife::warningDays());
        $this->assertSame(3, DeliveryProof::maksFoto());

        $this->post(route('wms.admin.settings.update'), $this->bawaan([
            Settings::EXPIRY_WARNING_DAYS => 120,
            Settings::PROOF_MAX_PHOTOS => 5,
        ]))->assertSessionHas('success');

        $this->assertSame(120, ShelfLife::warningDays());
        $this->assertSame(5, DeliveryProof::maksFoto());
    }

    public function test_umur_simpan_log_ikut_berubah(): void
    {
        $this->login();

        $this->assertSame(90, ActivityLog::umurSimpanHari());

        $this->post(route('wms.admin.settings.update'), $this->bawaan([
            Settings::ACTIVITY_RETENTION_DAYS => 180,
        ]))->assertSessionHas('success');

        $this->assertSame(180, ActivityLog::umurSimpanHari());
    }

    /* --------------------------------------------------------- Batas nilai */

    public function test_nilai_di_luar_batas_ditolak(): void
    {
        $this->login();

        // Jam cutoff 0 akan mengunci seluruh Sales sepanjang hari.
        $this->post(route('wms.admin.settings.update'), $this->bawaan([
            Settings::ORDER_CUTOFF_HOUR => 0,
        ]))->assertSessionHasErrors(Settings::ORDER_CUTOFF_HOUR);

        // Retensi log 1 hari menghapus jejak yang belum sempat dibaca.
        $this->post(route('wms.admin.settings.update'), $this->bawaan([
            Settings::ACTIVITY_RETENTION_DAYS => 1,
        ]))->assertSessionHasErrors(Settings::ACTIVITY_RETENTION_DAYS);

        $this->assertSame(15, OrderCutoff::hour());
        $this->assertSame(90, ActivityLog::umurSimpanHari());
    }

    /* ------------------------------------------------------------- Jejak */

    public function test_perubahan_setelan_tercatat_di_log_aktivitas(): void
    {
        $super = $this->login();

        $this->post(route('wms.admin.settings.update'), $this->bawaan([
            Settings::ACTIVITY_RETENTION_DAYS => 365,
        ]))->assertSessionHas('success');

        $log = ActivityLog::query()->where('action', ActivityLog::SETTINGS_UPDATE)->first();

        $this->assertNotNull($log, 'Perubahan setelan wajib meninggalkan jejak.');
        $this->assertSame($super->id, $log->user_id);
        $this->assertStringContainsString('90', $log->description);
        $this->assertStringContainsString('365', $log->description);
    }

    /** Menyimpan tanpa mengubah apa pun tidak boleh mengotori log. */
    public function test_menyimpan_tanpa_perubahan_tidak_mencatat_apa_pun(): void
    {
        $this->login();

        $this->post(route('wms.admin.settings.update'), $this->bawaan())
            ->assertSessionHas('success');

        $this->assertSame(0, ActivityLog::query()->where('action', ActivityLog::SETTINGS_UPDATE)->count());
    }

    /* ------------------------------------------- Penomoran Dokumen: baca-saja */

    /**
     * Halaman lamanya menyodorkan kolom prefix dan nomor urut yang bisa
     * diketik — nilainya karangan, dan tombolnya tidak menyimpan apa pun.
     * Yang benar bukan membuatnya bisa disimpan, melainkan tidak ada sama
     * sekali: mengganti prefix memecah riwayat, dan menggeser nomor mundur
     * menghentikan pembuatan pesanan untuk semua orang.
     */
    public function test_penomoran_dokumen_tidak_punya_rute_tulis(): void
    {
        $this->login();

        $this->post('/wms/admin/sequence')->assertStatus(405);
    }

    public function test_halaman_penomoran_menampilkan_format_sungguhan(): void
    {
        // Halaman ini menampilkan contoh nomor bertanggal hari ini. Tanpa
        // membekukan waktu, test yang kebetulan berjalan melewati tengah
        // malam menagih tanggal yang berbeda dari yang dirender.
        $this->freezeTime();

        $this->login();

        $tanggal = now()->format('ymd');

        $this->get(route('wms.admin.sequence'))
            ->assertOk()
            // Format sungguhan, bukan "PO-{YYYY}-{MM}-" yang dulu dikarang.
            ->assertSee('PO'.$tanggal.'001')
            ->assertSee('RJ'.$tanggal.'001')
            ->assertSee('Kenapa tidak bisa diubah')
            // Tidak ada satu pun kolom isian.
            ->assertDontSee('<input type="number"', false);
    }

    /* -------------------------------------------------------------- Bawaan */

    /**
     * Setelan yang belum pernah diubah TIDAK menyimpan baris apa pun. Nilai
     * bawaannya tinggal di kode, jadi setelan baru langsung hidup tanpa
     * migrasi pengisi — dan yang dihapus dari kode berhenti terbaca.
     */
    public function test_setelan_bawaan_tidak_menulis_baris(): void
    {
        $this->assertSame(0, SystemSetting::count());
        $this->assertSame(15, Settings::get(Settings::ORDER_CUTOFF_HOUR));
    }
}
