<?php

namespace Tests\Feature\Sales;

use App\Models\Customer;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\SalesOrderRejection;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\OrderCutoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Pesanan yang ditolak DIPERBAIKI, bukan diulang dari nol.
 *
 * KEADAAN SEBELUM INI: penolakan adalah jalan buntu. Pesanan 50 baris yang
 * ditolak karena satu item keliru memaksa Sales mengetik ulang seluruhnya
 * sebagai pesanan baru — dan pesanan barunya tidak punya hubungan apa pun
 * dengan yang ditolak, sehingga Logistik tidak pernah tahu ia sedang menilai
 * pengajuan kedua atas hal yang sama.
 *
 * DUA HAL YANG HARUS BERJALAN BERSAMA, dan itulah yang paling dijaga di sini:
 *   1. Begitu diajukan ulang, penanda penolakan di pesanan DIBERSIHKAN —
 *      pesanan itu sedang menunggu dinilai, bukan sedang ditolak.
 *   2. Fakta bahwa ia pernah ditolak TIDAK ikut hilang, dan tetap terbaca
 *      sampai pesanannya diterima dan selesai.
 *
 * Keduanya mudah saling merusak kalau disimpan di kolom yang sama.
 */
class OrderResubmissionTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $gudang;

    private Customer $customer;

    private PaymentTerm $term;

    private Product $produk;

    private Product $produkLain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->create(['code' => 'WH-01', 'name' => 'Karawang']);
        $this->customer = Customer::factory()->create(['is_active' => true]);
        $this->term = PaymentTerm::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Cash / Tunai', 'days' => 0, 'is_active' => true, 'sort_order' => 1]
        );
        $this->produk = Product::factory()->create(['sku' => 'APKO-001', 'uom' => 'TIN', 'is_active' => true]);
        $this->produkLain = Product::factory()->create(['sku' => 'APKO-002', 'uom' => 'TIN', 'is_active' => true]);

        // Sebelum cutoff, supaya test yang tidak sedang menguji cutoff tidak
        // diam-diam gagal saat dijalankan sore hari.
        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00', OrderCutoff::timezone()));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* -------------------------------------------------------- Perkakas */

    private function loginAs(string $slug = Role::SALES): User
    {
        $user = User::factory()->withRole($slug)->create(['warehouse_id' => $this->gudang->id]);
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

    private function stok(Product $produk, int $qty): InventoryStock
    {
        return InventoryStock::factory()->create([
            'product_id' => $produk->id,
            'warehouse_id' => $this->gudang->id,
            'location_id' => Location::factory()->create(['warehouse_id' => $this->gudang->id])->id,
            'qty_available' => $qty,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
            'expiry_date' => now()->addYears(2)->toDateString(),
        ]);
    }

    /** Pesanan milik $sales yang sudah dikirim dan menunggu Logistik. */
    private function pesananTerkirim(User $sales, int $qty = 50): SalesOrder
    {
        $order = SalesOrder::factory()->submitted()->create([
            'user_id' => $sales->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->gudang->id,
            'payment_term_id' => $this->term->id,
            'status' => SalesOrder::STATUS_PENDING,
        ]);

        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => $qty,
            'qty_approved' => 0,
        ]);

        return $order->refresh();
    }

    private function tolak(SalesOrder $order, string $alasan): User
    {
        $logistik = $this->loginAs(Role::LOGISTICS);

        $this->post(route('wms.approval.reject', $order), [
            'rejection_reason' => $alasan,
        ])->assertSessionHasNoErrors();

        return $logistik;
    }

    /** Sales memperbaiki isinya lalu mengajukan ulang lewat formulirnya. */
    private function ajukanUlang(SalesOrder $order, array $items, string $action = 'submit')
    {
        return $this->put('/sales/orders/'.$order->id, [
            'action' => $action,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->gudang->id,
            'payment_term_id' => $this->term->id,
            'order_source' => SalesOrder::SOURCE_MANUAL,
            'items' => $items,
        ]);
    }

    /* ================================================= Penolakan tercatat */

    public function test_penolakan_masuk_riwayat_dengan_nomor_pengajuan(): void
    {
        $sales = $this->loginAs();
        $order = $this->pesananTerkirim($sales);

        $logistik = $this->tolak($order, 'Ada satu item yang SKU-nya tidak sesuai permintaan customer.');

        $riwayat = SalesOrderRejection::where('sales_order_id', $order->id)->get();

        $this->assertCount(1, $riwayat);
        $this->assertSame(1, $riwayat[0]->attempt_no);
        $this->assertSame($logistik->id, $riwayat[0]->rejected_by);
        $this->assertSame(
            'Ada satu item yang SKU-nya tidak sesuai permintaan customer.',
            $riwayat[0]->reason
        );
    }

    public function test_riwayat_penolakan_tidak_boleh_diubah_maupun_dihapus(): void
    {
        $sales = $this->loginAs();
        $order = $this->pesananTerkirim($sales);
        $this->tolak($order, 'Item tidak sesuai permintaan customer.');

        $baris = SalesOrderRejection::where('sales_order_id', $order->id)->firstOrFail();

        $this->expectException(RuntimeException::class);
        $baris->update(['reason' => 'diubah']);
    }

    /* ================================================= Perbaikan oleh Sales */

    public function test_sales_bisa_membuka_kembali_pesanan_yang_ditolak(): void
    {
        $sales = $this->loginAs();
        $order = $this->pesananTerkirim($sales);
        $this->tolak($order, 'Item tidak sesuai permintaan customer, mohon diperiksa lagi.');

        $this->actingAs($sales);

        $this->get('/sales/orders/'.$order->id.'/edit')
            ->assertOk()
            // Alasannya dibawa ke dalam formulir: Sales yang harus
            // mengingat-ingat apa yang salah akan memperbaiki yang keliru.
            ->assertSee('Item tidak sesuai permintaan customer, mohon diperiksa lagi.')
            ->assertSee('Ajukan Ulang');
    }

    /** Yang sudah DITERIMA tetap tidak boleh diutak-atik Sales. */
    public function test_pesanan_yang_sudah_diterima_tetap_tidak_bisa_diubah(): void
    {
        $sales = $this->loginAs();
        $order = $this->pesananTerkirim($sales);

        $order->forceFill(['status' => SalesOrder::STATUS_APPROVED, 'approved_at' => now()])->save();

        $this->actingAs($sales);
        $this->get('/sales/orders/'.$order->id.'/edit')->assertForbidden();
    }

    /**
     * Pesanan yang pernah ditolak TIDAK boleh dihapus.
     *
     * Menghapusnya berarti menghapus jejak penolakannya juga — dan itu persis
     * yang perbaikan ini ada untuk mencegah.
     */
    public function test_pesanan_yang_ditolak_tidak_bisa_dihapus(): void
    {
        $sales = $this->loginAs();
        $order = $this->pesananTerkirim($sales);
        $this->tolak($order, 'Item tidak sesuai permintaan customer.');

        $this->actingAs($sales);
        $this->delete('/sales/orders/'.$order->id)->assertForbidden();

        $this->assertNotNull($order->fresh());
    }

    public function test_pengajuan_ulang_mengubah_isi_tanpa_membuat_pesanan_baru(): void
    {
        $sales = $this->loginAs();
        $order = $this->pesananTerkirim($sales, 50);
        $this->tolak($order, 'Satu item keliru, tolong diganti SKU yang benar.');

        $this->actingAs($sales);

        $this->ajukanUlang($order, [
            ['product_id' => $this->produk->id, 'qty' => 50],
            ['product_id' => $this->produkLain->id, 'qty' => 5],
        ])->assertSessionHasNoErrors();

        $segar = $order->fresh();

        $this->assertSame(1, SalesOrder::count(), 'Tidak ada pesanan baru yang dibuat.');
        $this->assertSame($order->order_number, $segar->order_number, 'Nomor pesanannya tetap sama.');
        $this->assertSame(SalesOrder::STATUS_PENDING, $segar->status);
        $this->assertCount(2, $segar->details, 'Item boleh ditambah saat diperbaiki.');
    }

    /**
     * Inti keluhannya: keadaan sekarang jujur, riwayatnya tetap ada.
     */
    public function test_pengajuan_ulang_membersihkan_penanda_tetapi_bukan_riwayatnya(): void
    {
        $sales = $this->loginAs();
        $order = $this->pesananTerkirim($sales);
        $this->tolak($order, 'Satu item keliru, tolong diganti SKU yang benar.');

        $this->assertNotNull($order->fresh()->rejected_at);

        $this->actingAs($sales);
        $this->ajukanUlang($order, [['product_id' => $this->produk->id, 'qty' => 50]])
            ->assertSessionHasNoErrors();

        $segar = $order->fresh();

        $this->assertNull($segar->rejected_at, 'Pesanan ini sedang menunggu, bukan sedang ditolak.');
        $this->assertNull($segar->rejection_reason);
        $this->assertSame(1, $segar->rejections()->count(), 'Riwayatnya tetap utuh.');
    }

    /** Disimpan tanpa diajukan: pesanannya MASIH ditolak, dan itu jujur. */
    public function test_menyimpan_perbaikan_tanpa_mengajukan_tidak_mengubah_status(): void
    {
        $sales = $this->loginAs();
        $order = $this->pesananTerkirim($sales);
        $this->tolak($order, 'Satu item keliru, tolong diganti SKU yang benar.');

        $this->actingAs($sales);
        $this->ajukanUlang($order, [['product_id' => $this->produk->id, 'qty' => 20]], 'draft')
            ->assertSessionHasNoErrors();

        $segar = $order->fresh();

        $this->assertSame(SalesOrder::STATUS_REJECTED, $segar->status);
        $this->assertNotNull($segar->rejected_at);
        $this->assertSame(20, $segar->details()->firstOrFail()->qty_ordered);
    }

    /* =============================================== Catatan sampai akhir */

    public function test_penolakan_berulang_dihitung_bertingkat(): void
    {
        $sales = $this->loginAs();
        $order = $this->pesananTerkirim($sales);

        foreach (['Alasan penolakan pertama yang cukup panjang.', 'Alasan penolakan kedua yang cukup panjang.'] as $alasan) {
            $this->tolak($order->fresh(), $alasan);

            $this->actingAs($sales);
            $this->ajukanUlang($order->fresh(), [['product_id' => $this->produk->id, 'qty' => 50]])
                ->assertSessionHasNoErrors();
        }

        $riwayat = SalesOrderRejection::where('sales_order_id', $order->id)
            ->orderBy('attempt_no')->get();

        $this->assertCount(2, $riwayat);
        $this->assertSame([1, 2], $riwayat->pluck('attempt_no')->all());
    }

    /**
     * Permintaan pemilik produk: catatannya melekat SAMPAI AKHIR, termasuk
     * sesudah pesanannya akhirnya diterima.
     */
    public function test_catatan_pernah_ditolak_bertahan_setelah_pesanan_diterima(): void
    {
        $sales = $this->loginAs();
        $order = $this->pesananTerkirim($sales);
        $this->tolak($order, 'Satu item keliru, tolong diganti SKU yang benar.');

        $this->actingAs($sales);
        $this->ajukanUlang($order, [['product_id' => $this->produk->id, 'qty' => 50]])
            ->assertSessionHasNoErrors();

        // Logistik menerima pengajuan keduanya.
        $this->stok($this->produk, 100);
        $this->loginAs(Role::LOGISTICS);

        $this->post(route('wms.approval.accept', $order->fresh()), [
            'bc_so_number' => 'SO-ULANG-1',
            'item' => [['product_id' => $this->produk->id, 'qty_approved' => 50, 'qty_ordered' => 50]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(SalesOrder::STATUS_APPROVED, $order->fresh()->status);
        $this->assertSame(1, $order->fresh()->rejections()->count());

        $this->get('/wms/outbound/approval/history')
            ->assertOk()
            ->assertSee('Pernah ditolak')
            ->assertSee('Satu item keliru, tolong diganti SKU yang benar.');

        // Dan tetap terbaca oleh Sales di pesanannya sendiri.
        $this->actingAs($sales);
        $this->get('/sales/orders/'.$order->id)
            ->assertOk()
            ->assertSee('pernah ditolak')
            ->assertSee('Satu item keliru, tolong diganti SKU yang benar.');
    }

    /** Logistik tahu ia sedang menilai pengajuan kedua, sejak di antrean. */
    public function test_antrean_menandai_pengajuan_ulang(): void
    {
        $sales = $this->loginAs();
        $order = $this->pesananTerkirim($sales);
        $this->tolak($order, 'Satu item keliru, tolong diganti SKU yang benar.');

        $this->actingAs($sales);
        $this->ajukanUlang($order, [['product_id' => $this->produk->id, 'qty' => 50]])
            ->assertSessionHasNoErrors();

        $this->loginAs(Role::LOGISTICS);

        $this->get('/wms/outbound/approval')
            ->assertOk()
            ->assertSee('Pengajuan ke-2');

        // Dan alasannya ikut terbawa ke layar penilaiannya.
        $this->get('/wms/outbound/approval/'.$order->id)
            ->assertOk()
            ->assertSee('Satu item keliru, tolong diganti SKU yang benar.');
    }

    /** Saringan "ditolak" ikut memuat yang sudah diperbaiki dan diajukan lagi. */
    public function test_saringan_ditolak_memuat_pesanan_yang_sudah_diajukan_ulang(): void
    {
        $sales = $this->loginAs();

        $pernahDitolak = $this->pesananTerkirim($sales);
        $this->tolak($pernahDitolak, 'Satu item keliru, tolong diganti SKU yang benar.');

        $this->actingAs($sales);
        $this->ajukanUlang($pernahDitolak, [['product_id' => $this->produk->id, 'qty' => 50]])
            ->assertSessionHasNoErrors();

        $mulus = $this->pesananTerkirim($sales);
        $this->stok($this->produk, 200);
        $this->loginAs(Role::LOGISTICS);
        $this->post(route('wms.approval.accept', $mulus), [
            'bc_so_number' => 'SO-MULUS-1',
            'item' => [['product_id' => $this->produk->id, 'qty_approved' => 50, 'qty_ordered' => 50]],
        ])->assertSessionHasNoErrors();

        $this->get('/wms/outbound/approval/history?hasil=ditolak')
            ->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->pluck('id')->all() === [$pernahDitolak->id]);
    }
}
