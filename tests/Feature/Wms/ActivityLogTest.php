<?php

namespace Tests\Feature\Wms;

use App\Models\ActivityLog;
use App\Models\DeliveryNote;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\MaterialRequisition;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Log aktivitas — siapa melakukan apa, kapan (permintaan pemilik produk).
 *
 * EMPAT HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * ---------------------------------------------------
 * 1. APPEND-ONLY. Log yang bisa disunting oleh orang yang tercatat di dalamnya
 *    bukan log. Ditegakkan di model, bukan sekadar tidak disediakan rutenya.
 * 2. SUPER ADMIN SAJA. Manager sengaja ditolak walau ia ikut di hampir semua
 *    gate admin lain — log ini merekam tindakan Manager juga.
 * 3. NAMA PELAKU DISALIN SEBAGAI TEKS. Akun dihapus tidak boleh ikut
 *    menghapus jejak perbuatannya.
 * 4. MENCATAT TIDAK BOLEH MENGGAGALKAN TINDAKANNYA. Stok yang sah harus tetap
 *    tersimpan walau catatannya gagal ditulis.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create(['code' => 'WH-01']);
        $this->produk = Product::factory()->create(['sku' => 'ID1-F00113202225', 'uom' => 'PAIL']);
    }

    private function login(string $slug): User
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

    private function rak(string $kode): Location
    {
        $parts = Location::parseCode($kode);

        return Location::firstOrCreate(
            ['warehouse_id' => $this->warehouse->id, 'code' => $kode],
            [
                'rack' => $parts['rack'], 'level' => $parts['level'], 'cell' => $parts['cell'],
                'zone' => Location::ZONE_FAST, 'is_active' => true,
            ]
        );
    }

    private function stock(array $overrides = []): InventoryStock
    {
        return InventoryStock::factory()->create(array_merge([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->rak('B-01-01')->id,
            'batch_no' => 'BT-2026-001',
            'status' => InventoryStock::STATUS_ACTIVE,
            'qty_available' => 50,
            'qty_allocated' => 0,
        ], $overrides));
    }

    /* --------------------------------------------------------- Append-only */

    public function test_log_tidak_boleh_diubah(): void
    {
        $log = ActivityLog::create([
            'action' => ActivityLog::STOCK_ADJUST,
            'description' => 'Contoh.',
            'created_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);

        $log->update(['description' => 'Diubah diam-diam.']);
    }

    public function test_log_tidak_boleh_dihapus(): void
    {
        $log = ActivityLog::create([
            'action' => ActivityLog::STOCK_ADJUST,
            'description' => 'Contoh.',
            'created_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);

        $log->delete();
    }

    /* ------------------------------------------------------- Siapa membaca */

    public function test_super_admin_dapat_membuka_log(): void
    {
        $this->login(Role::SUPER_ADMIN);

        $this->get(route('wms.admin.activity-log'))->assertOk();
    }

    /**
     * Rincian berbentuk daftar atau data bertingkat tetap bisa dibaca.
     *
     * Dahulu satu baris log berisi daftar (mis. kolom_berubah dari ubah master
     * data) mematikan SELURUH halaman dengan "htmlspecialchars(): array given",
     * sehingga Super Admin tidak bisa membaca log apa pun.
     */
    public function test_rincian_berupa_daftar_dan_data_bertingkat_tidak_mematikan_halaman(): void
    {
        $this->login(Role::SUPER_ADMIN);

        ActivityLog::create([
            'action' => ActivityLog::MASTER_UPDATE,
            'description' => 'Mengubah produk APKO-001.',
            'properties' => [
                'kolom_berubah' => ['max_qty_per_pallet', 'updated_at'],
                'perubahan' => [['batch' => 'I126090020', 'palet' => 1, 'semula' => 10, 'menjadi' => 8]],
                'penyaring' => [],
                'aktif' => false,
                'catatan' => null,
            ],
            'created_at' => now(),
        ]);

        $this->get(route('wms.admin.activity-log'))
            ->assertOk()
            ->assertSee('max_qty_per_pallet, updated_at')
            ->assertSee('"batch":"I126090020"')
            ->assertSee('tidak');
    }

    public function test_nilai_rincian_diubah_ke_teks_yang_terbaca(): void
    {
        $this->assertSame('—', ActivityLog::tampilkanNilai(null));
        $this->assertSame('—', ActivityLog::tampilkanNilai([]));
        $this->assertSame('ya', ActivityLog::tampilkanNilai(true));
        $this->assertSame('40', ActivityLog::tampilkanNilai(40));
        $this->assertSame('12, 15', ActivityLog::tampilkanNilai([12, 15]));
        $this->assertSame('{"menunggak":1,"jatuh_tempo_terlama":"2026-09-21"}', ActivityLog::tampilkanNilai([
            'menunggak' => 1, 'jatuh_tempo_terlama' => '2026-09-21',
        ]));
    }

    public function test_manager_ditolak_membuka_log(): void
    {
        $this->login(Role::MANAGER);

        $this->get(route('wms.admin.activity-log'))->assertForbidden();
    }

    public function test_logistik_ditolak_membuka_log(): void
    {
        $this->login(Role::LOGISTICS);

        $this->get(route('wms.admin.activity-log'))->assertForbidden();
    }

    /**
     * Halamannya tidak boleh menjanjikan tindakan yang tidak ada.
     *
     * Tombol reset penyaring sempat berlabel "Bersihkan" — pada halaman log,
     * kata itu terbaca sebagai membuang isinya. Yang menekannya lalu melihat
     * daftar kembali penuh akan mengira penghapusan gagal, padahal log memang
     * tidak bisa dihapus siapa pun. Sekalian dipastikan batas simpannya
     * tertulis, supaya yang mencari kejadian lama tahu kenapa tidak ketemu.
     */
    public function test_halaman_log_tidak_menawarkan_penghapusan(): void
    {
        $this->login(Role::SUPER_ADMIN);

        $this->get(route('wms.admin.activity-log'))
            ->assertOk()
            ->assertDontSee('Bersihkan')
            ->assertSee('Reset Filter')
            ->assertSee('tidak bisa diubah maupun dihapus')
            ->assertSee(ActivityLog::umurSimpanHari().' hari terakhir');
    }

    /* ------------------------------------------------- Tindakan yang dicatat */

    public function test_koreksi_stok_tercatat_beserta_nilai_sebelum_dan_sesudah(): void
    {
        $manager = $this->login(Role::MANAGER);
        $stok = $this->stock(['qty_available' => 50]);

        $this->post('/wms/inventory/adjust', [
            'stock_id' => $stok->id,
            'qty_new' => 40,
            'reason' => 'Hasil hitung ulang rak.',
        ]);

        $log = ActivityLog::where('action', ActivityLog::STOCK_ADJUST)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($manager->id, $log->user_id);
        $this->assertSame($manager->full_name, $log->user_name, 'Nama pelaku disalin, bukan cuma dirujuk.');
        $this->assertSame(50, $log->properties['qty_sebelum']);
        $this->assertSame(40, $log->properties['qty_sesudah']);
        $this->assertSame('Hasil hitung ulang rak.', $log->properties['alasan']);
    }

    public function test_pemindahan_rak_tercatat(): void
    {
        $this->login(Role::LOGISTICS);
        $stok = $this->stock();
        $this->rak('B-02-01');

        $this->post('/wms/inventory/transfer', [
            'stock_id' => $stok->id,
            'to_location_code' => 'B-02-01',
            'qty' => 10,
            'reason' => 'Merapikan rak.',
        ]);

        $log = ActivityLog::where('action', ActivityLog::STOCK_TRANSFER)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('B-01-01', $log->properties['dari_rak']);
        $this->assertSame('B-02-01', $log->properties['ke_rak']);
        $this->assertSame(10, $log->properties['qty']);
    }

    public function test_penanda_dahulukan_keluar_tercatat_beserta_alasannya(): void
    {
        $this->login(Role::LOGISTICS);
        $stok = $this->stock();

        $this->post(route('wms.inventory.prioritize'), [
            'stock_id' => $stok->id,
            'reason' => 'Diminta customer PT Aneka.',
        ]);

        $log = ActivityLog::where('action', ActivityLog::PRIORITIZE)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('Diminta customer PT Aneka', $log->description);
    }

    public function test_karantina_tercatat(): void
    {
        $this->login(Role::LOGISTICS);
        $stok = $this->stock();

        $this->post(route('wms.inventory.quarantine'), [
            'stock_id' => $stok->id,
            'days' => 14,
        ]);

        $log = ActivityLog::where('action', ActivityLog::QUARANTINE_PLACE)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame(14, $log->properties['hari']);
    }

    /* -------------------------------------------- Nomor & jenis transaksi */

    /**
     * Nomor dokumennya disalin ke kolomnya sendiri saat dicatat.
     *
     * Bukan kenyamanan tampilan: selama nomornya hanya ada di dalam kalimat
     * keterangan, "tunjukkan seluruh jejak MRF ini" hanya bisa dijawab dengan
     * membaca ratusan baris satu per satu.
     */
    public function test_nomor_transaksi_disalin_saat_mencatat(): void
    {
        $this->login(Role::MANAGER);

        $mrf = MaterialRequisition::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'mrf_number' => 'MRF2609099',
        ]);

        Activity::record(ActivityLog::MRF_CREATE, 'Permintaan material dibuat.', $mrf, $this->warehouse->id);

        $log = ActivityLog::latest('id')->firstOrFail();

        $this->assertSame('MRF2609099', $log->reference_number);
        $this->assertSame('MRF', $log->kode_jenis);
        $this->assertSame('Permintaan Material', $log->label_jenis);
    }

    /** Tindakan tanpa dokumen tetap tercatat, kolom nomornya saja yang kosong. */
    public function test_tindakan_tanpa_dokumen_tidak_memaksakan_nomor(): void
    {
        $this->login(Role::MANAGER);

        Activity::record(ActivityLog::STOCK_ADJUST, 'Koreksi tanpa dokumen induk.', null, $this->warehouse->id);

        $log = ActivityLog::latest('id')->firstOrFail();

        $this->assertNull($log->reference_number);
        $this->assertSame('—', $log->kode_jenis);
    }

    /**
     * Penyaring jenis memakai subject_type, bukan huruf depan nomornya.
     *
     * Dokumen dengan nomor tanpa huruf sama sekali — Surat Jalan dari BC
     * berbunyi "206223" — tetap harus bisa disaring, dan itu mustahil kalau
     * jenisnya ditebak dari teks nomornya.
     */
    public function test_log_bisa_disaring_per_jenis_transaksi(): void
    {
        $this->login(Role::SUPER_ADMIN);

        $mrf = MaterialRequisition::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'mrf_number' => 'MRF2609098',
        ]);
        $surat = DeliveryNote::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'document_no' => '206223',
        ]);

        Activity::record(ActivityLog::MRF_CREATE, 'Permintaan material dibuat.', $mrf, $this->warehouse->id);
        Activity::record(ActivityLog::DELIVERY_SHIP, 'Kiriman berangkat.', $surat, $this->warehouse->id);

        $this->get(route('wms.admin.activity-log', ['jenis' => DeliveryNote::class]))
            ->assertOk()
            ->assertSee('206223')
            ->assertDontSee('MRF2609098');

        // Nomor yang tanpa huruf tetap ketemu lewat pencarian.
        $this->get(route('wms.admin.activity-log', ['search' => '206223']))
            ->assertOk()
            ->assertSee('Kiriman berangkat.')
            ->assertDontSee('MRF2609098');

        // Jenis yang tidak dikenal diabaikan, bukan menjatuhkan halaman.
        $this->get(route('wms.admin.activity-log', ['jenis' => 'App\Models\TidakAda']))
            ->assertOk()
            ->assertViewHas('filters', fn (array $f) => $f['jenis'] === null);
    }

    /* ------------------------------------------------------------ Ketahanan */

    public function test_stok_tetap_tersimpan_walau_pencatatan_log_gagal(): void
    {
        $this->login(Role::MANAGER);
        $stok = $this->stock(['qty_available' => 50]);

        // Penulisan log dibuat gagal. Ini persis bentuk kegagalan yang tidak boleh
        // ikut menjatuhkan tindakan yang sudah sah — koreksi stok yang benar
        // tidak boleh batal cuma karena catatannya gagal ditulis.
        // Digagalkan lewat event model, BUKAN dengan membuang tabelnya:
        // galat SQL akan meracuni transaksi test itu sendiri, sehingga yang
        // terbaca justru kegagalan test-nya, bukan perilaku yang diuji.
        ActivityLog::creating(function () {
            throw new RuntimeException('Penulisan log sengaja digagalkan.');
        });

        $this->post('/wms/inventory/adjust', [
            'stock_id' => $stok->id,
            'qty_new' => 40,
            'reason' => 'Hasil hitung ulang rak.',
        ])->assertSessionHas('success');

        $this->assertSame(40, $stok->fresh()->qty_available);
    }
}
