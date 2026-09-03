<?php

namespace Tests\Feature\Wms;

use App\Models\Customer;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderCancellation;
use App\Models\SalesOrderDetail;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pembatalan pesanan yang sudah diterima + penggabungan invoice.
 *
 * TEMUAN LAPANGAN YANG MENDASARI BERKAS INI
 * ------------------------------------------
 * Aturan Fase 6 tahap 1 memperlakukan nomor SO sebagai unik SELAMANYA. Di
 * sistem BC ia hanya unik selama pesanannya masih hidup, dan ada dua cara
 * nomor yang sama sah dipakai lagi:
 *
 *   1. Pemegang lamanya DIBATALKAN — customer batal, atau BC tidak setuju.
 *      Di BC nomor itu dipakai ulang untuk pesanan berikutnya yang berhasil.
 *   2. PESANAN TAMBAHAN untuk pelanggan yang sama, digabung ke satu invoice.
 *
 * YANG PALING PENTING DIJAGA DI SINI adalah bahwa kedua pintu itu TIDAK
 * melubangi aturan aslinya. Nomor SO yang sama muncul di pelanggan BERBEDA
 * tetap ditolak keras — itulah kasus "Logistik belum benar-benar memasukkan
 * pesanan ke BC" yang aturan ini ada untuk menangkap.
 */
class OrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $gudang;

    private Location $lokasi;

    private Product $produk;

    private PaymentTerm $term;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->withProduction()->create(['code' => 'WH-01', 'name' => 'Karawang']);
        $this->lokasi = Location::factory()->create(['warehouse_id' => $this->gudang->id, 'code' => 'A-01-01']);
        $this->produk = Product::factory()->create(['sku' => 'APKO-001', 'uom' => 'PAIL', 'is_active' => true]);
        $this->customer = Customer::factory()->create(['is_active' => true, 'name' => 'PT Pertama']);
        $this->term = PaymentTerm::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Cash / Tunai', 'days' => 0, 'is_active' => true, 'sort_order' => 1]
        );
    }

    private function loginAs(string $slug = Role::LOGISTICS): User
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

    private function pesanan(array $atribut = [], ?Customer $customer = null): SalesOrder
    {
        $sales = User::factory()->withRole(Role::SALES)->create(['warehouse_id' => $this->gudang->id]);

        $order = SalesOrder::factory()->submitted()->create(array_merge([
            'user_id' => $sales->id,
            'customer_id' => ($customer ?? $this->customer)->id,
            'warehouse_id' => $this->gudang->id,
            'payment_term_id' => $this->term->id,
            'status' => SalesOrder::STATUS_PENDING,
        ], $atribut));

        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 20,
            'qty_approved' => 0,
        ]);

        return $order;
    }

    private function stok(int $qty): InventoryStock
    {
        return InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->gudang->id,
            'location_id' => $this->lokasi->id,
            'batch_no' => 'BT-'.Str::random(4),
            'production_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYears(2)->toDateString(),
            'qty_available' => $qty,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);
    }

    /** Menerima pesanan dengan nomor SO tertentu. */
    private function terima(SalesOrder $order, string $nomorSo, array $tambahan = [])
    {
        return $this->post(route('wms.approval.accept', $order), array_merge([
            'bc_so_number' => $nomorSo,
            'item' => [[
                'product_id' => $this->produk->id,
                'qty_approved' => 20,
                'qty_ordered' => 20,
            ]],
        ], $tambahan));
    }

    private function batalkan(SalesOrder $order, string $source = SalesOrderCancellation::SOURCE_BC)
    {
        return $this->post(route('wms.approval.cancel', $order), [
            'cancellation_source' => $source,
            'cancellation_reason' => 'BC menolak karena limit kredit customer terlampaui.',
        ]);
    }

    /* -------------------------------------------------------- Pembatalan */

    public function test_pembatalan_mengembalikan_stok_yang_sudah_dialokasikan(): void
    {
        $this->loginAs();
        $stok = $this->stok(50);
        $order = $this->pesanan();

        $this->terima($order, 'SO-001');
        $this->assertSame(30, $stok->fresh()->qty_available);
        $this->assertSame(20, $stok->fresh()->qty_allocated);

        $this->batalkan($order);

        $this->assertSame(50, $stok->fresh()->qty_available);
        $this->assertSame(0, $stok->fresh()->qty_allocated);
    }

    public function test_pembatalan_mencatat_mutasi_dealokasi(): void
    {
        $this->loginAs();
        $this->stok(50);
        $order = $this->pesanan();

        $this->terima($order, 'SO-001');
        $this->batalkan($order);

        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => StockMovement::TYPE_DEALLOCATED,
            'reference_type' => StockMovement::REF_SALES_ORDER,
            'reference_id' => $order->id,
            'qty_change' => 20,
        ]);
    }

    /**
     * Pesanan kembali ke antrean, bukan ditutup — keputusan pemilik produk.
     *
     * Pesanan yang ditolak BC lazimnya diperbaiki lalu diajukan lagi, dan
     * Sales tidak perlu mengetik ulang seluruh item.
     */
    public function test_pesanan_kembali_ke_antrean_tanpa_nomor_so(): void
    {
        $this->loginAs();
        $this->stok(50);
        $order = $this->pesanan();

        $this->terima($order, 'SO-001');
        $this->batalkan($order);

        $segar = $order->fresh();

        $this->assertSame(SalesOrder::STATUS_PENDING, $segar->status);
        $this->assertNull($segar->bc_so_number);
        $this->assertNull($segar->approved_at);
        $this->assertNotNull($segar->cancelled_at);

        // Dan benar-benar muncul lagi di antrean.
        $this->get(route('wms.approval.index'))->assertOk()->assertSee($order->order_number);
    }

    /** Keputusan Logistik ikut dihapus: 0 berarti "belum dinilai" lagi. */
    public function test_qty_disetujui_dikembalikan_ke_nol(): void
    {
        $this->loginAs();
        $this->stok(50);
        $order = $this->pesanan();

        $this->terima($order, 'SO-001');
        $this->batalkan($order);

        $this->assertSame(0, $order->details()->first()->qty_approved);
        $this->assertDatabaseCount('sales_order_allocations', 0);
    }

    /** Riwayat pembatalan tidak pernah hilang, walau kolomnya ditimpa. */
    public function test_riwayat_pembatalan_menyimpan_nomor_so_yang_dilepas(): void
    {
        $this->loginAs();
        $this->stok(50);
        $order = $this->pesanan();

        $this->terima($order, 'SO-001');
        $this->batalkan($order);

        $this->assertDatabaseHas('sales_order_cancellations', [
            'sales_order_id' => $order->id,
            'bc_so_number' => 'SO-001',
            'source' => SalesOrderCancellation::SOURCE_BC,
            'qty_released' => 20,
        ]);
    }

    /**
     * Inti masalah yang dilaporkan: nomor SO yang dibatalkan HARUS bisa
     * dipakai ulang oleh pesanan berikutnya yang berhasil.
     */
    public function test_nomor_so_yang_dibatalkan_bisa_dipakai_pesanan_lain(): void
    {
        $this->loginAs();
        $this->stok(100);

        $pertama = $this->pesanan();
        $this->terima($pertama, 'SO-001');
        $this->batalkan($pertama);

        $kedua = $this->pesanan(customer: Customer::factory()->create(['is_active' => true, 'name' => 'PT Kedua']));
        $this->terima($kedua, 'SO-001');

        $this->assertSame(SalesOrder::STATUS_APPROVED, $kedua->fresh()->status);
        $this->assertSame('SO-001', $kedua->fresh()->bc_so_number);
    }

    public function test_pesanan_yang_dibatalkan_dapat_diterima_lagi_dengan_nomor_baru(): void
    {
        $this->loginAs();
        $this->stok(100);
        $order = $this->pesanan();

        $this->terima($order, 'SO-001');
        $this->batalkan($order);
        $this->terima($order, 'SO-002');

        $segar = $order->fresh();

        $this->assertSame(SalesOrder::STATUS_APPROVED, $segar->status);
        $this->assertSame('SO-002', $segar->bc_so_number);
        // Penanda pembatalan dibersihkan supaya keadaan SEKARANG jujur...
        $this->assertNull($segar->cancelled_at);
        // ...tetapi riwayatnya tetap ada.
        $this->assertDatabaseCount('sales_order_cancellations', 1);
    }

    public function test_pesanan_yang_belum_diterima_tidak_bisa_dibatalkan(): void
    {
        $this->loginAs();
        $order = $this->pesanan();

        $this->batalkan($order)->assertSessionHas('error');

        $this->assertSame(SalesOrder::STATUS_PENDING, $order->fresh()->status);
        $this->assertDatabaseCount('sales_order_cancellations', 0);
    }

    /**
     * Barang yang sudah berangkat bukan urusan pembatalan lagi, melainkan
     * Retur — mencabut catatannya di sini hanya membuat angka stok berbohong.
     */
    public function test_pesanan_yang_sudah_dikirim_tidak_bisa_dibatalkan(): void
    {
        $this->loginAs();
        $this->stok(50);
        $order = $this->pesanan();

        $this->terima($order, 'SO-001');
        $order->fresh()->update(['status' => SalesOrder::STATUS_SHIPPING]);

        $this->batalkan($order)->assertSessionHas('error');

        $this->assertSame(SalesOrder::STATUS_SHIPPING, $order->fresh()->status);
    }

    public function test_alasan_pembatalan_wajib_diisi(): void
    {
        $this->loginAs();
        $this->stok(50);
        $order = $this->pesanan();
        $this->terima($order, 'SO-001');

        $this->post(route('wms.approval.cancel', $order), [
            'cancellation_source' => SalesOrderCancellation::SOURCE_BC,
            'cancellation_reason' => 'batal',
        ])->assertSessionHasErrors('cancellation_reason');

        $this->assertSame(SalesOrder::STATUS_APPROVED, $order->fresh()->status);
    }

    public function test_pembatalan_pesanan_gudang_lain_ditolak(): void
    {
        $this->loginAs();
        $this->stok(50);
        $order = $this->pesanan();
        $this->terima($order, 'SO-001');

        $lain = Warehouse::factory()->create(['code' => 'WH-03', 'name' => 'Surabaya']);
        $penyusup = User::factory()->withRole(Role::LOGISTICS)->create(['warehouse_id' => $lain->id]);
        $this->actingAs($penyusup);

        $this->batalkan($order)->assertForbidden();

        $this->assertSame(SalesOrder::STATUS_APPROVED, $order->fresh()->status);
    }

    /* ---------------------------------------------------- Gabung invoice */

    public function test_pesanan_tambahan_pelanggan_sama_boleh_berbagi_nomor_so(): void
    {
        $this->loginAs();
        $this->stok(100);

        $pertama = $this->pesanan();
        $this->terima($pertama, 'SO-001');

        $tambahan = $this->pesanan();
        $this->terima($tambahan, 'SO-001', [
            'gabung_invoice' => 1,
            'merge_with_order_id' => $pertama->id,
        ]);

        $segar = $tambahan->fresh();

        $this->assertSame(SalesOrder::STATUS_APPROVED, $segar->status);
        $this->assertSame('SO-001', $segar->bc_so_number);
        $this->assertSame($pertama->id, $segar->so_merged_into_id);
    }

    /**
     * Penjagaan aslinya HARUS tetap bekerja.
     *
     * Nomor SO yang sama pada pelanggan berbeda hampir selalu berarti
     * Logistik memakai ulang nomor pesanan orang lain karena belum
     * memasukkan pesanan ini ke BC.
     */
    public function test_nomor_so_sama_pada_pelanggan_berbeda_tetap_ditolak(): void
    {
        $this->loginAs();
        $this->stok(100);

        $pertama = $this->pesanan();
        $this->terima($pertama, 'SO-001');

        $lain = $this->pesanan(customer: Customer::factory()->create(['is_active' => true, 'name' => 'PT Berbeda']));
        $this->terima($lain, 'SO-001')->assertSessionHasErrors('bc_so_number');

        $this->assertSame(SalesOrder::STATUS_PENDING, $lain->fresh()->status);
    }

    /** Penggabungan tidak boleh dipakai untuk menembus pelanggan berbeda. */
    public function test_gabung_invoice_pada_pelanggan_berbeda_tetap_ditolak(): void
    {
        $this->loginAs();
        $this->stok(100);

        $pertama = $this->pesanan();
        $this->terima($pertama, 'SO-001');

        $lain = $this->pesanan(customer: Customer::factory()->create(['is_active' => true, 'name' => 'PT Berbeda']));
        $this->terima($lain, 'SO-001', [
            'gabung_invoice' => 1,
            'merge_with_order_id' => $pertama->id,
        ])->assertSessionHasErrors('bc_so_number');

        $this->assertSame(SalesOrder::STATUS_PENDING, $lain->fresh()->status);
    }

    public function test_nomor_so_sama_tanpa_mencentang_gabung_tetap_ditolak(): void
    {
        $this->loginAs();
        $this->stok(100);

        $pertama = $this->pesanan();
        $this->terima($pertama, 'SO-001');

        $tambahan = $this->pesanan();
        $this->terima($tambahan, 'SO-001')->assertSessionHasErrors('bc_so_number');

        $this->assertSame(SalesOrder::STATUS_PENDING, $tambahan->fresh()->status);
    }

    /** Mencentang gabung pada nomor yang bebas berarti salah paham. */
    public function test_gabung_invoice_pada_nomor_yang_belum_dipakai_ditolak(): void
    {
        $this->loginAs();
        $this->stok(100);
        $order = $this->pesanan();

        $this->terima($order, 'SO-BARU', [
            'gabung_invoice' => 1,
            'merge_with_order_id' => 999999,
        ])->assertSessionHasErrors();

        $this->assertSame(SalesOrder::STATUS_PENDING, $order->fresh()->status);
    }

    /**
     * Membatalkan induk ikut melepas pesanan tambahannya.
     *
     * Kalau tidak, pesanan tambahan berstatus diterima dengan nomor SO yang
     * sudah tidak ada di BC.
     */
    public function test_membatalkan_induk_ikut_membatalkan_pesanan_tambahan(): void
    {
        $this->loginAs();
        $this->stok(100);

        $induk = $this->pesanan();
        $this->terima($induk, 'SO-001');

        $tambahan = $this->pesanan();
        $this->terima($tambahan, 'SO-001', [
            'gabung_invoice' => 1,
            'merge_with_order_id' => $induk->id,
        ]);

        $this->batalkan($induk);

        $this->assertSame(SalesOrder::STATUS_PENDING, $tambahan->fresh()->status);
        $this->assertNull($tambahan->fresh()->bc_so_number);
        $this->assertNull($tambahan->fresh()->so_merged_into_id);
        $this->assertDatabaseCount('sales_order_cancellations', 2);
    }

    /* ------------------------------------------------- Pemeriksaan nomor */

    public function test_pemeriksaan_nomor_so_melaporkan_nomor_bebas(): void
    {
        $this->loginAs();
        $order = $this->pesanan();

        $this->postJson(route('wms.approval.check-so', $order), ['bc_so_number' => 'SO-BEBAS'])
            ->assertOk()
            ->assertJson(['status' => 'bebas']);
    }

    public function test_pemeriksaan_nomor_so_menawarkan_gabung_untuk_pelanggan_sama(): void
    {
        $this->loginAs();
        $this->stok(100);

        $pertama = $this->pesanan();
        $this->terima($pertama, 'SO-001');

        $tambahan = $this->pesanan();

        $this->postJson(route('wms.approval.check-so', $tambahan), ['bc_so_number' => 'SO-001'])
            ->assertOk()
            ->assertJson([
                'status' => 'dapat_digabung',
                'pesanan' => ['id' => $pertama->id, 'nomor' => $pertama->order_number],
            ]);
    }

    public function test_pemeriksaan_nomor_so_menolak_untuk_pelanggan_berbeda(): void
    {
        $this->loginAs();
        $this->stok(100);

        $pertama = $this->pesanan();
        $this->terima($pertama, 'SO-001');

        $lain = $this->pesanan(customer: Customer::factory()->create(['is_active' => true, 'name' => 'PT Berbeda']));

        $this->postJson(route('wms.approval.check-so', $lain), ['bc_so_number' => 'SO-001'])
            ->assertOk()
            ->assertJson(['status' => 'terpakai']);
    }

    /* ------------------------------------------------------------ Riwayat */

    public function test_riwayat_menampilkan_pesanan_yang_dibatalkan(): void
    {
        $this->loginAs();
        $this->stok(50);
        $order = $this->pesanan();

        $this->terima($order, 'SO-001');
        $this->batalkan($order);

        $this->get(route('wms.approval.history').'?hasil=dibatalkan')
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Dibatalkan');
    }

    /** Yang dibatalkan tidak boleh ikut terhitung sebagai "diterima". */
    public function test_saringan_diterima_tidak_memuat_yang_sudah_dibatalkan(): void
    {
        $this->loginAs();
        $this->stok(100);

        $dibatalkan = $this->pesanan();
        $this->terima($dibatalkan, 'SO-001');
        $this->batalkan($dibatalkan);

        $berjalan = $this->pesanan();
        $this->terima($berjalan, 'SO-002');

        $this->get(route('wms.approval.history').'?hasil=diterima')
            ->assertOk()
            ->assertSee($berjalan->order_number)
            ->assertDontSee($dibatalkan->order_number);
    }
}
