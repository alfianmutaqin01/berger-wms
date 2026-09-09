<?php

namespace Tests\Feature\Wms;

use App\Models\ActivityLog;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Reporting\AdminDashboard;
use App\Support\Reporting\ReportRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dashboard utama — angka nyata, dan kartu yang dibatasi per peran.
 *
 * TIGA HAL YANG DIJAGA DI SINI
 * ----------------------------
 * 1. ANGKANYA DARI DATA. Sebelum ini seluruh dashboard adalah angka yang
 *    ditulis tangan di Blade ("156 PO"). Angka palsu lebih berbahaya daripada
 *    tidak ada angka: orang mengambil keputusan darinya.
 * 2. KARTU YANG TIDAK BOLEH DILIHAT TIDAK IKUT TERKIRIM. Bukan sekadar
 *    disembunyikan lewat CSS atau @can — kuncinya memang tidak ada di data
 *    halaman, jadi tidak ada yang bisa dibaca dari "view source".
 * 3. BATAS GUDANG BERLAKU DI SINI JUGA. Dashboard adalah satu-satunya layar
 *    yang menjumlahkan segalanya sekaligus, dan justru karena itu ia jadi
 *    tempat paling mudah membocorkan angka gudang lain.
 */
class DashboardAdminTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $gudang;

    private Warehouse $gudangLain;

    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->create(['code' => 'WH-01']);
        $this->gudangLain = Warehouse::factory()->create(['code' => 'WH-02']);
        $this->produk = Product::factory()->create(['sku' => 'ID1-F00113202225', 'uom' => 'PAIL']);
    }

    /* ------------------------------------------------------------ Perkakas */

    private function login(string $slug, ?Warehouse $gudang = null): User
    {
        $user = User::factory()->withRole($slug)->create([
            'warehouse_id' => $gudang?->id,
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

    private function pesanan(string $status, ?Warehouse $gudang = null, array $extra = []): SalesOrder
    {
        return SalesOrder::factory()->create(array_merge([
            'warehouse_id' => ($gudang ?? $this->gudang)->id,
            'status' => $status,
            'submitted_at' => now()->subDays(3),
        ], $extra));
    }

    private function stok(array $extra = []): InventoryStock
    {
        $lokasi = Location::factory()->create([
            'warehouse_id' => ($extra['warehouse'] ?? $this->gudang)->id,
        ]);

        unset($extra['warehouse']);

        return InventoryStock::factory()->create(array_merge([
            'product_id' => $this->produk->id,
            'location_id' => $lokasi->id,
            'warehouse_id' => $lokasi->warehouse_id,
            'batch_no' => 'BT-'.Str::random(4),
            'qty_available' => 100,
            'qty_allocated' => 0,
            'production_date' => now()->subMonth(),
            'expiry_date' => now()->addYears(2),
            'status' => InventoryStock::STATUS_ACTIVE,
            'verified_at' => now(),
        ], $extra));
    }

    /** Batch yang benar-benar dikarantina — constraint DB menuntut semuanya terisi. */
    private function stokKarantina(int $hariLagi, int $qty): InventoryStock
    {
        return $this->stok([
            'status' => InventoryStock::STATUS_QUARANTINE,
            'qty_available' => $qty,
            'quarantine_days' => $hariLagi,
            'quarantine_until' => now()->addDays($hariLagi),
            'quarantined_at' => now(),
            'quarantined_by' => auth()->id(),
        ]);
    }

    /** Metrik apa adanya, tanpa lewat HTTP — untuk menguji angkanya sendiri. */
    private function metrik(User $user): array
    {
        return app(AdminDashboard::class)->untuk($user);
    }

    /* --------------------------------------------- Angkanya berasal dari data */

    public function test_kartu_alur_harian_menghitung_dari_data_bukan_angka_tetap(): void
    {
        $admin = $this->login(Role::SUPER_ADMIN);

        $this->pesanan(SalesOrder::STATUS_PENDING);
        $this->pesanan(SalesOrder::STATUS_PENDING);
        $this->pesanan(SalesOrder::STATUS_APPROVED, extra: ['picking_list_id' => null]);
        $this->pesanan(SalesOrder::STATUS_COMPLETED);

        $m = $this->metrik($admin);

        $this->assertSame(2, $m['menunggu_diterima']['jumlah']);
        $this->assertSame(1, $m['siap_dipicking']['jumlah']);
    }

    /** Umur antrean, bukan cuma jumlahnya — 3 pesanan lama lebih gawat dari 12 baru. */
    public function test_pesanan_tertua_dilaporkan_dalam_hari(): void
    {
        $admin = $this->login(Role::SUPER_ADMIN);

        $this->pesanan(SalesOrder::STATUS_PENDING, extra: ['submitted_at' => now()->subDays(9)]);
        $this->pesanan(SalesOrder::STATUS_PENDING, extra: ['submitted_at' => now()->subDay()]);

        $m = $this->metrik($admin);

        $this->assertSame(9, $m['menunggu_diterima']['tertua_hari']);
    }

    public function test_outstanding_dijumlahkan_dari_baris_pesanan(): void
    {
        $admin = $this->login(Role::SUPER_ADMIN);

        $pesanan = $this->pesanan(SalesOrder::STATUS_COMPLETED);

        SalesOrderDetail::factory()->create([
            'sales_order_id' => $pesanan->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 10,
            'qty_approved' => 4,
            'qty_shipped' => 4,
            'outstanding_qty' => 6,
        ]);

        // SKU kedua, dan itu perlu: satu pesanan tidak boleh punya dua baris
        // untuk produk yang sama, dan yang diuji di sini justru bahwa baris
        // yang sudah lunas tidak ikut menambah angka outstanding.
        SalesOrderDetail::factory()->create([
            'sales_order_id' => $pesanan->id,
            'product_id' => Product::factory()->create(['sku' => 'ID1-F00573202825'])->id,
            'qty_ordered' => 5,
            'qty_approved' => 5,
            'qty_shipped' => 5,
            'outstanding_qty' => 0,
        ]);

        $m = $this->metrik($admin);

        $this->assertSame(6, $m['outstanding']['qty']);
        $this->assertSame(1, $m['outstanding']['pesanan']);
    }

    public function test_batch_yang_segera_kedaluwarsa_dihitung_dalam_ambang(): void
    {
        $admin = $this->login(Role::SUPER_ADMIN);

        $this->stok(['expiry_date' => now()->addDays(30), 'qty_available' => 40]);
        $this->stok(['expiry_date' => now()->addDays(200), 'qty_available' => 90]);

        $m = $this->metrik($admin);

        $this->assertSame(1, $m['segera_kedaluwarsa']['batch']);
        $this->assertSame(40, $m['segera_kedaluwarsa']['qty']);
    }

    /**
     * Yang dicari di kartu karantina bukan "berapa ditahan" melainkan "berapa
     * yang sebentar lagi boleh dijual" — itu yang menentukan pesanan mana bisa
     * dijanjikan pekan ini.
     */
    public function test_karantina_melaporkan_yang_lepas_pekan_ini(): void
    {
        $admin = $this->login(Role::SUPER_ADMIN);

        $this->stokKarantina(hariLagi: 3, qty: 25);
        $this->stokKarantina(hariLagi: 40, qty: 15);

        $m = $this->metrik($admin);

        $this->assertSame(2, $m['karantina']['batch']);
        $this->assertSame(40, $m['karantina']['qty']);
        $this->assertSame(1, $m['karantina']['lepas_pekan_ini']);
    }

    /**
     * Kekurangan 20 di satu rak dan kelebihan 20 di rak sebelah BUKAN nol
     * selisih — itu dua kesalahan, dan neto akan menyembunyikan keduanya.
     */
    public function test_selisih_stocktake_dijumlahkan_mutlak_bukan_neto(): void
    {
        $admin = $this->login(Role::SUPER_ADMIN);

        $sesi = StockTake::create([
            'reference' => 'ST-UJI-01',
            'warehouse_id' => $this->gudang->id,
            'scope_type' => 'warehouse',
            'scope_value' => null,
            'status' => StockTake::STATUS_FINALIZED,
            'opened_at' => now()->subDays(2),
            'opened_by' => $admin->id,
            'finalized_at' => now()->subDay(),
            'finalized_by' => $admin->id,
        ]);

        // Satu baris hitungan per baris stok — itu memang aturannya di DB,
        // jadi tiga delta berarti tiga rak yang berbeda.
        foreach ([-20, 20, 0] as $delta) {
            $stok = $this->stok();

            StockTakeItem::create([
                'stock_take_id' => $sesi->id,
                'inventory_stock_id' => $stok->id,
                'location_id' => $stok->location_id,
                'product_id' => $this->produk->id,
                'batch_no' => $stok->batch_no,
                'qty_system' => 100,
                'qty_physical' => 100 + $delta,
                'counted_at' => now()->subDay(),
                'counted_by' => $admin->id,
                'applied_delta' => $delta,
                'qty_after' => 100 + $delta,
            ]);
        }

        $m = $this->metrik($admin);

        $this->assertSame(2, $m['stocktake']['terakhir']['selisih_baris']);
        $this->assertSame(40, $m['stocktake']['terakhir']['selisih_qty']);
    }

    /** Bulan sepi tetap digambar nol, bukan dilompati. */
    public function test_grafik_tren_selalu_berisi_enam_bulan_penuh(): void
    {
        $admin = $this->login(Role::SUPER_ADMIN);

        $this->pesanan(SalesOrder::STATUS_COMPLETED, extra: [
            'submitted_at' => now(),
            'completed_at' => now(),
        ]);

        $m = $this->metrik($admin);

        $this->assertCount(AdminDashboard::BULAN_TREN, $m['tren']['label']);
        $this->assertCount(AdminDashboard::BULAN_TREN, $m['tren']['masuk']);
        $this->assertSame(1, end($m['tren']['masuk']));
        $this->assertSame(1, end($m['tren']['selesai']));
    }

    /* ------------------------------------------------------ Pembatasan kartu */

    public function test_logistik_tidak_mendapat_kartu_pengawasan_sama_sekali(): void
    {
        $logistik = $this->login(Role::LOGISTICS, $this->gudang);

        $m = $this->metrik($logistik);

        // Alur hariannya tetap lengkap — ini pekerjaan Logistik sehari-hari.
        $this->assertArrayHasKey('menunggu_diterima', $m);
        $this->assertArrayHasKey('outstanding', $m);
        $this->assertArrayHasKey('karantina', $m);

        // Yang dipakai untuk MENILAI pekerjaan gudang tidak ikut.
        $this->assertArrayNotHasKey('koreksi_stok', $m);
        $this->assertArrayNotHasKey('stocktake', $m);
        $this->assertArrayNotHasKey('pengguna', $m);
        $this->assertArrayNotHasKey('aktivitas', $m);
    }

    public function test_manager_mendapat_kartu_pengawasan_tetapi_bukan_log_aktivitas(): void
    {
        $manager = $this->login(Role::MANAGER, $this->gudang);

        $m = $this->metrik($manager);

        $this->assertArrayHasKey('koreksi_stok', $m);
        $this->assertArrayHasKey('stocktake', $m);
        $this->assertArrayHasKey('pengguna', $m);

        // Log merekam tindakan Manager juga; yang diawasi tidak memegang
        // jendela pengawasnya sendiri.
        $this->assertArrayNotHasKey('aktivitas', $m);
    }

    public function test_super_admin_mendapat_seluruh_kartu(): void
    {
        $admin = $this->login(Role::SUPER_ADMIN);

        $m = $this->metrik($admin);

        foreach (['menunggu_diterima', 'outstanding', 'siap_dipicking', 'picking_berjalan',
            'dalam_pengiriman', 'bukti_menunggu', 'inbound_menunggu', 'karantina',
            'segera_kedaluwarsa', 'tren', 'koreksi_stok', 'stocktake', 'pengguna',
            'aktivitas'] as $kartu) {
            $this->assertArrayHasKey($kartu, $m, "Kartu $kartu hilang dari Super Admin.");
        }
    }

    /**
     * Menyembunyikan kotaknya tidak cukup: angkanya tidak boleh sampai ke
     * halaman sama sekali.
     */
    public function test_angka_kartu_terlarang_tidak_ikut_terkirim_ke_halaman(): void
    {
        $this->login(Role::LOGISTICS, $this->gudang);

        StockMovement::create([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->gudang->id,
            'movement_type' => StockMovement::TYPE_ADJUSTMENT,
            'qty_change' => -777,
            'qty_before' => 1000,
            'qty_after' => 223,
            'reference_type' => 'uji',
            'reference_id' => 1,
            'created_at' => now(),
        ]);

        $respons = $this->get('/wms/dashboard/admin')->assertOk();

        $respons->assertViewHas('m', fn ($m) => ! array_key_exists('koreksi_stok', $m));
        $respons->assertDontSee('777');
        $respons->assertDontSee('Pengawasan');
    }

    public function test_produksi_dan_operator_ditolak_membuka_dashboard_utama(): void
    {
        $this->login(Role::PRODUCTION, $this->gudang);
        $this->get('/wms/dashboard/admin')->assertForbidden();

        $this->login(Role::WAREHOUSE_OPERATOR, $this->gudang);
        $this->get('/wms/dashboard/admin')->assertForbidden();
    }

    /* ------------------------------------------------------------ Batas gudang */

    public function test_manager_hanya_melihat_angka_gudangnya_sendiri(): void
    {
        $manager = $this->login(Role::MANAGER, $this->gudang);

        $this->pesanan(SalesOrder::STATUS_PENDING, $this->gudang);
        $this->pesanan(SalesOrder::STATUS_PENDING, $this->gudangLain);
        $this->pesanan(SalesOrder::STATUS_PENDING, $this->gudangLain);

        $m = $this->metrik($manager);

        $this->assertSame(1, $m['menunggu_diterima']['jumlah']);
    }

    public function test_super_admin_melihat_seluruh_gudang(): void
    {
        $admin = $this->login(Role::SUPER_ADMIN);

        $this->pesanan(SalesOrder::STATUS_PENDING, $this->gudang);
        $this->pesanan(SalesOrder::STATUS_PENDING, $this->gudangLain);

        $m = $this->metrik($admin);

        $this->assertSame(2, $m['menunggu_diterima']['jumlah']);
    }

    public function test_stok_gudang_lain_tidak_ikut_terhitung(): void
    {
        $manager = $this->login(Role::MANAGER, $this->gudang);

        $this->stok(['expiry_date' => now()->addDays(10), 'qty_available' => 30]);
        $this->stok([
            'warehouse' => $this->gudangLain,
            'expiry_date' => now()->addDays(10),
            'qty_available' => 500,
        ]);

        $m = $this->metrik($manager);

        $this->assertSame(1, $m['segera_kedaluwarsa']['batch']);
        $this->assertSame(30, $m['segera_kedaluwarsa']['qty']);
    }

    /* ------------------------------------------------------- Halaman utuh */

    public function test_halaman_dashboard_terbuka_dan_menampilkan_angka_nyata(): void
    {
        $admin = $this->login(Role::SUPER_ADMIN);

        $this->pesanan(SalesOrder::STATUS_PENDING);
        $this->pesanan(SalesOrder::STATUS_PENDING);
        $this->pesanan(SalesOrder::STATUS_PENDING);

        ActivityLog::create([
            'user_id' => $admin->id,
            'user_name' => $admin->full_name,
            'user_role' => Role::SUPER_ADMIN,
            'action' => ActivityLog::STOCK_ADD,
            'description' => 'Menambah stok uji coba dashboard.',
            'created_at' => now(),
        ]);

        $this->get('/wms/dashboard/admin')
            ->assertOk()
            ->assertSee('Butuh Diterima')
            ->assertSee('Pengawasan')
            ->assertSee('Aktivitas Terbaru')
            ->assertSee('Menambah stok uji coba dashboard.')
            // Angka dummy lama yang harus benar-benar hilang.
            ->assertDontSee('156 PO')
            ->assertDontSee('Toko Makmur');
    }

    /* ------------------------------------------------- Papan peringkat */

    /**
     * Papan peringkat dashboard dan laporan Produk Terlaris WAJIB sepakat.
     *
     * Keduanya menjawab pertanyaan yang persis sama, jadi kalau dashboard
     * punya query sendiri cukup satu perbedaan kecil untuk membuat sebuah
     * produk jadi nomor satu di layar tetapi nomor tiga di berkas Excel —
     * dan tidak ada cara menebak mana yang benar. Karena itu kartunya
     * memanggil ReportRunner yang sama, dan test ini menguncinya.
     */
    public function test_papan_peringkat_dashboard_sama_dengan_laporan(): void
    {
        $admin = $this->login(Role::SUPER_ADMIN);

        $laris = Product::factory()->create(['sku' => 'LARIS-1', 'name' => 'Paling Laku']);
        $sepi = Product::factory()->create(['sku' => 'SEPI-1', 'name' => 'Jarang Keluar']);

        // Dipesan paling banyak tetapi hampir tidak pernah terkirim: tidak
        // boleh menyalip yang benar-benar keluar.
        $this->pesananTerkirim($sepi, dipesan: 900, terkirim: 3);
        $this->pesananTerkirim($laris, dipesan: 60, terkirim: 60);

        $peringkat = $this->metrik($admin)['terlaris'];

        $this->assertSame('LARIS-1', $peringkat['produk'][0]['sku']);
        $this->assertSame(60, $peringkat['produk'][0]['terkirim']);
        $this->assertSame('SEPI-1', $peringkat['produk'][1]['sku']);
        $this->assertSame(AdminDashboard::HARI_PERINGKAT, $peringkat['hari']);

        $laporan = (new ReportRunner)->jalankan('produk-terlaris', $admin, [
            'dari' => now()->subDays(AdminDashboard::HARI_PERINGKAT)->toDateString(),
            'sampai' => now()->toDateString(),
            'warehouse_id' => null,
        ], AdminDashboard::PUNCAK);

        $this->assertSame(
            array_column($peringkat['produk'], 'sku'),
            array_map(fn ($b) => $b[1], $laporan['baris']),
            'Urutan di dashboard harus identik dengan urutan di laporan.',
        );

        $this->get('/wms/dashboard/admin')
            ->assertOk()
            ->assertSee('Produk Terlaris')
            ->assertSee('Paling Laku')
            ->assertSee('Pelanggan Teratas');
    }

    /** Logistik ikut melihat papan peringkat — izinnya REPORTS_VIEW. */
    public function test_logistik_ikut_melihat_papan_peringkat(): void
    {
        $logistik = $this->login(Role::LOGISTICS);

        $this->assertArrayHasKey('terlaris', $this->metrik($logistik));
    }

    private function pesananTerkirim(Product $produk, int $dipesan, int $terkirim): void
    {
        $order = $this->pesanan(SalesOrder::STATUS_COMPLETED, extra: [
            'shipped_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
        ]);

        SalesOrderDetail::create([
            'sales_order_id' => $order->id,
            'product_id' => $produk->id,
            'qty_ordered' => $dipesan,
            'qty_approved' => $dipesan,
            'qty_shipped' => $terkirim,
            'outstanding_qty' => max($dipesan - $terkirim, 0),
        ]);
    }
}
