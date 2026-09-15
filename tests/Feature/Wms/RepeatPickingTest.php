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
use App\Models\SalesOrder;
use App\Models\SalesOrderCancellation;
use App\Models\SalesOrderDetail;
use App\Models\Warehouse;
use App\Support\Outbound\FifoAllocator;
use App\Support\Outbound\OrderCanceller;
use App\Support\Outbound\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pesanan yang dipicking LEBIH DARI SATU KALI.
 *
 * TEMUAN LAPANGAN: layar Siap Kirim melaporkan "diambil dari rak 53" untuk
 * barang yang nyatanya dipicking 3. Angka 53 itu 50 dari putaran picking
 * pertama — yang pesanannya sudah dibatalkan dan barangnya sudah lama
 * dikembalikan ke rak — ditambah 3 dari putaran yang sekarang.
 *
 * Baris picking putaran lama memang TIDAK dihapus; ia riwayat daftar picking
 * yang sudah selesai dikerjakan. Yang keliru adalah menjumlahkan seluruh
 * baris milik satu pesanan seolah semuanya masih di dermaga.
 *
 * YANG JAUH LEBIH BERBAHAYA DARIPADA ANGKA DI LAYAR: pengembalian stok saat
 * pembatalan dicari dengan cara yang sama persis. Tanpa penyaringan putaran,
 * pembatalan KEDUA mengembalikan barang putaran pertama sekali lagi — stok
 * bertambah dari ketiadaan, ledger-nya tetap terlihat rapi, dan selisihnya
 * baru ketahuan saat stocktake.
 */
class RepeatPickingTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $gudang;

    private Location $rak;

    private Product $produk;

    private Customer $customer;

    private PaymentTerm $term;

    private InventoryStock $stok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->withProduction()->create(['code' => 'WH-01', 'name' => 'Karawang']);
        $this->rak = Location::factory()->create([
            'warehouse_id' => $this->gudang->id, 'code' => 'B-01-09', 'is_active' => true,
        ]);
        $this->produk = Product::factory()->create([
            'sku' => 'ID1-F0017X002801', 'name' => 'Bocor Guard 2 Base 1Kg', 'uom' => 'TIN', 'is_active' => true,
        ]);
        $this->customer = Customer::factory()->create(['is_active' => true]);
        $this->term = PaymentTerm::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Cash / Tunai', 'days' => 0, 'is_active' => true, 'sort_order' => 1]
        );

        $this->stok = InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->gudang->id,
            'location_id' => $this->rak->id,
            'batch_no' => 'I126080037',
            'production_date' => '2026-01-15',
            'expiry_date' => '2028-01-15',
            'qty_available' => 100,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);
    }

    private function pesanan(int $qty): SalesOrder
    {
        $order = SalesOrder::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->gudang->id,
            'payment_term_id' => $this->term->id,
            'status' => SalesOrder::STATUS_APPROVED,
            'bc_so_number' => 'SO0987004',
            'submitted_at' => now()->subDays(3),
            'approved_at' => now()->subDays(3),
        ]);

        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => $qty,
            'qty_approved' => $qty,
            'outstanding_qty' => 0,
        ]);

        return $order->refresh();
    }

    /**
     * Menjalankan satu PUTARAN picking sampai Siap Loading.
     *
     * Meniru alur aslinya: alokasi FIFO, daftar picking selesai, lalu
     * cadangan berakhir karena barangnya benar-benar turun dari rak.
     */
    private function putaranPicking(SalesOrder $order, int $qty): PickingList
    {
        $detail = $order->details()->firstOrFail();
        $detail->forceFill(['qty_approved' => $qty])->save();

        DB::transaction(fn () => app(FifoAllocator::class)->allocate($detail, $qty, null));

        $daftar = PickingList::factory()->create([
            'warehouse_id' => $this->gudang->id,
            'status' => PickingList::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        // Efek Siap Loading pada stok: cadangan berakhir, barangnya di dermaga.
        $this->stok->refresh();
        $this->stok->forceFill([
            'qty_allocated' => max(0, $this->stok->qty_allocated - $qty),
        ])->save();
        $detail->allocations()->delete();

        PickingListItem::factory()->create([
            'picking_list_id' => $daftar->id,
            'sales_order_id' => $order->id,
            'sales_order_detail_id' => $detail->id,
            'product_id' => $this->produk->id,
            'inventory_stock_id' => $this->stok->id,
            'location_id' => $this->rak->id,
            'batch_no' => 'I126080037',
            'production_date' => '2026-01-15',
            'qty_to_pick' => $qty,
            'qty_picked' => $qty,
            'status' => PickingListItem::STATUS_PICKED,
        ]);

        $order->forceFill([
            'status' => SalesOrder::STATUS_READY_TO_SHIP,
            'picking_list_id' => $daftar->id,
            'picking_completed_at' => now(),
        ])->save();

        $order->refresh();

        return $daftar;
    }

    private function batalkan(SalesOrder $order): array
    {
        return DB::transaction(fn () => app(OrderCanceller::class)->cancel(
            $order,
            SalesOrderCancellation::SOURCE_BC,
            'BC tidak menyetujui pesanan ini, barangnya kembali ke rak.',
            null,
        ));
    }

    /** Pesanan yang batal lalu diterima lagi, siap dipicking ulang. */
    private function terimaLagi(SalesOrder $order): SalesOrder
    {
        $order->refresh()->forceFill([
            'status' => SalesOrder::STATUS_APPROVED,
            'bc_so_number' => 'SO0987004',
            'approved_at' => now(),
        ])->save();

        return $order->refresh();
    }

    /**
     * Membangun keadaan persis seperti temuan lapangan: putaran pertama 50,
     * dibatalkan, lalu putaran kedua 3.
     */
    private function duaPutaran(): SalesOrder
    {
        $order = $this->pesanan(50);

        $this->putaranPicking($order, 50);
        $this->batalkan($order);

        $this->terimaLagi($order);
        $this->putaranPicking($order, 3);

        return $order->refresh();
    }

    /* ------------------------------------------------------- Angka di layar */

    public function test_diambil_dari_rak_hanya_menghitung_putaran_yang_berjalan(): void
    {
        $order = $this->duaPutaran();

        $note = DeliveryNote::factory()->create([
            'document_no' => '206215',
            'bc_so_number' => $order->bc_so_number,
            'sales_order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->gudang->id,
        ]);

        DeliveryNoteLine::factory()->create([
            'delivery_note_id' => $note->id,
            'sku' => $this->produk->sku,
            'product_id' => $this->produk->id,
            'qty' => 3,
        ]);

        $baris = app(Shipment::class)->bandingkan($note->refresh());

        $this->assertCount(1, $baris);
        $this->assertSame(3, $baris[0]['qty_sj']);
        $this->assertSame(3, $baris[0]['qty_picking'], 'Yang dipicking putaran ini 3, bukan 53.');
        $this->assertSame(0, $baris[0]['selisih']);
    }

    /**
     * Putaran lama tidak boleh muncul sebagai "turun dari rak tapi tidak
     * disebut Surat Jalan" — barangnya sudah lama berdiri di raknya sendiri.
     */
    public function test_putaran_lama_tidak_muncul_sebagai_barang_tak_tercantum(): void
    {
        $order = $this->duaPutaran();

        $note = DeliveryNote::factory()->create([
            'document_no' => '206216',
            'bc_so_number' => $order->bc_so_number,
            'sales_order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->gudang->id,
        ]);

        DeliveryNoteLine::factory()->create([
            'delivery_note_id' => $note->id,
            'sku' => $this->produk->sku,
            'product_id' => $this->produk->id,
            'qty' => 3,
        ]);

        $tidakCocok = app(Shipment::class)->skuTidakCocok($note->refresh());

        $this->assertSame([], $tidakCocok['di_sj_saja']);
        $this->assertSame([], $tidakCocok['di_picking_saja']);
    }

    /* --------------------------------------------------------- Stok hantu */

    /**
     * Yang paling mahal kalau salah: pembatalan kedua mengembalikan barang
     * putaran pertama SEKALI LAGI.
     *
     * Stoknya 100 sejak awal. Putaran 1 menurunkan 50 lalu dibatalkan
     * (kembali 100), putaran 2 menurunkan 3. Pembatalan kedua hanya boleh
     * mengembalikan 3 itu — bukan 53.
     */
    public function test_pembatalan_kedua_tidak_mengembalikan_barang_putaran_pertama(): void
    {
        $order = $this->duaPutaran();

        $this->assertSame(97, $this->stok->fresh()->qty_available, 'Putaran kedua menurunkan 3 dari rak.');

        $hasil = $this->batalkan($order);

        $this->assertSame(3, $hasil['qty_dilepas'], 'Hanya barang putaran ini yang kembali.');
        $this->assertSame(100, $this->stok->fresh()->qty_available, 'Stok kembali utuh, tidak lebih.');
    }

    /** Pembatalan putaran pertama sendiri tetap mengembalikan seluruhnya. */
    public function test_pembatalan_putaran_pertama_tetap_mengembalikan_seluruhnya(): void
    {
        $order = $this->pesanan(50);
        $this->putaranPicking($order, 50);

        $this->assertSame(50, $this->stok->fresh()->qty_available);

        $hasil = $this->batalkan($order);

        $this->assertSame(50, $hasil['qty_dilepas']);
        $this->assertSame(100, $this->stok->fresh()->qty_available);
    }
}
