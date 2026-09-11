<?php

namespace Tests\Feature\Wms;

use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\PaymentTerm;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\SalesOrderOutstanding;
use App\Models\SalesOrderReshipment;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Outbound\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Pengiriman ulang kekurangan — permintaan pemilik produk.
 *
 * LIMA HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * --------------------------------------------------
 * 1. NOMOR SO TETAP SAMA. Tidak ada pesanan baru yang dibuat; kalau dibuat,
 *    satu kewajiban terbaca sebagai dua pesanan dan penjualan terhitung dua
 *    kali.
 * 2. PUTARAN LAMA DILEPAS. picking_list_id harus dikosongkan, kalau tidak
 *    pesanan ini tidak akan pernah bisa masuk daftar picking berikutnya —
 *    dan tidak ada galat apa pun yang muncul, ia cuma hilang dari antrean.
 * 3. HANYA DARI PUTARAN YANG SUDAH SELESAI. Membuka putaran baru di atas
 *    pesanan yang masih berjalan akan mencadangkan stok dua kali untuk
 *    kekurangan yang sama.
 * 4. SEBANYAK YANG ADA. Stok yang cuma cukup sebagian tetap dicadangkan,
 *    sisanya TETAP outstanding — bukan ditolak seluruhnya.
 * 5. qty_shipped MENUMPUK antar putaran. Kalau ditimpa, Surat Jalan kedua
 *    menghapus catatan keberangkatan pertama dan pelanggan ditagih barang
 *    yang sudah ia terima.
 */
class OutstandingReshipmentTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $gudang;

    private Location $lokasi;

    private Product $produk;

    private Customer $customer;

    private PaymentTerm $term;

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
            'user_id' => $user->id, 'session_id' => $token, 'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit', 'last_activity_at' => now(), 'created_at' => now(),
        ]);

        $this->withUnencryptedCookies(['device_token' => $token]);
        $this->withCredentials();
        $this->actingAs($user);

        return $user;
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

    /**
     * Pesanan yang PUTARAN PERTAMANYA sudah berangkat sebagian.
     *
     * Dibuat lewat state langsung, bukan menempuh seluruh alur picking &
     * Surat Jalan: yang diuji di sini adalah PEMBUKAAN PUTARAN BERIKUTNYA,
     * dan menempuh alur penuh membuat kegagalannya menunjuk ke modul lain.
     */
    private function pesananBerangkatSebagian(int $dipesan = 10, int $terkirim = 6): SalesOrder
    {
        $sales = User::factory()->withRole(Role::SALES)->create(['warehouse_id' => $this->gudang->id]);

        $order = SalesOrder::factory()->submitted()->create([
            'user_id' => $sales->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->gudang->id,
            'payment_term_id' => $this->term->id,
            'status' => SalesOrder::STATUS_COMPLETED,
            'shipped_at' => now()->subDays(3),
        ]);

        $detail = SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => $dipesan,
            'qty_approved' => $terkirim,
            'qty_shipped' => $terkirim,
            'outstanding_qty' => $dipesan - $terkirim,
        ]);

        // Baris riwayat outstanding-nya, karena LAYAR outstanding dibangun
        // dari riwayat — bukan dari baris pesanan. Tanpa ini pesanannya
        // memang punya kekurangan, tetapi tidak pernah muncul di daftar.
        if ($dipesan > $terkirim) {
            SalesOrderOutstanding::create([
                'sales_order_id' => $order->id,
                'sales_order_detail_id' => $detail->id,
                'product_id' => $this->produk->id,
                'warehouse_id' => $this->gudang->id,
                'qty_ordered' => $dipesan,
                'qty_fulfilled' => $terkirim,
                'qty_outstanding' => $dipesan - $terkirim,
                'cause' => SalesOrderOutstanding::CAUSE_SHIPMENT,
                'note' => 'Surat Jalan berangkat sebagian.',
                'created_at' => now()->subDays(3),
            ]);
        }

        return $order->refresh();
    }

    private function kirimUlang(SalesOrder $order, array $isi = [])
    {
        return $this->post(route('wms.outstanding.reship', $order), $isi);
    }

    /**
     * Memberangkatkan putaran yang SEDANG dibuka, sebanyak $qty.
     *
     * Menempuh mesin pengiriman yang sungguhan — App\Support\Outbound\Shipment
     * — bukan menulis langsung ke kolomnya. Yang diuji di sini justru apakah
     * kekurangan sisa masuk kembali ke outstanding, dan itu efek samping dari
     * Surat Jalan yang berangkat; meniru efeknya dengan tangan akan membuat
     * test-nya hijau untuk sesuatu yang tidak pernah dijalankan sistem.
     */
    private function berangkatkanPutaran(SalesOrder $order, int $qty): DeliveryNote
    {
        $detail = $order->details()->firstOrFail();

        $daftar = PickingList::factory()->create([
            'warehouse_id' => $this->gudang->id,
            'status' => PickingList::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        // Efek Siap Loading pada stok: cadangan berakhir karena barangnya
        // benar-benar turun dari rak.
        foreach ($detail->allocations as $alokasi) {
            $stok = InventoryStock::findOrFail($alokasi->inventory_stock_id);
            $stok->forceFill([
                'qty_allocated' => max(0, $stok->qty_allocated - $alokasi->qty_allocated),
            ])->save();

            PickingListItem::factory()->create([
                'picking_list_id' => $daftar->id,
                'sales_order_id' => $order->id,
                'sales_order_detail_id' => $detail->id,
                'product_id' => $this->produk->id,
                'inventory_stock_id' => $stok->id,
                'location_id' => $this->lokasi->id,
                'batch_no' => $stok->batch_no,
                'production_date' => $stok->production_date,
                'qty_to_pick' => $alokasi->qty_allocated,
                'qty_picked' => $alokasi->qty_allocated,
                'status' => PickingListItem::STATUS_PICKED,
            ]);
        }

        $detail->allocations()->delete();

        $order->forceFill([
            'status' => SalesOrder::STATUS_READY_TO_SHIP,
            'picking_list_id' => $daftar->id,
            'picking_completed_at' => now(),
        ])->save();

        $note = DeliveryNote::factory()->create([
            'document_no' => 'SJ-'.Str::upper(Str::random(5)),
            // Pesanan pada helper ini dibuat tanpa nomor SO BC; Surat Jalannya
            // tetap wajib punya satu karena itu kolom yang tidak boleh kosong.
            'bc_so_number' => $order->bc_so_number ?: 'SO0987010',
            'sales_order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $order->warehouse_id,
        ]);

        DeliveryNoteLine::factory()->create([
            'delivery_note_id' => $note->id,
            'sku' => $this->produk->sku,
            'product_id' => $this->produk->id,
            'qty' => $qty,
        ]);

        app(Shipment::class)->ship($note->refresh(), [
            'driver_name' => 'Budi Santoso',
            'driver_phone' => '081234567890',
            'vehicle_plate' => 'B 1234 XYZ',
        ], null);

        return $note->refresh();
    }

    /* ------------------------------------------------------------- Inti */

    public function test_nomor_so_tetap_sama_dan_tidak_ada_pesanan_baru(): void
    {
        $this->loginAs();
        $this->stok(100);
        $order = $this->pesananBerangkatSebagian(10, 6);
        $nomorSemula = $order->order_number;

        $this->kirimUlang($order)->assertSessionHas('success');

        $this->assertSame(1, SalesOrder::count(), 'Pengiriman ulang TIDAK membuat pesanan baru.');
        $this->assertSame($nomorSemula, $order->refresh()->order_number);
    }

    public function test_pesanan_kembali_ke_status_diterima_dan_siap_masuk_daftar_picking(): void
    {
        $this->loginAs();
        $this->stok(100);

        $order = $this->pesananBerangkatSebagian(10, 6);

        $this->kirimUlang($order);

        $segar = $order->refresh();
        $this->assertSame(SalesOrder::STATUS_APPROVED, $segar->status);
        $this->assertNull($segar->picking_list_id,
            'picking_list_id yang tertinggal membuat pesanan ini tidak pernah bisa masuk daftar picking berikutnya.');
        $this->assertNull($segar->shipped_at);
    }

    public function test_kekurangan_dicadangkan_dari_stok_yang_ada_sekarang(): void
    {
        $this->loginAs();
        $stok = $this->stok(100);
        $order = $this->pesananBerangkatSebagian(10, 6);

        $this->kirimUlang($order);

        // 4 yang kurang berpindah dari tersedia ke teralokasi.
        $this->assertSame(96, $stok->fresh()->qty_available);
        $this->assertSame(4, $stok->fresh()->qty_allocated);
    }

    public function test_stok_yang_cuma_cukup_sebagian_tetap_dicadangkan(): void
    {
        $this->loginAs();
        $this->stok(3);
        $order = $this->pesananBerangkatSebagian(10, 6);

        $this->kirimUlang($order)->assertSessionHas('warning');

        $riwayat = SalesOrderReshipment::firstOrFail();
        $this->assertSame(4, $riwayat->qty_outstanding);
        $this->assertSame(3, $riwayat->qty_allocated);
        $this->assertSame(1, $riwayat->qty_belum_kebagian,
            'Sisanya TETAP terutang dan bisa dikirim ulang lagi — bukan ditolak seluruhnya.');
    }

    public function test_tanpa_stok_sama_sekali_putaran_tetap_dibuka(): void
    {
        $this->loginAs();
        $order = $this->pesananBerangkatSebagian(10, 6);

        $this->kirimUlang($order)->assertSessionHas('warning');

        $this->assertSame(SalesOrder::STATUS_APPROVED, $order->refresh()->status);
        $this->assertSame(0, SalesOrderReshipment::firstOrFail()->qty_allocated);
    }

    /* -------------------------------------------------------- Riwayatnya */

    public function test_putaran_dihitung_naik_tiap_kali_dibuka(): void
    {
        $this->loginAs();
        $this->stok(100);
        $order = $this->pesananBerangkatSebagian(10, 6);

        $this->kirimUlang($order);
        $this->assertSame(1, SalesOrderReshipment::firstOrFail()->round_no);

        // Putaran kedua: dikembalikan ke keadaan sudah berangkat lagi, dan
        // masih ada sisa kekurangan.
        // refresh() dulu: instance di test masih memegang status lama dari
        // sebelum kirim ulang, sehingga forceFill ke nilai yang sama tidak
        // menandai apa pun kotor dan save() jadi tidak melakukan apa-apa.
        $order->refresh()->forceFill(['status' => SalesOrder::STATUS_COMPLETED])->save();
        $order->details()->update(['outstanding_qty' => 2]);

        $this->kirimUlang($order)->assertSessionHas('success');

        $this->assertSame(2, SalesOrderReshipment::latest('id')->first()->round_no);
        $this->assertSame(2, SalesOrderReshipment::count());
    }

    public function test_riwayat_pengiriman_ulang_tidak_boleh_diubah(): void
    {
        $this->loginAs();
        $this->stok(100);
        $this->kirimUlang($this->pesananBerangkatSebagian(10, 6));

        $this->expectException(RuntimeException::class);

        SalesOrderReshipment::firstOrFail()->update(['qty_allocated' => 999]);
    }

    /* ------------------------------------------------------------ Batas */

    public function test_pesanan_tanpa_kekurangan_ditolak(): void
    {
        $this->loginAs();
        $order = $this->pesananBerangkatSebagian(10, 10);

        $this->kirimUlang($order)->assertSessionHas('error');

        $this->assertSame(0, SalesOrderReshipment::count());
    }

    /**
     * Membuka putaran baru di atas putaran yang masih berjalan akan
     * mencadangkan stok DUA KALI untuk kekurangan yang sama.
     */
    public function test_pesanan_yang_putarannya_masih_berjalan_ditolak(): void
    {
        $this->loginAs();
        $this->stok(100);
        $order = $this->pesananBerangkatSebagian(10, 6);

        foreach ([SalesOrder::STATUS_APPROVED, SalesOrder::STATUS_PICKING, SalesOrder::STATUS_READY_TO_SHIP] as $status) {
            $order->forceFill(['status' => $status])->save();

            $this->kirimUlang($order)->assertSessionHas('error');
        }

        $this->assertSame(0, SalesOrderReshipment::count());
    }

    public function test_gudang_lain_ditolak(): void
    {
        $lain = Warehouse::factory()->create(['code' => 'PKU']);
        $order = $this->pesananBerangkatSebagian(10, 6);

        $this->loginAs(Role::LOGISTICS, $lain);

        $this->kirimUlang($order)->assertForbidden();
    }

    public function test_sales_tidak_boleh_membuka_pengiriman_ulang(): void
    {
        $order = $this->pesananBerangkatSebagian(10, 6);

        $this->loginAs(Role::SALES);

        $this->kirimUlang($order)->assertForbidden();
    }

    /* ------------------------------------- Sisa kurang masuk lagi ke daftar */

    /**
     * KEKURANGAN YANG BELUM TERTUTUP MASUK LAGI KE OUTSTANDING.
     *
     * Permintaan pemilik produk, dan satu-satunya hal yang membuat tombol
     * Kirim Outstanding layak dipercaya: pesanan 10 yang baru terkirim 6 lalu
     * dikirim outstanding 3 masih menyisakan 1, dan 1 itu harus punya barisnya
     * sendiri di daftar — bukan diam-diam hilang karena kekurangannya "sudah
     * pernah dicatat" pada peristiwa sebelumnya.
     */
    public function test_sisa_yang_masih_kurang_masuk_lagi_ke_outstanding(): void
    {
        $this->loginAs();

        $order = $this->pesananBerangkatSebagian(10, 6);

        // Stok susulan hanya cukup 3 dari 4 yang kurang.
        $this->stok(3);

        $this->kirimUlang($order)->assertSessionHas('warning');

        $this->berangkatkanPutaran($order->refresh(), 3);

        $detail = $order->details()->firstOrFail()->refresh();

        $this->assertSame(9, $detail->qty_shipped, 'qty_shipped menumpuk antar putaran: 6 + 3.');
        $this->assertSame(1, $detail->outstanding_qty);

        $terakhir = SalesOrderOutstanding::query()
            ->where('sales_order_detail_id', $detail->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(1, $terakhir->qty_outstanding, 'Sisa 1 wajib punya barisnya sendiri.');
        $this->assertSame(9, $terakhir->qty_fulfilled);
        $this->assertSame(SalesOrderOutstanding::CAUSE_SHIPMENT, $terakhir->cause);

        $this->assertSame(2, SalesOrderOutstanding::where('sales_order_detail_id', $detail->id)->count(),
            'Dua peristiwa yang berbeda: kurang 4 saat putaran pertama, kurang 1 saat putaran kedua.');
    }

    /** Dan pesanannya boleh dikirim outstanding SEKALI LAGI untuk sisa itu. */
    public function test_sisa_itu_bisa_dikirim_outstanding_sekali_lagi(): void
    {
        $this->loginAs();

        $order = $this->pesananBerangkatSebagian(10, 6);
        $this->stok(3);
        $this->kirimUlang($order);
        $this->berangkatkanPutaran($order->refresh(), 3);

        $boleh = $this->get(route('wms.outstanding.index'))->assertOk()->viewData('bolehKirimUlang');

        $this->assertTrue($boleh->has($order->id),
            'Sisa 1 masih kewajiban yang belum dipenuhi — tombolnya harus muncul lagi.');

        // Stok susulan terakhir, lalu putaran ketiga menutup semuanya.
        $this->stok(1);
        $this->kirimUlang($order->refresh())->assertSessionHas('success');

        $this->assertSame(2, SalesOrderReshipment::where('sales_order_id', $order->id)->count(),
            'Dua kali kirim outstanding di atas putaran pertama.');
        $this->assertSame(2, (int) SalesOrderReshipment::where('sales_order_id', $order->id)->max('round_no'));
    }

    /* ---------------------------------------------------------- Tampilan */

    public function test_tombol_kirim_ulang_hanya_untuk_pesanan_yang_masih_kurang(): void
    {
        $this->loginAs();
        $kurang = $this->pesananBerangkatSebagian(10, 6);

        // Pernah kurang, lalu SUDAH dipenuhi. Riwayatnya tetap ada di layar —
        // memang begitu maksudnya — tetapi tombolnya tidak boleh muncul lagi.
        $lunas = $this->pesananBerangkatSebagian(10, 6);
        $lunas->details()->update(['qty_shipped' => 10, 'outstanding_qty' => 0]);

        $boleh = $this->get(route('wms.outstanding.index'))->assertOk()->viewData('bolehKirimUlang');

        $this->assertTrue($boleh->has($kurang->id));
        $this->assertFalse($boleh->has($lunas->id),
            'Kekurangan yang sudah dipenuhi tidak boleh bisa dikirim ulang lagi.');
    }
}
