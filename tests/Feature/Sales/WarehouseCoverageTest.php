<?php

namespace Tests\Feature\Sales;

use App\Models\Customer;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\OrderCutoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cakupan wilayah gudang di Portal Sales — sisipan multi-gudang Fase 6.
 *
 * KEPUTUSAN PEMILIK PRODUK YANG DIJAGA DI SINI
 * --------------------------------------------
 *   Karawang  : SEMUA wilayah
 *   Pekanbaru : HANYA Sumatera 1 dan 2
 *   Surabaya  : semua KECUALI Sumatera 1 dan 2
 *
 * Pelanggan TIDAK dimiliki gudang — satu wilayah boleh dilayani beberapa
 * gudang. Yang dibatasi adalah cakupan, dan batasnya KERAS: pelanggan di luar
 * cakupan tidak muncul di pencarian DAN pesanannya ditolak saat disimpan.
 *
 * Dua lapis itu diuji terpisah dengan sengaja. Pencarian yang disaring hanya
 * kenyamanan — kolom customer mengirim id, dan id bisa diketik langsung ke
 * permintaan tanpa lewat pencarian sama sekali.
 */
class WarehouseCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const SUMATERA = ['SUMATERA 1', 'SUMATERA 2'];

    private Warehouse $karawang;

    private Warehouse $pekanbaru;

    private Warehouse $surabaya;

    private Product $produk;

    private PaymentTerm $term;

    protected function setUp(): void
    {
        parent::setUp();

        // Pukul 09:00 WIB — sebelum cutoff. Tanpa ini, test yang menekan
        // Submit gagal setiap kali dijalankan sore hari, dan kegagalannya
        // berbunyi "batas waktu pemesanan sudah lewat" seolah-olah cakupan
        // wilayahnya yang bermasalah. Pola yang sama dipakai SalesOrderTest.
        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00', OrderCutoff::timezone()));

        $this->karawang = Warehouse::factory()->withProduction()
            ->create(['code' => 'WH-01', 'name' => 'Karawang']);

        $this->pekanbaru = Warehouse::factory()
            ->covering(Warehouse::MODE_ONLY, self::SUMATERA)
            ->create(['code' => 'WH-02', 'name' => 'Pekanbaru']);

        $this->surabaya = Warehouse::factory()
            ->covering(Warehouse::MODE_EXCEPT, self::SUMATERA)
            ->create(['code' => 'WH-03', 'name' => 'Surabaya']);

        $this->produk = Product::factory()->create(['sku' => 'APKO-001', 'is_active' => true]);
        $this->term = PaymentTerm::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Cash / Tunai', 'days' => 0, 'is_active' => true, 'sort_order' => 1]
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function salesDi(Warehouse $gudang): User
    {
        $user = User::factory()->withRole(Role::SALES)->create(['warehouse_id' => $gudang->id]);
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
        $this->withCredentials();
        $this->actingAs($user);

        return $user;
    }

    private function pelanggan(?string $territory, string $nama = 'PT Contoh Wilayah'): Customer
    {
        return Customer::factory()->create([
            'name' => $nama,
            'territory_code' => $territory,
            'is_active' => true,
        ]);
    }

    /** Isian form pesanan yang sah, tanpa `warehouse_id` sama sekali. */
    private function isian(Customer $customer, string $action = 'submit'): array
    {
        return [
            'action' => $action,
            'customer_id' => $customer->id,
            'payment_term_id' => $this->term->id,
            'order_source' => SalesOrder::SOURCE_MANUAL,
            'items' => [['product_id' => $this->produk->id, 'qty' => 5]],
        ];
    }

    /* ------------------------------------------------- Aturan cakupan */

    public function test_karawang_melayani_semua_wilayah(): void
    {
        foreach (['SUMATERA 1', 'JAWA TIMUR', 'PROJECT', 'KALIMANTAN'] as $wilayah) {
            $this->assertTrue(
                $this->karawang->servesTerritory($wilayah),
                "Karawang seharusnya melayani {$wilayah}."
            );
        }
    }

    public function test_pekanbaru_hanya_melayani_sumatera(): void
    {
        $this->assertTrue($this->pekanbaru->servesTerritory('SUMATERA 1'));
        $this->assertTrue($this->pekanbaru->servesTerritory('SUMATERA 2'));
        $this->assertFalse($this->pekanbaru->servesTerritory('JAWA TIMUR'));
    }

    public function test_surabaya_melayani_semua_kecuali_sumatera(): void
    {
        $this->assertFalse($this->surabaya->servesTerritory('SUMATERA 1'));
        $this->assertTrue($this->surabaya->servesTerritory('JAWA TIMUR'));
    }

    /**
     * Wilayah yang BELUM ADA hari ini otomatis masuk cakupan Karawang dan
     * Surabaya, dan tidak pernah masuk cakupan Pekanbaru.
     *
     * Inilah alasan aturannya disimpan sebagai mode + pengecualian, bukan
     * sebagai salinan 14 kode wilayah yang ada saat ini: daftar salinan akan
     * membuat wilayah baru tidak terlayani gudang mana pun, diam-diam.
     */
    public function test_wilayah_baru_otomatis_masuk_cakupan_yang_benar(): void
    {
        $this->assertTrue($this->karawang->servesTerritory('WILAYAH BARU'));
        $this->assertTrue($this->surabaya->servesTerritory('WILAYAH BARU'));
        $this->assertFalse($this->pekanbaru->servesTerritory('WILAYAH BARU'));
    }

    /** Master data yang belum lengkap tidak boleh menghilang diam-diam. */
    public function test_pelanggan_tanpa_wilayah_tetap_dilayani(): void
    {
        $this->assertTrue($this->pekanbaru->servesTerritory(null));
        $this->assertTrue($this->pekanbaru->servesTerritory(''));
    }

    /* ---------------------------------------------------- Pencarian */

    public function test_pencarian_pelanggan_menyembunyikan_luar_cakupan(): void
    {
        $this->salesDi($this->pekanbaru);

        $this->pelanggan('SUMATERA 1', 'PT Cakupan Sumatera');
        $this->pelanggan('JAWA TIMUR', 'PT Cakupan Jawa');

        $hasil = $this->getJson('/sales/lookup/customers?q=cakupan')->assertOk()->json();
        $nama = array_column($hasil, 'name');

        $this->assertContains('PT Cakupan Sumatera', $nama);
        $this->assertNotContains('PT Cakupan Jawa', $nama);
    }

    public function test_pencarian_pelanggan_karawang_memuat_semua(): void
    {
        $this->salesDi($this->karawang);

        $this->pelanggan('SUMATERA 1', 'PT Cakupan Sumatera');
        $this->pelanggan('JAWA TIMUR', 'PT Cakupan Jawa');

        $nama = array_column($this->getJson('/sales/lookup/customers?q=cakupan')->assertOk()->json(), 'name');

        $this->assertContains('PT Cakupan Sumatera', $nama);
        $this->assertContains('PT Cakupan Jawa', $nama);
    }

    /* ------------------------------------------------- Saat menyimpan */

    /**
     * Lapis kedua: id pelanggan bisa dikirim tanpa lewat pencarian.
     */
    public function test_pesanan_ke_pelanggan_luar_cakupan_ditolak(): void
    {
        $this->salesDi($this->pekanbaru);
        $luar = $this->pelanggan('JAWA TIMUR');

        $this->post('/sales/new-order', $this->isian($luar))
            ->assertSessionHasErrors('customer_id');

        $this->assertDatabaseCount('sales_orders', 0);
    }

    public function test_pesanan_ke_pelanggan_dalam_cakupan_diterima(): void
    {
        $this->salesDi($this->pekanbaru);
        $dalam = $this->pelanggan('SUMATERA 2');

        $this->post('/sales/new-order', $this->isian($dalam))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('sales_orders', 1);
    }

    /* -------------------------------------------- Gudang dikunci akun */

    /**
     * Gudang pesanan diambil dari AKUN, bukan dari formulir.
     *
     * Dikirim `warehouse_id` gudang lain di badan permintaan — dan diabaikan
     * seluruhnya. Sebelum perubahan ini, nilai itulah yang tersimpan.
     */
    public function test_gudang_diambil_dari_akun_bukan_dari_isian(): void
    {
        $sales = $this->salesDi($this->pekanbaru);
        $pelanggan = $this->pelanggan('SUMATERA 1');

        $this->post('/sales/new-order', array_merge($this->isian($pelanggan), [
            'warehouse_id' => $this->surabaya->id,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(
            $this->pekanbaru->id,
            SalesOrder::where('user_id', $sales->id)->value('warehouse_id')
        );
    }

    /** Form tidak lagi menawarkan pilihan gudang sama sekali. */
    public function test_form_pesanan_tidak_lagi_punya_pemilih_gudang(): void
    {
        $this->salesDi($this->pekanbaru);

        $this->get('/sales/new-order')
            ->assertOk()
            ->assertSee('Gudang Pemroses')
            ->assertSee('Pekanbaru')
            ->assertDontSee('id="warehouseSelect"', false);
    }

    /**
     * Akun Sales tanpa gudang tidak bisa membuat pesanan.
     *
     * Dijawab 403 dengan sebab yang jelas, bukan disimpan ke gudang mana pun
     * sebagai tebakan — pesanan yang mendarat di gudang yang salah lebih sulit
     * ditemukan daripada pesanan yang gagal dibuat.
     */
    public function test_sales_tanpa_gudang_tidak_bisa_membuat_pesanan(): void
    {
        $user = User::factory()->withRole(Role::SALES)->create(['warehouse_id' => null]);
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

        $this->post('/sales/new-order', $this->isian($this->pelanggan('JAWA TIMUR')))
            ->assertForbidden();

        $this->assertDatabaseCount('sales_orders', 0);
    }
}
