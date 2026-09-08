<?php

namespace Tests\Feature\Sales;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Reporting\SalesDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dashboard Sales — disusun menurut siapa yang harus bergerak berikutnya.
 *
 * TIGA HAL YANG DIJAGA DI SINI
 * ----------------------------
 * 1. YANG MACET DI TANGAN SALES DIPISAHKAN dari yang sedang ditangani gudang.
 *    Draft, pesanan ditolak, dan bukti kirim menunggu DIRINYA; sisanya tidak.
 *    Menyusun keempatnya sebagai deretan angka setara — seperti versi lama —
 *    membuat yang mendesak tidak terlihat mendesak.
 * 2. UNGGAH BUKTI PALSU SUDAH HILANG. Versi lama punya tombol yang membuka
 *    jendela unggah tiruan lalu menampilkan "Berhasil! Bukti pengiriman
 *    berhasil diunggah" tanpa mengirim apa pun. Itu bukan tampilan yang belum
 *    tersambung — itu kebohongan aktif kepada orang yang mengira pekerjaannya
 *    sudah selesai.
 * 3. PESANAN ORANG LAIN TIDAK PERNAH IKUT TERHITUNG.
 */
class DashboardSalesTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $gudang;

    private Customer $customer;

    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->create(['code' => 'WH-01']);
        $this->customer = Customer::factory()->create(['name' => 'PT Bangun Menara Abadi']);
        $this->produk = Product::factory()->create(['sku' => 'ID1-F00113202225', 'uom' => 'PAIL']);
    }

    /* ------------------------------------------------------------ Perkakas */

    private function login(string $slug = Role::SALES): User
    {
        $user = User::factory()->withRole($slug)->create([
            'warehouse_id' => $this->gudang->id,
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

    private function pesanan(User $pemilik, string $status, array $extra = []): SalesOrder
    {
        return SalesOrder::factory()->create(array_merge([
            'user_id' => $pemilik->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->gudang->id,
            'status' => $status,
            'submitted_at' => $status === SalesOrder::STATUS_DRAFT ? null : now()->subDays(2),
        ], $extra));
    }

    private function metrik(User $user): array
    {
        return app(SalesDashboard::class)->untuk($user);
    }

    /* ------------------------------------- Yang menunggu Sales sendiri */

    public function test_draft_ditolak_dan_bukti_dikumpulkan_sebagai_yang_perlu_tindakan(): void
    {
        $sales = $this->login();

        $this->pesanan($sales, SalesOrder::STATUS_DRAFT);
        $this->pesanan($sales, SalesOrder::STATUS_DRAFT);
        $this->pesanan($sales, SalesOrder::STATUS_REJECTED);
        $this->pesanan($sales, SalesOrder::STATUS_SHIPPING);

        // Yang ini menunggu GUDANG, bukan Sales — tidak boleh ikut.
        $this->pesanan($sales, SalesOrder::STATUS_PENDING);

        $m = $this->metrik($sales);

        $this->assertSame(2, $m['perlu_tindakan']['draft']);
        $this->assertSame(1, $m['perlu_tindakan']['ditolak']);
        $this->assertSame(1, $m['perlu_tindakan']['bukti']);
        $this->assertSame(4, $m['perlu_tindakan']['total']);
    }

    public function test_pesanan_yang_ditangani_gudang_dihitung_terpisah(): void
    {
        $sales = $this->login();

        foreach ([
            SalesOrder::STATUS_APPROVED,
            SalesOrder::STATUS_PICKING,
            SalesOrder::STATUS_READY_TO_SHIP,
            SalesOrder::STATUS_SHIPPING,
            SalesOrder::STATUS_PROOF_UPLOADED,
        ] as $status) {
            $this->pesanan($sales, $status);
        }

        $this->pesanan($sales, SalesOrder::STATUS_DRAFT);

        $m = $this->metrik($sales);

        $this->assertSame(5, $m['berjalan']['jumlah']);
    }

    /** Umur antrean, karena itulah yang ditanyakan pelanggan. */
    public function test_pesanan_pending_terlama_dilaporkan_dalam_hari(): void
    {
        $sales = $this->login();

        $this->pesanan($sales, SalesOrder::STATUS_PENDING, ['submitted_at' => now()->subDays(11)]);
        $this->pesanan($sales, SalesOrder::STATUS_PENDING, ['submitted_at' => now()->subDay()]);

        $m = $this->metrik($sales);

        $this->assertSame(2, $m['menunggu_gudang']['jumlah']);
        $this->assertSame(11, $m['menunggu_gudang']['tertua_hari']);
    }

    public function test_outstanding_hanya_menjumlahkan_pesanan_sendiri(): void
    {
        $sales = $this->login();
        $rekan = User::factory()->withRole(Role::SALES)->create(['warehouse_id' => $this->gudang->id]);

        $milikSaya = $this->pesanan($sales, SalesOrder::STATUS_COMPLETED);
        $milikRekan = $this->pesanan($rekan, SalesOrder::STATUS_COMPLETED);

        SalesOrderDetail::factory()->create([
            'sales_order_id' => $milikSaya->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 10,
            'qty_approved' => 3,
            'qty_shipped' => 3,
            'outstanding_qty' => 7,
        ]);

        SalesOrderDetail::factory()->create([
            'sales_order_id' => $milikRekan->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 100,
            'qty_approved' => 1,
            'qty_shipped' => 1,
            'outstanding_qty' => 99,
        ]);

        $m = $this->metrik($sales);

        $this->assertSame(7, $m['outstanding']['qty']);
        $this->assertSame(1, $m['outstanding']['pesanan']);
    }

    public function test_selesai_bulan_ini_tidak_menghitung_bulan_lalu(): void
    {
        $sales = $this->login();

        $this->pesanan($sales, SalesOrder::STATUS_COMPLETED, ['completed_at' => now()]);
        $this->pesanan($sales, SalesOrder::STATUS_COMPLETED_BILLING, ['completed_at' => now()]);
        $this->pesanan($sales, SalesOrder::STATUS_COMPLETED, [
            'completed_at' => now()->startOfMonth()->subDay(),
        ]);

        $m = $this->metrik($sales);

        $this->assertSame(2, $m['selesai_bulan_ini']['jumlah']);
    }

    /* ------------------------------------------------------ Batas kepemilikan */

    public function test_pesanan_rekan_tidak_pernah_ikut_terhitung(): void
    {
        $sales = $this->login();
        $rekan = User::factory()->withRole(Role::SALES)->create(['warehouse_id' => $this->gudang->id]);

        $this->pesanan($rekan, SalesOrder::STATUS_DRAFT);
        $this->pesanan($rekan, SalesOrder::STATUS_REJECTED);
        $this->pesanan($rekan, SalesOrder::STATUS_PENDING);

        $m = $this->metrik($sales);

        $this->assertSame(0, $m['perlu_tindakan']['total']);
        $this->assertSame(0, $m['menunggu_gudang']['jumlah']);
        $this->assertCount(0, $m['daftar_draft']);
    }

    /* ------------------------------------------------------------ Grafik */

    public function test_grafik_selalu_berisi_enam_bulan_penuh(): void
    {
        $sales = $this->login();

        $this->pesanan($sales, SalesOrder::STATUS_COMPLETED, ['completed_at' => now()]);

        $m = $this->metrik($sales);

        $this->assertCount(SalesDashboard::BULAN_TREN, $m['tren']['label']);
        $this->assertCount(SalesDashboard::BULAN_TREN, $m['tren']['dibuat']);
        $this->assertSame(1, end($m['tren']['selesai']));
    }

    /* ------------------------------------------------------------ Halaman */

    /**
     * Yang paling penting di berkas ini: tombol unggah palsu benar-benar
     * hilang, dan tautannya menuju halaman detail tempat formulir unggah
     * yang SUNGGUHAN menempel.
     */
    public function test_unggah_bukti_palsu_diganti_tautan_ke_halaman_sungguhan(): void
    {
        $sales = $this->login();

        $pesanan = $this->pesanan($sales, SalesOrder::STATUS_SHIPPING, [
            'shipped_at' => now()->subHours(3),
        ]);

        $respons = $this->get('/sales/dashboard')->assertOk();

        $respons->assertSee('Unggah Bukti Kirim');
        $respons->assertSee('PT Bangun Menara Abadi');
        $respons->assertSee('/sales/orders/'.$pesanan->id, false);

        // Jejak jendela unggah tiruan yang dulu ada.
        $respons->assertDontSee('simulateUploadBukti');
        $respons->assertDontSee('Seret Foto ke Sini');
        $respons->assertDontSee('fileInputMock');
    }

    /** Target penjualan tidak pernah ada tabelnya — garisnya angka karangan. */
    public function test_grafik_target_karangan_sudah_dibuang(): void
    {
        $this->login();

        $this->get('/sales/dashboard')
            ->assertOk()
            ->assertDontSee('Target vs Realisasi')
            ->assertDontSee('Target (Qty)')
            ->assertSee('Pesanan Dibuat vs Selesai');
    }

    /**
     * Nol di sini dikatakan sekali dengan tenang, bukan lewat tiga kotak
     * kosong yang membuat hal mendesak tenggelam saat suatu hari muncul.
     */
    public function test_bagian_butuh_tindakan_tidak_digambar_saat_kosong(): void
    {
        $sales = $this->login();

        $this->pesanan($sales, SalesOrder::STATUS_PENDING);

        $this->get('/sales/dashboard')
            ->assertOk()
            ->assertDontSee('Butuh Tindakan Anda')
            ->assertSee('Tidak ada yang menunggu tindakan Anda')
            ->assertSee('Pesanan Anda di Gudang');
    }

    public function test_bagian_butuh_tindakan_muncul_saat_ada_draft(): void
    {
        $sales = $this->login();

        $this->pesanan($sales, SalesOrder::STATUS_DRAFT);

        $this->get('/sales/dashboard')
            ->assertOk()
            ->assertSee('Butuh Tindakan Anda')
            ->assertSee('Draft Belum Dikirim')
            ->assertSee('Belum terlihat oleh Logistik sama sekali.');
    }

    /**
     * Batas jam yang mengubah "ada 3 draft" dari catatan kecil menjadi hal
     * yang mendesak. Dashboard dan halaman Pesanan Saya memakai sumber yang
     * sama, jadi keduanya tidak boleh memberi kabar berbeda.
     */
    public function test_batas_jam_submit_masih_terbuka_ditampilkan_terbuka(): void
    {
        // Waktu dipasang SEBELUM login, bukan di antara dua permintaan:
        // memundurkan jam setelah sesi terbentuk membuat sesinya sendiri
        // terbaca kedaluwarsa oleh TrackUserSession, dan yang diuji berubah
        // diam-diam dari "batas jam" menjadi "sesi mati".
        Carbon::setTestNow(Carbon::parse('2026-09-08 09:00:00', 'Asia/Jakarta'));
        $this->login();

        $this->get('/sales/dashboard')
            ->assertOk()
            ->assertSee('Masih bisa submit sampai');

        Carbon::setTestNow();
    }

    public function test_batas_jam_submit_yang_sudah_lewat_dikatakan_apa_adanya(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-08 16:30:00', 'Asia/Jakarta'));
        $this->login();

        $this->get('/sales/dashboard')
            ->assertOk()
            ->assertSee('masuk antrean besok');

        Carbon::setTestNow();
    }

    public function test_role_gudang_tidak_bisa_membuka_portal_sales(): void
    {
        $this->login(Role::LOGISTICS);

        $this->get('/sales/dashboard')->assertForbidden();
    }
}
