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
use App\Models\SalesOrderOutstanding;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Outbound\OutstandingRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Riwayat outstanding & riwayat pembatalan yang tidak hilang.
 *
 * DUA KELUHAN LAPANGAN, SATU AKAR YANG SAMA
 * -----------------------------------------
 * Keduanya berasal dari kebiasaan menyimpan riwayat di kolom yang menyimpan
 * keadaan sekarang, lalu menimpanya begitu keadaannya berubah:
 *
 *   1. `sales_order_details.outstanding_qty` ditimpa setiap Surat Jalan
 *      berangkat, jadi "PO itu dulu kurang berapa" tidak bisa dijawab lagi.
 *   2. Kolom pembatalan di `sales_orders` dibersihkan saat pesanan diterima
 *      ulang, jadi PO yang sudah tiga kali batal terlihat sama bersihnya
 *      dengan yang mulus sejak awal.
 *
 * Yang paling dijaga berkas ini: baris riwayat TETAP ADA sesudah keadaannya
 * berubah, dan angka "sekarang" tetap ikut kenyataan — dua hal yang mudah
 * saling merusak kalau dijadikan satu kolom.
 */
class OutstandingHistoryTest extends TestCase
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

        $this->gudang = Warehouse::factory()->create(['code' => 'KRW', 'name' => 'Karawang']);
        $this->lokasi = Location::factory()->create(['warehouse_id' => $this->gudang->id, 'code' => 'A-01-01']);
        $this->produk = Product::factory()->create(['sku' => 'APKO-001', 'uom' => 'PAIL', 'is_active' => true]);
        $this->customer = Customer::factory()->create(['is_active' => true, 'name' => 'PT Pertama']);
        $this->term = PaymentTerm::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Cash / Tunai', 'days' => 0, 'is_active' => true, 'sort_order' => 1]
        );
    }

    private function loginAs(string $slug = Role::LOGISTICS, ?Warehouse $gudang = null): User
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
        $this->withCredentials();
        $this->actingAs($user);

        return $user;
    }

    private function pesanan(int $qty = 10, ?Warehouse $gudang = null): SalesOrder
    {
        $gudang ??= $this->gudang;

        $sales = User::factory()->withRole(Role::SALES)->create(['warehouse_id' => $gudang->id]);

        $order = SalesOrder::factory()->submitted()->create([
            'user_id' => $sales->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $gudang->id,
            'payment_term_id' => $this->term->id,
            'status' => SalesOrder::STATUS_PENDING,
        ]);

        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => $qty,
            'qty_approved' => 0,
        ]);

        return $order;
    }

    private function stok(int $qty, ?Warehouse $gudang = null, ?Location $lokasi = null): InventoryStock
    {
        return InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'warehouse_id' => ($gudang ?? $this->gudang)->id,
            'location_id' => ($lokasi ?? $this->lokasi)->id,
            'batch_no' => 'BT-'.Str::random(4),
            'production_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYears(2)->toDateString(),
            'qty_available' => $qty,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);
    }

    /** Menerima pesanan dengan qty yang disetujui tertentu. */
    private function terima(SalesOrder $order, int $disetujui, int $dipesan, string $nomorSo)
    {
        return $this->post(route('wms.approval.accept', $order), [
            'bc_so_number' => $nomorSo,
            'item' => [[
                'product_id' => $this->produk->id,
                'qty_approved' => $disetujui,
                'qty_ordered' => $dipesan,
            ]],
        ]);
    }

    private function batalkan(SalesOrder $order, string $alasan = 'BC menolak karena limit kredit customer terlampaui.')
    {
        return $this->post(route('wms.approval.cancel', $order), [
            'cancellation_source' => SalesOrderCancellation::SOURCE_BC,
            'cancellation_reason' => $alasan,
        ]);
    }

    /* ============================================================ AKSES */

    public function test_role_operasional_tidak_boleh_membuka_riwayat_outstanding(): void
    {
        foreach ([Role::PRODUCTION, Role::WAREHOUSE_OPERATOR] as $slug) {
            $this->loginAs($slug);

            $this->get('/wms/outbound/outstanding')->assertForbidden();
        }
    }

    public function test_logistik_boleh_membuka_riwayat_outstanding(): void
    {
        $this->loginAs();

        $this->get('/wms/outbound/outstanding')->assertOk();
    }

    /* ================================================= PENCATATAN OUTSTANDING */

    /** Contoh persis dari pemilik produk: pesan 10, hanya ada stok 5. */
    public function test_disetujui_sebagian_masuk_riwayat_outstanding(): void
    {
        $this->loginAs();
        $this->stok(5);

        $order = $this->pesanan(10);

        $this->terima($order, 5, 10, 'SO-0001')->assertSessionHasNoErrors();

        $riwayat = SalesOrderOutstanding::where('sales_order_id', $order->id)->get();

        $this->assertCount(1, $riwayat);
        $this->assertSame(SalesOrderOutstanding::CAUSE_APPROVAL, $riwayat[0]->cause);
        $this->assertSame(10, $riwayat[0]->qty_ordered);
        $this->assertSame(5, $riwayat[0]->qty_fulfilled);
        $this->assertSame(5, $riwayat[0]->qty_outstanding);
    }

    /** Pesanan yang disetujui penuh tidak meninggalkan baris apa pun. */
    public function test_disetujui_penuh_tidak_mencatat_outstanding(): void
    {
        $this->loginAs();
        $this->stok(10);

        $order = $this->pesanan(10);

        $this->terima($order, 10, 10, 'SO-0002')->assertSessionHasNoErrors();

        $this->assertSame(0, SalesOrderOutstanding::where('sales_order_id', $order->id)->count());
    }

    /**
     * Menyetujui melebihi stok BUKAN outstanding.
     *
     * Selisih "menunggu stok" adalah janji yang belum punya cadangan, bukan
     * kewajiban yang tidak dipenuhi — barangnya sudah di gudang, hanya belum
     * di-putaway. Mencatatnya sebagai kekurangan berarti Sales menagih
     * sesuatu yang sebenarnya sudah dijanjikan penuh.
     */
    public function test_menyetujui_melebihi_stok_tidak_dihitung_outstanding(): void
    {
        $this->loginAs();
        $this->stok(3);

        $order = $this->pesanan(10);

        $this->terima($order, 10, 10, 'SO-0003')->assertSessionHasNoErrors();

        $this->assertSame(0, SalesOrderOutstanding::where('sales_order_id', $order->id)->count());
        $this->assertSame(10, $order->details()->firstOrFail()->qty_approved);
    }

    /**
     * Kekurangan yang tidak berubah tidak dicatat dua kali.
     *
     * Inilah yang membedakan riwayat yang bisa dibaca dari daftar yang
     * membanjir: satu kekurangan yang sama harus tampil satu kali, bukan
     * sekali lagi setiap kali ada yang menghitung ulang.
     */
    public function test_kekurangan_yang_sama_tidak_dicatat_berulang(): void
    {
        $user = $this->loginAs();
        $this->stok(5);

        $order = $this->pesanan(10);
        $this->terima($order, 5, 10, 'SO-0004')->assertSessionHasNoErrors();

        $detail = $order->details()->firstOrFail();

        $ditambahkan = app(OutstandingRecorder::class)->record(
            $order,
            $detail,
            5,
            SalesOrderOutstanding::CAUSE_SHIPMENT,
            $user->id,
        );

        $this->assertFalse($ditambahkan);
        $this->assertSame(1, SalesOrderOutstanding::where('sales_order_id', $order->id)->count());
    }

    /** Kekurangan yang MEMBESAR memang peristiwa baru dan dicatat sendiri. */
    public function test_kekurangan_yang_berubah_dicatat_sebagai_baris_baru(): void
    {
        $user = $this->loginAs();
        $this->stok(5);

        $order = $this->pesanan(10);
        $this->terima($order, 5, 10, 'SO-0005')->assertSessionHasNoErrors();

        $detail = $order->details()->firstOrFail();

        $ditambahkan = app(OutstandingRecorder::class)->record(
            $order,
            $detail,
            8,
            SalesOrderOutstanding::CAUSE_SHIPMENT,
            $user->id,
        );

        $this->assertTrue($ditambahkan);
        $this->assertSame(2, SalesOrderOutstanding::where('sales_order_id', $order->id)->count());
    }

    /* ============================================== RIWAYAT TIDAK MENGHILANG */

    /**
     * Inti keluhannya: kekurangan yang sudah tertutup tetap punya barisnya.
     *
     * Kolom hidupnya boleh — dan memang harus — kembali nol; yang tidak boleh
     * adalah baris riwayatnya ikut hilang bersamanya.
     */
    public function test_baris_riwayat_bertahan_setelah_kekurangannya_tertutup(): void
    {
        $this->loginAs();
        $this->stok(5);

        $order = $this->pesanan(10);
        $this->terima($order, 5, 10, 'SO-0006')->assertSessionHasNoErrors();

        // Kekurangannya ditutup, meniru stok susulan yang akhirnya masuk.
        $order->details()->firstOrFail()->forceFill(['outstanding_qty' => 0])->save();

        $baris = SalesOrderOutstanding::where('sales_order_id', $order->id)->firstOrFail();

        $this->assertSame(5, $baris->qty_outstanding, 'Cuplikan masa lalu tidak boleh ikut berubah.');
        $this->assertSame(0, $baris->fresh()->sisa_sekarang);
        $this->assertTrue($baris->fresh()->sudah_tertutup);
    }

    public function test_halaman_memisahkan_yang_masih_kurang_dari_yang_sudah_terpenuhi(): void
    {
        $this->loginAs();
        $this->stok(5);

        $kurang = $this->pesanan(10);
        $this->terima($kurang, 5, 10, 'SO-0007')->assertSessionHasNoErrors();

        $this->stok(5);
        $tertutup = $this->pesanan(10);
        $this->terima($tertutup, 5, 10, 'SO-0008')->assertSessionHasNoErrors();
        $tertutup->details()->firstOrFail()->forceFill(['outstanding_qty' => 0])->save();

        $this->get('/wms/outbound/outstanding?keadaan=berjalan')
            ->assertOk()
            ->assertSee($kurang->order_number)
            ->assertDontSee($tertutup->order_number);

        $this->get('/wms/outbound/outstanding?keadaan=selesai')
            ->assertOk()
            ->assertSee($tertutup->order_number)
            ->assertDontSee($kurang->order_number);
    }

    /** Angka ringkasnya menghitung yang MASIH kurang, bukan seluruh riwayat. */
    public function test_kartu_ringkas_hanya_menghitung_yang_masih_kurang(): void
    {
        $this->loginAs();
        $this->stok(5);

        $order = $this->pesanan(10);
        $this->terima($order, 5, 10, 'SO-0009')->assertSessionHasNoErrors();

        $this->get('/wms/outbound/outstanding')
            ->assertOk()
            ->assertViewHas('stats', fn (array $s) => $s['baris_berjalan'] === 1
                && $s['qty_berjalan'] === 5
                && $s['pesanan_berjalan'] === 1);

        $order->details()->firstOrFail()->forceFill(['outstanding_qty' => 0])->save();

        $this->get('/wms/outbound/outstanding')
            ->assertOk()
            ->assertViewHas('stats', fn (array $s) => $s['baris_berjalan'] === 0
                && $s['qty_berjalan'] === 0);
    }

    public function test_riwayat_outstanding_tidak_boleh_diubah_maupun_dihapus(): void
    {
        $this->loginAs();
        $this->stok(5);

        $order = $this->pesanan(10);
        $this->terima($order, 5, 10, 'SO-0010')->assertSessionHasNoErrors();

        $baris = SalesOrderOutstanding::where('sales_order_id', $order->id)->firstOrFail();

        $this->expectException(RuntimeException::class);
        $baris->update(['qty_outstanding' => 1]);
    }

    public function test_riwayat_outstanding_gudang_lain_tidak_terlihat(): void
    {
        $lain = Warehouse::factory()->create(['code' => 'PKU', 'name' => 'Pekanbaru']);
        $lokasiLain = Location::factory()->create(['warehouse_id' => $lain->id, 'code' => 'B-01-01']);

        // Diterima oleh Logistik gudang lain lebih dulu.
        $this->loginAs(Role::LOGISTICS, $lain);
        $this->stok(5, $lain, $lokasiLain);
        $milikLain = $this->pesanan(10, $lain);
        $this->terima($milikLain, 5, 10, 'SO-PKU-1')->assertSessionHasNoErrors();

        // Lalu Logistik Karawang membuka halamannya.
        $this->loginAs(Role::LOGISTICS);
        $this->stok(5);
        $milikSendiri = $this->pesanan(10);
        $this->terima($milikSendiri, 5, 10, 'SO-KRW-1')->assertSessionHasNoErrors();

        $this->get('/wms/outbound/outstanding')
            ->assertOk()
            ->assertSee($milikSendiri->order_number)
            ->assertDontSee($milikLain->order_number);
    }

    /* ============================================== RIWAYAT PEMBATALAN */

    /**
     * Keluhan pemilik produk yang paling langsung: PO05 dibatalkan, lalu
     * diterima lagi, dan jejak pembatalannya lenyap dari layar.
     */
    public function test_riwayat_pembatalan_tetap_terlihat_setelah_pesanan_diterima_lagi(): void
    {
        $this->loginAs();
        $this->stok(50);

        $order = $this->pesanan(10);
        $this->terima($order, 10, 10, 'SO-BATAL-1')->assertSessionHasNoErrors();
        $this->batalkan($order, 'BC menolak karena limit kredit customer terlampaui.');

        // Diterima ulang dengan nomor SO baru — kolom pembatalan di pesanannya
        // dibersihkan, dan dulu di sinilah riwayatnya hilang dari layar.
        $this->terima($order->fresh(), 10, 10, 'SO-BATAL-2')->assertSessionHasNoErrors();

        $this->assertNull($order->fresh()->cancelled_at, 'Keadaan sekarang harus jujur: pesanan ini tidak sedang batal.');

        // Keduanya hanya bisa berasal dari tabel riwayat: pesan flash boleh
        // menyebut nomor pesanannya, tetapi tidak pernah menyebut hitungan
        // pembatalan maupun alasan yang dipakai saat itu.
        $this->get('/wms/outbound/approval/history')
            ->assertOk()
            ->assertSee('Pernah dibatalkan')
            ->assertSee('BC menolak karena limit kredit customer terlampaui.')
            ->assertViewHas('orders', fn ($orders) => $orders->contains('id', $order->id));
    }

    /** Berapa kali sebuah PO pernah dibatalkan harus terbaca dari layarnya. */
    public function test_pembatalan_berulang_dihitung_dan_seluruhnya_tercatat(): void
    {
        $this->loginAs();
        $this->stok(50);

        $order = $this->pesanan(10);

        foreach (['SO-A', 'SO-B', 'SO-C'] as $i => $nomor) {
            $this->terima($order->fresh(), 10, 10, $nomor)->assertSessionHasNoErrors();
            $this->batalkan($order->fresh(), 'Alasan pembatalan ke-'.($i + 1).' yang cukup panjang.');
        }

        $this->assertSame(3, SalesOrderCancellation::where('sales_order_id', $order->id)->count());

        $halaman = $this->get('/wms/outbound/approval/history')->assertOk();

        $halaman->assertSee('Pernah dibatalkan 3');

        // Setiap pembatalan menyimpan nomor SO yang saat itu dilepas — itulah
        // yang ditelusuri ketika angka di BC dan WMS berbeda.
        foreach (['SO-A', 'SO-B', 'SO-C'] as $nomor) {
            $halaman->assertSee($nomor);
        }
    }

    /** Siapa yang membatalkan tetap tercatat, sekalipun kolomnya sudah ditimpa. */
    public function test_pelaku_pembatalan_tersimpan_di_riwayat(): void
    {
        $pembatal = $this->loginAs();
        $this->stok(50);

        $order = $this->pesanan(10);
        $this->terima($order, 10, 10, 'SO-SIAPA-1')->assertSessionHasNoErrors();
        $this->batalkan($order);

        $batal = SalesOrderCancellation::where('sales_order_id', $order->id)->firstOrFail();

        $this->assertSame($pembatal->id, $batal->cancelled_by);
        $this->assertSame('SO-SIAPA-1', $batal->bc_so_number);

        // Diterima lagi oleh orang lain: kolom di pesanan berpindah tangan,
        // riwayatnya tidak.
        $penerima = $this->loginAs();
        $this->terima($order->fresh(), 10, 10, 'SO-SIAPA-2')->assertSessionHasNoErrors();

        $this->assertSame($penerima->id, $order->fresh()->approved_by);
        $this->assertSame($pembatal->id, $batal->fresh()->cancelled_by);
    }

    /** Saringan "dibatalkan" ikut memuat yang sudah diterima lagi. */
    public function test_saringan_dibatalkan_memuat_pesanan_yang_sudah_diterima_lagi(): void
    {
        $this->loginAs();
        $this->stok(50);

        $pernahBatal = $this->pesanan(10);
        $this->terima($pernahBatal, 10, 10, 'SO-F-1')->assertSessionHasNoErrors();
        $this->batalkan($pernahBatal);
        $this->terima($pernahBatal->fresh(), 10, 10, 'SO-F-2')->assertSessionHasNoErrors();

        $mulus = $this->pesanan(10);
        $this->terima($mulus, 10, 10, 'SO-F-3')->assertSessionHasNoErrors();

        $this->assertSame(1, SalesOrderCancellation::where('sales_order_id', $pernahBatal->id)->count());
        $this->assertSame(0, SalesOrderCancellation::where('sales_order_id', $mulus->id)->count());

        $this->get('/wms/outbound/approval/history?hasil=dibatalkan')
            ->assertOk()
            // Diperiksa pada daftar yang masuk ke view, BUKAN pada teks
            // halamannya. Penerimaan tepat sebelum ini meninggalkan pesan
            // flash "Pesanan PO... diterima" yang ikut tergambar di halaman
            // berikutnya, sehingga assertDontSee akan menuduh saringan ini
            // bocor padahal barisnya tidak pernah ada di daftar.
            ->assertViewHas('orders', fn ($orders) => $orders->pluck('id')->all() === [$pernahBatal->id]);
    }
}
