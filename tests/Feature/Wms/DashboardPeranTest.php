<?php

namespace Tests\Feature\Wms;

use App\Models\InboundDetail;
use App\Models\InboundHeader;
use App\Models\PickingList;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockTake;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Reporting\OperatorDashboard;
use App\Support\Reporting\ProductionDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dashboard Produksi & Operator — isinya sesuai pekerjaannya, tidak lebih.
 *
 * DUA HAL YANG DIJAGA DI SINI
 * ---------------------------
 * 1. ANGKA YANG MENJANJIKAN MODUL YANG TIDAK ADA SUDAH HILANG. Dashboard
 *    Produksi lama menampilkan target produksi, mesin aktif, dan stok bahan
 *    baku menipis — tidak satu pun tabelnya ada di sistem ini. Angka seperti
 *    itu lebih berbahaya daripada halaman kosong: orang menunggu ia berubah
 *    sendiri suatu hari.
 * 2. KARTU YANG TIDAK ADA PEKERJAANNYA TIDAK DIGAMBAR. Di layar Operator,
 *    stocktake dan "tugas saya" hanya muncul kalau memang ada. Kartu kosong
 *    bertuliskan nol bukan informasi — ia membuat yang benar-benar perlu
 *    dikerjakan tenggelam.
 */
class DashboardPeranTest extends TestCase
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
            'warehouse_id' => ($gudang ?? $this->gudang)->id,
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

    /** Satu dokumen inbound beserta paletnya. */
    private function inbound(string $status, int $palet = 2, ?Warehouse $gudang = null, array $isiDetail = []): InboundHeader
    {
        $header = InboundHeader::create([
            'document_number' => 'IN-'.Str::upper(Str::random(8)),
            'warehouse_id' => ($gudang ?? $this->gudang)->id,
            'production_date' => now()->subDays(2)->toDateString(),
            'status' => $status,
        ]);

        for ($i = 1; $i <= $palet; $i++) {
            InboundDetail::create(array_merge([
                'inbound_header_id' => $header->id,
                'product_id' => $this->produk->id,
                'batch_no' => 'BT-'.Str::random(4),
                'total_qty' => 100,
                'pallet_no' => $i,
                'pallet_qty' => 50,
            ], $isiDetail));
        }

        return $header;
    }

    private function daftarPicking(string $status, array $extra = []): PickingList
    {
        return PickingList::create(array_merge([
            'list_number' => 'PL-'.Str::upper(Str::random(8)),
            'warehouse_id' => $this->gudang->id,
            'status' => $status,
        ], $extra));
    }

    private function metrikProduksi(User $user): array
    {
        return app(ProductionDashboard::class)->untuk($user);
    }

    private function metrikOperator(User $user): array
    {
        return app(OperatorDashboard::class)->untuk($user);
    }

    /* ======================================================== PRODUKSI ==== */

    public function test_produksi_melihat_perjalanan_serahannya_dalam_tiga_tahap(): void
    {
        $produksi = $this->login(Role::PRODUCTION);

        $this->inbound(InboundHeader::STATUS_PUTAWAY_PENDING, palet: 3);
        $this->inbound(InboundHeader::STATUS_VERIFICATION_PENDING);
        $this->inbound(InboundHeader::STATUS_PARTIAL_VERIFIED);

        $m = $this->metrikProduksi($produksi);

        $this->assertSame(1, $m['menunggu_putaway']['dokumen']);
        $this->assertSame(3, $m['menunggu_putaway']['palet']);

        // Dua status berbeda, satu pertanyaan yang sama: stoknya belum resmi.
        $this->assertSame(2, $m['menunggu_verifikasi']['dokumen']);
    }

    public function test_yang_masuk_bulan_ini_dihitung_dari_qty_yang_benar_benar_naik_rak(): void
    {
        $produksi = $this->login(Role::PRODUCTION);

        // Dinyatakan 50 per palet, yang benar-benar naik 45.
        $this->inbound(InboundHeader::STATUS_VERIFIED, palet: 2, isiDetail: ['qty_actual' => 45]);

        $m = $this->metrikProduksi($produksi);

        $this->assertSame(1, $m['masuk_bulan_ini']['dokumen']);
        $this->assertSame(90, $m['masuk_bulan_ini']['unit']);
    }

    /**
     * Angka paling berguna di halaman Produksi, dan satu-satunya kabar buruk:
     * yang dinyatakan tidak sama dengan yang sampai di rak.
     */
    public function test_selisih_putaway_dijumlahkan_mutlak_bukan_neto(): void
    {
        $produksi = $this->login(Role::PRODUCTION);

        $header = $this->inbound(InboundHeader::STATUS_VERIFIED, palet: 3);

        $delta = [-5, 5, 0];

        foreach ($header->details as $i => $detail) {
            $detail->forceFill([
                'qty_actual' => 50 + $delta[$i],
                'putaway_at' => now()->subDay(),
            ])->save();
        }

        $m = $this->metrikProduksi($produksi);

        // Kurang 5 di satu palet dan lebih 5 di palet lain adalah DUA
        // kesalahan, bukan nol.
        $this->assertSame(2, $m['selisih_putaway']['baris']);
        $this->assertSame(10, $m['selisih_putaway']['unit']);
    }

    public function test_selisih_yang_sudah_lewat_ambang_waktu_tidak_ikut(): void
    {
        $produksi = $this->login(Role::PRODUCTION);

        $header = $this->inbound(InboundHeader::STATUS_VERIFIED, palet: 1);

        $header->details->first()->forceFill([
            'qty_actual' => 40,
            'putaway_at' => now()->subDays(ProductionDashboard::HARI_SELISIH + 5),
        ])->save();

        $m = $this->metrikProduksi($produksi);

        $this->assertSame(0, $m['selisih_putaway']['baris']);
    }

    public function test_dokumen_gudang_lain_tidak_muncul_di_dashboard_produksi(): void
    {
        $produksi = $this->login(Role::PRODUCTION);

        $this->inbound(InboundHeader::STATUS_PUTAWAY_PENDING, gudang: $this->gudangLain);

        $m = $this->metrikProduksi($produksi);

        $this->assertSame(0, $m['menunggu_putaway']['dokumen']);
        $this->assertCount(0, $m['terakhir']);
    }

    /**
     * Modul yang memang tidak pernah dibangun tidak boleh dijanjikan di layar.
     */
    public function test_halaman_produksi_tidak_lagi_menjanjikan_modul_yang_tidak_ada(): void
    {
        $this->login(Role::PRODUCTION);

        $this->inbound(InboundHeader::STATUS_PUTAWAY_PENDING);

        $this->get('/wms/dashboard/produksi')
            ->assertOk()
            ->assertSee('Menunggu Naik Rak')
            ->assertSee('Selisih Saat Naik Rak')
            ->assertDontSee('Bahan Baku')
            ->assertDontSee('Mesin Aktif')
            ->assertDontSee('Target Produksi')
            ->assertDontSee('Purchasing');
    }

    /* ======================================================== OPERATOR ==== */

    public function test_tugas_yang_sedang_dipegang_hanya_milik_orangnya_sendiri(): void
    {
        $operator = $this->login(Role::WAREHOUSE_OPERATOR);
        $rekan = User::factory()->withRole(Role::WAREHOUSE_OPERATOR)->create([
            'warehouse_id' => $this->gudang->id,
        ]);

        $this->daftarPicking(PickingList::STATUS_PICKING, [
            'claimed_by' => $operator->id,
            'claimed_at' => now(),
        ]);

        $this->daftarPicking(PickingList::STATUS_PICKING, [
            'claimed_by' => $rekan->id,
            'claimed_at' => now(),
        ]);

        $m = $this->metrikOperator($operator);

        $this->assertCount(1, $m['tugas_saya']);
    }

    public function test_antrean_picking_hanya_menghitung_yang_belum_diambil(): void
    {
        $operator = $this->login(Role::WAREHOUSE_OPERATOR);

        $this->daftarPicking(PickingList::STATUS_OPEN);
        $this->daftarPicking(PickingList::STATUS_OPEN);
        $this->daftarPicking(PickingList::STATUS_PICKING, [
            'claimed_by' => $operator->id,
            'claimed_at' => now(),
        ]);

        $m = $this->metrikOperator($operator);

        $this->assertSame(2, $m['picking_tersedia']['jumlah']);
    }

    /**
     * Stocktake bukan pekerjaan harian. Kartunya baru ada isinya saat sesinya
     * benar-benar dibuka; sisa tahun ia tidak digambar sama sekali.
     */
    public function test_kartu_stocktake_hanya_muncul_saat_sesinya_berjalan(): void
    {
        $operator = $this->login(Role::WAREHOUSE_OPERATOR);

        $this->assertNull($this->metrikOperator($operator)['stocktake']);

        $this->get('/wms/dashboard/operator')
            ->assertOk()
            ->assertDontSee('Stocktake Berjalan');

        StockTake::create([
            'reference' => 'ST-UJI-01',
            'warehouse_id' => $this->gudang->id,
            'scope_type' => 'warehouse',
            'status' => StockTake::STATUS_COUNTING,
            'opened_at' => now(),
            'opened_by' => $operator->id,
        ]);

        $this->assertNotNull($this->metrikOperator($operator)['stocktake']);

        $this->get('/wms/dashboard/operator')
            ->assertOk()
            ->assertSee('Stocktake Berjalan')
            ->assertSee('ST-UJI-01');
    }

    public function test_sesi_stocktake_gudang_lain_tidak_muncul(): void
    {
        $operator = $this->login(Role::WAREHOUSE_OPERATOR);

        StockTake::create([
            'reference' => 'ST-LAIN-01',
            'warehouse_id' => $this->gudangLain->id,
            'scope_type' => 'warehouse',
            'status' => StockTake::STATUS_COUNTING,
            'opened_at' => now(),
            'opened_by' => $operator->id,
        ]);

        $this->assertNull($this->metrikOperator($operator)['stocktake']);
    }

    public function test_riwayat_hanya_berisi_tugas_yang_diselesaikan_sendiri(): void
    {
        $operator = $this->login(Role::WAREHOUSE_OPERATOR);
        $rekan = User::factory()->withRole(Role::WAREHOUSE_OPERATOR)->create([
            'warehouse_id' => $this->gudang->id,
        ]);

        $this->daftarPicking(PickingList::STATUS_COMPLETED, [
            'completed_by' => $operator->id,
            'completed_at' => now()->subHour(),
        ]);

        $this->daftarPicking(PickingList::STATUS_COMPLETED, [
            'completed_by' => $rekan->id,
            'completed_at' => now()->subHour(),
        ]);

        $m = $this->metrikOperator($operator);

        $this->assertCount(1, $m['riwayat']);
    }

    /**
     * Antrean tetap SELALU tampil walau nol — di sana nol adalah kabar yang
     * berguna: tidak ada yang menunggu dikerjakan.
     */
    public function test_antrean_tetap_tampil_meski_kosong(): void
    {
        $this->login(Role::WAREHOUSE_OPERATOR);

        $this->get('/wms/dashboard/operator')
            ->assertOk()
            ->assertSee('Tugas Put-away')
            ->assertSee('Antrean Picking Tersedia')
            ->assertSee('Antrean kosong')
            // Angka dummy lama yang harus benar-benar hilang.
            ->assertDontSee('Stok Rak Menipis')
            ->assertDontSee('Isi Ulang Rak');
    }

    public function test_halaman_operator_menampilkan_tugas_yang_sedang_dipegang(): void
    {
        $operator = $this->login(Role::WAREHOUSE_OPERATOR);

        $daftar = $this->daftarPicking(PickingList::STATUS_PICKING, [
            'claimed_by' => $operator->id,
            'claimed_at' => now(),
        ]);

        $this->get('/wms/dashboard/operator')
            ->assertOk()
            ->assertSee('Sedang Anda Kerjakan')
            ->assertSee($daftar->list_number)
            ->assertSee('Lanjutkan Picking');
    }

    /* --------------------------------------------------------- Batas akses */

    public function test_sales_tidak_bisa_membuka_dashboard_gudang(): void
    {
        $this->login(Role::SALES);

        $this->get('/wms/dashboard/produksi')->assertForbidden();
        $this->get('/wms/dashboard/operator')->assertForbidden();
    }

    public function test_produksi_tidak_bisa_membuka_area_kerja_operator(): void
    {
        $this->login(Role::PRODUCTION);

        $this->get('/wms/dashboard/operator')->assertForbidden();
    }

    /** Pengawas ikut boleh melihat keduanya — mereka yang menagih hasilnya. */
    public function test_manager_boleh_membuka_kedua_dashboard(): void
    {
        $this->login(Role::MANAGER);

        $this->get('/wms/dashboard/produksi')->assertOk();
        $this->get('/wms/dashboard/operator')->assertOk();
    }

    /**
     * Kartu "tugas saya" milik akun yang membuka, dan Manager tidak pernah
     * memicking — halamannya tetap harus terbuka tanpa meledak.
     */
    public function test_manager_tanpa_tugas_picking_tidak_membuat_halaman_meledak(): void
    {
        $manager = $this->login(Role::MANAGER);

        $m = $this->metrikOperator($manager);

        $this->assertCount(0, $m['tugas_saya']);
        $this->assertCount(0, $m['riwayat']);
    }
}
