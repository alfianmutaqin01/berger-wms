<?php

namespace Tests\Feature\Wms;

use App\Models\Customer;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\PaymentTerm;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderCancellation;
use App\Models\SalesOrderDetail;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Outbound\FifoAllocator;
use App\Support\Outbound\PendingAllocationFiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Picking — PRD §6.5 F-OUT-03, Fase 6 tahap 3.
 *
 * EMPAT HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * ---------------------------------------------------
 * 1. LEDGER HARUS TETAP SETARA qty_available. Saat alokasi dibuat, barangnya
 *    sudah dipindahkan dari qty_available ke qty_allocated. Menuliskan satu
 *    baris OUT bernilai negatif saat picking akan mengurangi barang yang sama
 *    untuk KEDUA KALINYA — dan tidak ada layar yang menampilkan itu. Karena
 *    itu ada test yang menjumlahkan seluruh ledger dan membandingkannya.
 * 2. SATU PESANAN TIDAK BOLEH ADA DI DUA DAFTAR. Kalau bisa, barangnya
 *    diambil dua kali oleh dua operator, dan yang kedua baru ketahuan di rak.
 * 3. SELISIH BUKAN BARANG KELUAR. Yang tidak ditemukan di rak tidak pernah
 *    sampai ke customer; menghitungnya sebagai OUT membuat laporan pengiriman
 *    lebih besar daripada yang benar-benar dikirim.
 * 4. MEMBATALKAN PESANAN YANG SUDAH DIPICKING HARUS MENGEMBALIKAN BARANGNYA.
 *    Sesudah picking, alokasinya sudah habis dipakai — jalur pembatalan lama
 *    tidak menemukan apa pun untuk dilepas, dan stoknya lenyap tanpa jejak.
 */
class PickingTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $karawang;

    private Warehouse $pekanbaru;

    private Location $rakDepan;

    private Location $rakBelakang;

    private Product $produk;

    private Customer $customer;

    private PaymentTerm $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->karawang = Warehouse::factory()->withProduction()->create(['code' => 'WH-01', 'name' => 'Karawang']);
        $this->pekanbaru = Warehouse::factory()->create(['code' => 'WH-02', 'name' => 'Pekanbaru']);

        $this->rakDepan = Location::factory()->create([
            'warehouse_id' => $this->karawang->id, 'code' => 'A-01-01', 'is_active' => true,
        ]);
        $this->rakBelakang = Location::factory()->create([
            'warehouse_id' => $this->karawang->id, 'code' => 'Z-09-09', 'is_active' => true,
        ]);

        $this->produk = Product::factory()->create(['sku' => 'APKO-001', 'uom' => 'PAIL', 'is_active' => true]);
        $this->customer = Customer::factory()->create(['is_active' => true]);
        $this->term = PaymentTerm::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Cash / Tunai', 'days' => 0, 'is_active' => true, 'sort_order' => 1]
        );
    }

    /* ------------------------------------------------------------ Perkakas */

    private function loginAt(?Warehouse $gudang, string $slug = Role::LOGISTICS): User
    {
        $user = User::factory()->withRole($slug)->create(['warehouse_id' => $gudang?->id]);
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

    private function stok(int $qty, ?Location $rak = null, array $atribut = []): InventoryStock
    {
        return InventoryStock::factory()->create(array_merge([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->karawang->id,
            'location_id' => ($rak ?? $this->rakDepan)->id,
            'batch_no' => 'BT-2601',
            'production_date' => '2026-01-15',
            'expiry_date' => '2028-01-15',
            'qty_available' => $qty,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ], $atribut));
    }

    /**
     * Pesanan yang SUDAH diterima dan sudah dialokasikan sungguhan.
     *
     * Dialokasikan lewat FifoAllocator, bukan dengan menulis baris alokasi
     * langsung: yang diuji di sini adalah lanjutan dari keadaan yang dibuat
     * alokasi, dan keadaan buatan tangan bisa saja tidak pernah bisa terjadi.
     */
    private function pesananSiapPicking(int $qty = 10, ?Warehouse $gudang = null): SalesOrder
    {
        $gudang = $gudang ?? $this->karawang;

        $order = SalesOrder::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $gudang->id,
            'payment_term_id' => $this->term->id,
            'status' => SalesOrder::STATUS_APPROVED,
            'bc_so_number' => 'SO-'.Str::random(6),
            'approved_at' => now(),
            'submitted_at' => now()->subHour(),
        ]);

        $detail = SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => $qty,
            'qty_approved' => $qty,
            'outstanding_qty' => 0,
        ]);

        DB::transaction(fn () => app(FifoAllocator::class)->allocate($detail, $qty, null));

        return $order->refresh();
    }

    /** @param  list<SalesOrder>  $pesanan */
    private function susunDaftar(array $pesanan, ?Warehouse $gudang = null)
    {
        return $this->post(route('wms.picking.store'), [
            'warehouse_id' => ($gudang ?? $this->karawang)->id,
            'order_ids' => array_map(fn (SalesOrder $o) => $o->id, $pesanan),
        ]);
    }

    /** Jumlah seluruh mutasi satu baris stok. */
    private function jumlahLedger(InventoryStock $stok): int
    {
        return (int) StockMovement::query()
            ->where('product_id', $stok->product_id)
            ->where('location_id', $stok->location_id)
            ->where('batch_no', $stok->batch_no)
            ->sum('qty_change');
    }

    /**
     * Ledger harus menjelaskan SELURUH perubahan qty_available.
     *
     * Dibandingkan sebagai SELISIH dari qty awal, bukan angka mutlaknya:
     * stok di test ini ditanam langsung ke tabel tanpa mutasi IN, sehingga
     * ledgernya memang tidak memuat 100 yang pertama. Yang harus benar
     * adalah setiap perubahan SESUDAH itu punya barisnya sendiri — dan itu
     * yang menangkap satu OUT yang mengurangi barang yang sama dua kali.
     */
    private function assertLedgerMenjelaskanSeluruhPerubahan(InventoryStock $stok, int $qtyAwal): void
    {
        $stok->refresh();

        $this->assertSame(
            $stok->qty_available - $qtyAwal,
            $this->jumlahLedger($stok),
            'Setiap perubahan qty_available harus punya barisnya sendiri di ledger.'
        );
    }

    /* ------------------------------------------------ Penyusunan (Logistik) */

    public function test_logistik_menyusun_satu_daftar_dari_beberapa_pesanan(): void
    {
        $this->stok(100);
        $this->loginAt($this->karawang);

        $satu = $this->pesananSiapPicking(10);
        $dua = $this->pesananSiapPicking(15);

        $this->susunDaftar([$satu, $dua])->assertRedirect();

        $daftar = PickingList::first();

        $this->assertNotNull($daftar);
        $this->assertSame(PickingList::STATUS_OPEN, $daftar->status);
        $this->assertSame(2, $daftar->orders()->count());
        $this->assertSame(2, $daftar->items()->count());
        $this->assertStringStartsWith('PL', $daftar->list_number);
    }

    public function test_baris_daftar_membekukan_rak_batch_dan_tanggal_produksi(): void
    {
        $stok = $this->stok(100);
        $this->loginAt($this->karawang);

        $order = $this->pesananSiapPicking(10);
        $this->susunDaftar([$order]);

        $baris = PickingListItem::first();

        $this->assertSame($this->rakDepan->id, $baris->location_id);
        $this->assertSame('BT-2601', $baris->batch_no);
        $this->assertSame('2026-01-15', $baris->production_date->toDateString());
        $this->assertSame(10, $baris->qty_to_pick);
        $this->assertSame($stok->id, $baris->inventory_stock_id);
        $this->assertNull($baris->qty_picked);
    }

    public function test_satu_baris_per_batch_bukan_per_sku(): void
    {
        // Dua batch, yang tertua di rak belakang. FIFO memecah pesanan 30
        // menjadi 20 dari batch lama + 10 dari batch baru.
        $this->stok(20, $this->rakBelakang, ['batch_no' => 'BT-LAMA', 'production_date' => '2025-01-01']);
        $this->stok(50, $this->rakDepan, ['batch_no' => 'BT-BARU', 'production_date' => '2026-06-01']);

        $this->loginAt($this->karawang);
        $order = $this->pesananSiapPicking(30);

        $this->susunDaftar([$order]);

        $baris = PickingListItem::orderBy('id')->get();

        $this->assertCount(2, $baris, 'Satu pesanan yang terpecah ke dua batch harus jadi dua baris pengambilan.');
        $this->assertSame(['BT-LAMA', 'BT-BARU'], $baris->pluck('batch_no')->all());
        $this->assertSame([20, 10], $baris->pluck('qty_to_pick')->all());
    }

    public function test_pesanan_yang_sudah_masuk_daftar_hilang_dari_antrean(): void
    {
        $this->stok(100);
        $this->loginAt($this->karawang);

        $order = $this->pesananSiapPicking(10);
        $this->susunDaftar([$order]);

        $this->get(route('wms.picking.batching'))
            ->assertOk()
            ->assertDontSee($order->order_number);
    }

    public function test_pesanan_yang_sudah_di_daftar_lain_ditolak(): void
    {
        $this->stok(100);
        $this->loginAt($this->karawang);

        $order = $this->pesananSiapPicking(10);
        $this->susunDaftar([$order]);

        $this->susunDaftar([$order])->assertSessionHas('error');

        $this->assertSame(1, PickingList::count(), 'Daftar kedua tidak boleh ikut terbuat.');
    }

    public function test_pesanan_gudang_lain_tidak_bisa_dimasukkan(): void
    {
        $this->stok(100);
        $this->loginAt($this->karawang);

        $pesananPekanbaru = SalesOrder::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->pekanbaru->id,
            'payment_term_id' => $this->term->id,
            'status' => SalesOrder::STATUS_APPROVED,
            'submitted_at' => now()->subHour(),
        ]);

        $this->susunDaftar([$pesananPekanbaru])->assertSessionHas('error');

        $this->assertSame(0, PickingList::count());
    }

    public function test_menyusun_daftar_untuk_gudang_lain_ditolak_403(): void
    {
        $this->loginAt($this->karawang);

        // Batasnya ditegakkan di authorize(), sehingga 403 muncul TANPA
        // bergantung pada isian lain yang lengkap — permintaan setengah jadi
        // pun tidak boleh dijawab "isian kurang".
        $this->post(route('wms.picking.store'), [
            'warehouse_id' => $this->pekanbaru->id,
        ])->assertForbidden();
    }

    public function test_pesanan_yang_belum_diterima_tidak_bisa_dipicking(): void
    {
        $this->loginAt($this->karawang);

        $order = SalesOrder::factory()->submitted()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->karawang->id,
            'payment_term_id' => $this->term->id,
        ]);

        $this->susunDaftar([$order])->assertSessionHas('error');
        $this->assertSame(0, PickingList::count());
    }

    public function test_porsi_yang_menunggu_stok_tidak_ikut_ke_daftar(): void
    {
        // Stok hanya 4, disetujui 10. Sisanya dijanjikan tetapi belum punya
        // batch maupun rak — tidak ada yang bisa ditulis di daftar.
        $this->stok(4);
        $this->loginAt($this->karawang);

        $order = $this->pesananSiapPicking(10);
        $this->susunDaftar([$order]);

        $baris = PickingListItem::first();

        $this->assertSame(4, $baris->qty_to_pick);
        $this->assertSame(1, PickingListItem::count());
    }

    public function test_daftar_yang_seluruhnya_menunggu_stok_ditolak(): void
    {
        // Tidak ada stok sama sekali: tidak ada satu baris pun yang bisa
        // diambil, dan daftar tanpa baris adalah tugas yang menggantung
        // selamanya di antrean operator.
        $this->loginAt($this->karawang);

        $order = $this->pesananSiapPicking(10);

        $this->susunDaftar([$order])->assertSessionHas('error');

        $this->assertSame(0, PickingList::count());
        $this->assertNull($order->fresh()->picking_list_id);
    }

    /* -------------------------------------------------- Pengerjaan (Operator) */

    public function test_operator_mengambil_tugas_dan_pesanan_berpindah_status(): void
    {
        $this->stok(100);
        $this->loginAt($this->karawang);
        $order = $this->pesananSiapPicking(10);
        $this->susunDaftar([$order]);
        $daftar = PickingList::first();

        $operator = $this->loginAt($this->karawang, Role::WAREHOUSE_OPERATOR);
        $this->post(route('wms.picking.claim', $daftar))->assertRedirect();

        $daftar->refresh();

        $this->assertSame(PickingList::STATUS_PICKING, $daftar->status);
        $this->assertSame($operator->id, $daftar->claimed_by);
        $this->assertSame(SalesOrder::STATUS_PICKING, $order->fresh()->status);
    }

    public function test_tugas_yang_sudah_dipegang_tidak_bisa_diambil_operator_lain(): void
    {
        $daftar = $this->daftarSiapDikerjakan();

        $this->loginAt($this->karawang, Role::WAREHOUSE_OPERATOR);
        $this->post(route('wms.picking.claim', $daftar))->assertSessionHas('error');

        $this->assertNotSame(auth()->id(), $daftar->fresh()->claimed_by);
    }

    public function test_operator_lain_tidak_bisa_menandai_baris_daftar_orang(): void
    {
        $daftar = $this->daftarSiapDikerjakan();
        $baris = $daftar->items()->first();

        $this->loginAt($this->karawang, Role::WAREHOUSE_OPERATOR);
        $this->post(route('wms.picking.item.pick', [$daftar, $baris]))->assertSessionHas('error');

        $this->assertSame(PickingListItem::STATUS_PENDING, $baris->fresh()->status);
    }

    public function test_satu_ketuk_menandai_baris_terambil_penuh(): void
    {
        $daftar = $this->daftarSiapDikerjakan();
        $baris = $daftar->items()->first();

        $this->post(route('wms.picking.item.pick', [$daftar, $baris]))->assertRedirect();

        $baris->refresh();

        $this->assertSame(PickingListItem::STATUS_PICKED, $baris->status);
        $this->assertSame($baris->qty_to_pick, $baris->qty_picked);
        $this->assertNull($baris->discrepancy_reason);
    }

    public function test_tanda_bisa_dibatalkan_saat_operator_salah_ketuk(): void
    {
        $daftar = $this->daftarSiapDikerjakan();
        $baris = $daftar->items()->first();

        $this->post(route('wms.picking.item.pick', [$daftar, $baris]));
        $this->post(route('wms.picking.item.reset', [$daftar, $baris]))->assertRedirect();

        $baris->refresh();

        $this->assertSame(PickingListItem::STATUS_PENDING, $baris->status);
        $this->assertNull($baris->qty_picked);
    }

    public function test_baris_milik_daftar_lain_ditolak_404(): void
    {
        $daftar = $this->daftarSiapDikerjakan();

        $daftarLain = PickingList::factory()->create(['warehouse_id' => $this->karawang->id]);
        $barisLain = PickingListItem::factory()->create([
            'picking_list_id' => $daftarLain->id,
            'sales_order_id' => $daftar->orders()->first()->id,
            'sales_order_detail_id' => $daftar->items()->first()->sales_order_detail_id,
            'product_id' => $this->produk->id,
            'location_id' => $this->rakDepan->id,
        ]);

        // Daftar di URL boleh dibuka, barisnya milik daftar lain. Tanpa
        // pemeriksaan pasangan, yang tertandai adalah baris orang lain.
        $this->post(route('wms.picking.item.pick', [$daftar, $barisLain]))->assertNotFound();
    }

    /* ------------------------------------------------------------- Selisih */

    public function test_selisih_wajib_beralasan(): void
    {
        $daftar = $this->daftarSiapDikerjakan();
        $baris = $daftar->items()->first();

        $this->post(route('wms.picking.item.short', [$daftar, $baris]), [
            'qty_picked' => 5,
        ])->assertSessionHasErrors('discrepancy_reason');

        $this->assertSame(PickingListItem::STATUS_PENDING, $baris->fresh()->status);
    }

    public function test_selisih_tidak_boleh_sama_dengan_qty_daftar(): void
    {
        $daftar = $this->daftarSiapDikerjakan();
        $baris = $daftar->items()->first();

        $this->post(route('wms.picking.item.short', [$daftar, $baris]), [
            'qty_picked' => $baris->qty_to_pick,
            'discrepancy_reason' => 'Sebenarnya lengkap, salah tekan tombol.',
        ])->assertSessionHas('error');

        $this->assertSame(PickingListItem::STATUS_PENDING, $baris->fresh()->status);
    }

    public function test_selisih_tercatat_lengkap_dengan_alasannya(): void
    {
        $daftar = $this->daftarSiapDikerjakan();
        $baris = $daftar->items()->first();

        $this->post(route('wms.picking.item.short', [$daftar, $baris]), [
            'qty_picked' => 6,
            'discrepancy_reason' => 'Rak hanya berisi 6 pail, sisanya tidak ditemukan.',
        ])->assertRedirect();

        $baris->refresh();

        $this->assertSame(PickingListItem::STATUS_SHORT, $baris->status);
        $this->assertSame(6, $baris->qty_picked);
        $this->assertSame(4, $baris->qty_kurang);
        $this->assertNotNull($baris->discrepancy_reason);
    }

    /* --------------------------------------------------------- Siap Loading */

    public function test_siap_loading_ditolak_selama_masih_ada_baris_belum_ditandai(): void
    {
        $daftar = $this->daftarSiapDikerjakan();

        $this->post(route('wms.picking.complete', $daftar))->assertSessionHas('error');

        $this->assertSame(PickingList::STATUS_PICKING, $daftar->fresh()->status);
    }

    public function test_siap_loading_mengurangi_stok_dan_menutup_alokasi(): void
    {
        $stok = $this->stok(100);
        $daftar = $this->daftarSiapDikerjakan(10, $stok);
        $baris = $daftar->items()->first();

        // Sebelum picking: 90 bebas, 10 dicadangkan.
        $stok->refresh();
        $this->assertSame(90, $stok->qty_available);
        $this->assertSame(10, $stok->qty_allocated);

        $this->post(route('wms.picking.item.pick', [$daftar, $baris]));
        $this->post(route('wms.picking.complete', $daftar))->assertRedirect();

        $stok->refresh();

        // Sesudah: 90 tetap bebas (yang keluar memang bukan bagian bebas),
        // dan cadangannya habis karena barangnya sudah turun dari rak.
        $this->assertSame(90, $stok->qty_available);
        $this->assertSame(0, $stok->qty_allocated);
        $this->assertSame(0, $baris->detail->allocations()->count());
    }

    public function test_siap_loading_memindahkan_pesanan_ke_siap_kirim(): void
    {
        $daftar = $this->daftarSiapDikerjakan();
        $order = $daftar->orders()->first();

        $this->post(route('wms.picking.item.pick', [$daftar, $daftar->items()->first()]));
        $this->post(route('wms.picking.complete', $daftar));

        $order->refresh();

        $this->assertSame(SalesOrder::STATUS_READY_TO_SHIP, $order->status);
        $this->assertNotNull($order->picking_completed_at);
        $this->assertSame(PickingList::STATUS_COMPLETED, $daftar->fresh()->status);
    }

    public function test_ledger_tetap_setara_qty_available_sesudah_picking(): void
    {
        // Inti pertahanannya. Alokasi SUDAH mengurangi qty_available; satu
        // baris OUT tanpa DEALLOCATED pendampingnya akan menguranginya untuk
        // kedua kalinya di ledger, dan tidak ada layar yang menampilkan itu.
        $stok = $this->stok(100);
        $daftar = $this->daftarSiapDikerjakan(10, $stok);

        $this->post(route('wms.picking.item.pick', [$daftar, $daftar->items()->first()]));
        $this->post(route('wms.picking.complete', $daftar));

        $this->assertSame(90, $stok->fresh()->qty_available);
        $this->assertLedgerMenjelaskanSeluruhPerubahan($stok, 100);
    }

    public function test_selisih_dicatat_sebagai_koreksi_bukan_barang_keluar(): void
    {
        $stok = $this->stok(100);
        $daftar = $this->daftarSiapDikerjakan(10, $stok);
        $baris = $daftar->items()->first();

        $this->post(route('wms.picking.item.short', [$daftar, $baris]), [
            'qty_picked' => 6,
            'discrepancy_reason' => 'Rak hanya berisi 6 pail, sisanya tidak ditemukan.',
        ]);
        $this->post(route('wms.picking.complete', $daftar))->assertSessionHas('warning');

        $keluar = StockMovement::where('movement_type', StockMovement::TYPE_OUT)->sum('qty_change');
        $koreksi = StockMovement::where('movement_type', StockMovement::TYPE_ADJUSTMENT)->sum('qty_change');

        $this->assertSame(-6, (int) $keluar, 'Yang keluar hanya yang benar-benar sampai ke customer.');
        $this->assertSame(-4, (int) $koreksi, 'Yang tidak ditemukan adalah koreksi stok, bukan barang keluar.');

        $stok->refresh();

        // 100 - 10 dicadangkan = 90 bebas; cadangan 10 berakhir, 6 keluar,
        // 4 ternyata tidak ada. Yang tersisa di rak: 90.
        $this->assertSame(90, $stok->qty_available);
        $this->assertSame(0, $stok->qty_allocated);
        $this->assertLedgerMenjelaskanSeluruhPerubahan($stok, 100);
    }

    public function test_koreksi_selisih_menyertakan_alasan_operator(): void
    {
        $stok = $this->stok(100);
        $daftar = $this->daftarSiapDikerjakan(10, $stok);

        $this->post(route('wms.picking.item.short', [$daftar, $daftar->items()->first()]), [
            'qty_picked' => 6,
            'discrepancy_reason' => 'Rak hanya berisi 6 pail, sisanya tidak ditemukan.',
        ]);
        $this->post(route('wms.picking.complete', $daftar));

        $koreksi = StockMovement::where('movement_type', StockMovement::TYPE_ADJUSTMENT)->first();

        $this->assertStringContainsString('sisanya tidak ditemukan', $koreksi->notes);
        $this->assertStringContainsString('A-01-01', $koreksi->notes);
    }

    /* ------------------------------------------------------- Pembubaran */

    public function test_daftar_yang_belum_tersentuh_bisa_dibubarkan(): void
    {
        $this->stok(100);
        $this->loginAt($this->karawang);
        $order = $this->pesananSiapPicking(10);
        $this->susunDaftar([$order]);
        $daftar = PickingList::first();

        $this->post(route('wms.picking.cancel', $daftar), [
            'cancellation_reason' => 'Container batal berangkat hari ini.',
        ])->assertRedirect();

        $daftar->refresh();
        $order->refresh();

        $this->assertSame(PickingList::STATUS_CANCELLED, $daftar->status);
        $this->assertSame(0, $daftar->items()->count());
        $this->assertNull($order->picking_list_id);
        $this->assertSame(SalesOrder::STATUS_APPROVED, $order->status);
    }

    public function test_daftar_yang_sudah_dikerjakan_sebagian_tidak_bisa_dibubarkan(): void
    {
        $daftar = $this->daftarSiapDikerjakan();
        $this->post(route('wms.picking.item.pick', [$daftar, $daftar->items()->first()]));

        $this->loginAt($this->karawang);
        $this->post(route('wms.picking.cancel', $daftar), [
            'cancellation_reason' => 'Ternyata containernya penuh, mau disusun ulang.',
        ])->assertSessionHas('error');

        $this->assertSame(PickingList::STATUS_PICKING, $daftar->fresh()->status);
    }

    /* ----------------------------------------------- Sambungan pembatalan */

    public function test_membatalkan_pesanan_mengeluarkannya_dari_daftar_yang_belum_selesai(): void
    {
        $this->stok(100);
        $this->loginAt($this->karawang);

        $tetap = $this->pesananSiapPicking(10);
        $batal = $this->pesananSiapPicking(10);
        $this->susunDaftar([$tetap, $batal]);
        $daftar = PickingList::first();

        $this->post(route('wms.approval.cancel', $batal), [
            'cancellation_source' => SalesOrderCancellation::SOURCE_CUSTOMER,
            'cancellation_reason' => 'Customer membatalkan pesanannya pagi ini.',
        ])->assertRedirect();

        $this->assertNull($batal->fresh()->picking_list_id);
        $this->assertSame(0, $daftar->items()->where('sales_order_id', $batal->id)->count());
        $this->assertSame(1, $daftar->fresh()->items()->count(), 'Baris pesanan lain tidak boleh ikut hilang.');
    }

    public function test_membatalkan_pesanan_yang_sudah_dipicking_mengembalikan_barangnya_ke_rak(): void
    {
        // Lubang yang paling mudah tertinggal: sesudah picking, alokasinya
        // sudah habis dipakai, jadi jalur pembatalan lama tidak menemukan apa
        // pun untuk dilepas — dan stoknya lenyap tanpa jejak.
        $stok = $this->stok(100);
        $daftar = $this->daftarSiapDikerjakan(10, $stok);
        $order = $daftar->orders()->first();

        $this->post(route('wms.picking.item.pick', [$daftar, $daftar->items()->first()]));
        $this->post(route('wms.picking.complete', $daftar));

        $this->assertSame(90, $stok->fresh()->qty_available);

        $this->loginAt($this->karawang);
        $this->post(route('wms.approval.cancel', $order), [
            'cancellation_source' => SalesOrderCancellation::SOURCE_BC,
            'cancellation_reason' => 'BC menolak karena limit kredit customer terlampaui.',
        ])->assertRedirect();

        $stok->refresh();

        $this->assertSame(100, $stok->qty_available, 'Barang yang sudah dipicking harus kembali ke raknya.');
        $this->assertLedgerMenjelaskanSeluruhPerubahan($stok, 100);
        $this->assertSame(SalesOrder::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_alokasi_susulan_tidak_menyusup_ke_daftar_yang_sudah_dibekukan(): void
    {
        // Stok hanya 4 dari 10 yang disetujui; sisanya menunggu. Daftar
        // dibuat atas 4 itu. Ketika stok baru masuk, porsi yang tertahan
        // TIDAK boleh ikut dialokasikan: ia tidak akan pernah muncul di
        // kertas yang sudah dibawa operator.
        $this->stok(4);
        $this->loginAt($this->karawang);

        $order = $this->pesananSiapPicking(10);
        $this->susunDaftar([$order]);

        $this->stok(50, $this->rakBelakang, ['batch_no' => 'BT-BARU', 'production_date' => '2026-08-01']);

        $hasil = DB::transaction(fn () => app(PendingAllocationFiller::class)
            ->fill($this->produk->id, $this->karawang->id, null));

        $this->assertSame(0, $hasil['terisi']);
        $this->assertSame(1, PickingListItem::count());
    }

    /* -------------------------------------------------- Pembatasan gudang */

    public function test_daftar_gudang_lain_tidak_bisa_dibuka(): void
    {
        $daftarPekanbaru = PickingList::factory()->create(['warehouse_id' => $this->pekanbaru->id]);

        $this->loginAt($this->karawang);

        $this->get(route('wms.picking.show', $daftarPekanbaru))->assertForbidden();
    }

    public function test_antrean_operator_hanya_memuat_daftar_gudangnya(): void
    {
        $milikKarawang = PickingList::factory()->create([
            'warehouse_id' => $this->karawang->id, 'list_number' => 'PL260916001',
        ]);
        $milikPekanbaru = PickingList::factory()->create([
            'warehouse_id' => $this->pekanbaru->id, 'list_number' => 'PL260916002',
        ]);

        $this->loginAt($this->karawang, Role::WAREHOUSE_OPERATOR);

        $this->get(route('wms.picking.queue'))
            ->assertOk()
            ->assertSee($milikKarawang->list_number)
            ->assertDontSee($milikPekanbaru->list_number);
    }

    /* ------------------------------------------------------------ Perkakas */

    /**
     * Daftar berisi satu pesanan, sudah dipegang seorang operator.
     *
     * Mengembalikan daftarnya dengan operator itu SEDANG login, karena
     * hampir semua test pengerjaan berlanjut sebagai operator tersebut.
     */
    private function daftarSiapDikerjakan(int $qty = 10, ?InventoryStock $stok = null): PickingList
    {
        if ($stok === null) {
            $this->stok(100);
        }

        $this->loginAt($this->karawang);
        $order = $this->pesananSiapPicking($qty);
        $this->susunDaftar([$order]);

        $daftar = PickingList::first();

        $this->loginAt($this->karawang, Role::WAREHOUSE_OPERATOR);
        $this->post(route('wms.picking.claim', $daftar));

        return $daftar->refresh();
    }
}
