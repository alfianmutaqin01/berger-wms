<?php

namespace Tests\Feature\Wms;

use App\Jobs\SendDeliveryNotification;
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
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Messaging\DispatchResult;
use App\Support\Messaging\WhatsAppSender;
use App\Support\Outbound\FifoAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pengiriman & konfirmasi supir — PRD §6.5 F-OUT-04, Fase 6 tahap 4.
 *
 * EMPAT HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * ---------------------------------------------------
 * 1. DOKUMEN BC MENANG, TAPI BARANGNYA HARUS IKUT PINDAH. Contoh pemilik
 *    produk: dipesan 15, dipicking 10, di SJ hanya 8. Yang berangkat 8, dan
 *    2 pail yang sudah turun dari rak HARUS kembali ke stok. Tanpa itu, stok
 *    tercatat berkurang 10 sementara yang pergi hanya 8 — dan selisihnya
 *    baru ketahuan saat opname.
 * 2. SJ TIDAK BOLEH LEBIH BANYAK DARIPADA YANG DIPICKING. Mengirim 12
 *    padahal 10 yang diambil mustahil secara fisik; mengikutinya membuat
 *    catatan stok berbohong.
 * 3. TRUK TIDAK MENUNGGU WHATSAPP. Kegagalan pesan tidak boleh membatalkan
 *    pengiriman, tetapi juga tidak boleh diam.
 * 4. TAUTAN SUPIR ADALAH KUNCI. Ia publik tanpa login, jadi token yang salah
 *    harus 404 — bukan menampilkan kiriman orang lain.
 */
class ShipmentTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $karawang;

    private Warehouse $pekanbaru;

    private Location $rak;

    private Product $produk;

    private Customer $customer;

    private PaymentTerm $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->karawang = Warehouse::factory()->withProduction()->create(['code' => 'WH-01', 'name' => 'Karawang']);
        $this->pekanbaru = Warehouse::factory()->create(['code' => 'WH-02', 'name' => 'Pekanbaru']);

        $this->rak = Location::factory()->create([
            'warehouse_id' => $this->karawang->id, 'code' => 'A-01-01', 'is_active' => true,
        ]);

        $this->produk = Product::factory()->create([
            'sku' => 'ID1-F0017X002820', 'name' => 'Bocor Guard 2 Base 20Kg', 'uom' => 'PAIL', 'is_active' => true,
        ]);
        $this->customer = Customer::factory()->create(['code' => 'IDR13302', 'is_active' => true]);
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
        $this->actingAs($user);

        return $user;
    }

    /**
     * Pesanan yang sudah SELESAI DIPICKING sungguhan.
     *
     * Dibangun lewat alur aslinya — alokasi FIFO, daftar picking, tandai,
     * Siap Loading — bukan dengan menulis baris langsung. Keadaan buatan
     * tangan bisa saja tidak pernah bisa terjadi, dan yang diuji di sini
     * justru lanjutan dari keadaan yang dihasilkan picking.
     */
    private function pesananSudahDipicking(int $dipesan, int $dipicking, ?InventoryStock $stok = null): SalesOrder
    {
        $stok = $stok ?? InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->karawang->id,
            'location_id' => $this->rak->id,
            'batch_no' => 'BT-2601',
            'production_date' => '2026-01-15',
            'expiry_date' => '2028-01-15',
            'qty_available' => 100,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);

        $order = SalesOrder::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->karawang->id,
            'payment_term_id' => $this->term->id,
            'status' => SalesOrder::STATUS_APPROVED,
            'bc_so_number' => 'SO260903',
            'submitted_at' => now()->subDay(),
            'approved_at' => now()->subDay(),
        ]);

        $detail = SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => $dipesan,
            'qty_approved' => $dipicking,
            'outstanding_qty' => max(0, $dipesan - $dipicking),
        ]);

        DB::transaction(fn () => app(FifoAllocator::class)->allocate($detail, $dipicking, null));

        $daftar = PickingList::factory()->create([
            'warehouse_id' => $this->karawang->id,
            'status' => PickingList::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $order->forceFill(['picking_list_id' => $daftar->id])->save();

        // Menjalankan efek Siap Loading pada stok: cadangan berakhir dan
        // barangnya turun dari rak.
        $stok->refresh();
        $stok->forceFill([
            'qty_allocated' => max(0, $stok->qty_allocated - $dipicking),
        ])->save();
        $detail->allocations()->delete();

        PickingListItem::factory()->create([
            'picking_list_id' => $daftar->id,
            'sales_order_id' => $order->id,
            'sales_order_detail_id' => $detail->id,
            'product_id' => $this->produk->id,
            'inventory_stock_id' => $stok->id,
            'location_id' => $this->rak->id,
            'batch_no' => 'BT-2601',
            'production_date' => '2026-01-15',
            'qty_to_pick' => $dipicking,
            'qty_picked' => $dipicking,
            'status' => PickingListItem::STATUS_PICKED,
        ]);

        $order->forceFill([
            'status' => SalesOrder::STATUS_READY_TO_SHIP,
            'picking_completed_at' => now(),
        ])->save();

        return $order->refresh();
    }

    private function suratJalan(SalesOrder $order, int $qty): DeliveryNote
    {
        $note = DeliveryNote::factory()->create([
            'document_no' => '206215',
            'bc_so_number' => $order->bc_so_number,
            'sales_order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'customer_code' => 'IDR13302',
            'warehouse_id' => $order->warehouse_id,
        ]);

        DeliveryNoteLine::factory()->create([
            'delivery_note_id' => $note->id,
            'sku' => $this->produk->sku,
            'product_id' => $this->produk->id,
            'qty' => $qty,
        ]);

        return $note->refresh();
    }

    private function kirim(DeliveryNote $note, array $ubah = [])
    {
        return $this->post(route('wms.delivery.ship', $note), array_merge([
            'driver_name' => 'Budi Santoso',
            'driver_phone' => '081234567890',
            'vehicle_plate' => 'B 1234 XYZ',
        ], $ubah));
    }

    /* ------------------------------------------------------- SKU berbeda */

    /**
     * Barang lain yang juga ada stoknya di rak yang sama.
     *
     * Sengaja PUNYA STOK: kalau stoknya kosong, blokir yang diuji bisa lolos
     * karena alasan lain (stok tidak cukup menutupi) dan test-nya hijau untuk
     * sebab yang salah.
     */
    private function produkLain(): Product
    {
        $lain = Product::factory()->create([
            'sku' => 'ID1-F0017X002020', 'name' => 'Bocor Guard 5Kg', 'uom' => 'PAIL', 'is_active' => true,
        ]);

        InventoryStock::factory()->create([
            'product_id' => $lain->id,
            'warehouse_id' => $this->karawang->id,
            'location_id' => $this->rak->id,
            'batch_no' => 'BT-2699',
            'production_date' => '2026-02-01',
            'expiry_date' => '2028-02-01',
            'qty_available' => 50,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);

        return $lain;
    }

    /** SJ yang isinya SKU lain sama sekali — bukan selisih qty. */
    private function suratJalanBedaSku(SalesOrder $order, Product $lain, int $qty): DeliveryNote
    {
        $note = $this->suratJalan($order, $qty);
        $note->lines()->delete();

        DeliveryNoteLine::factory()->create([
            'delivery_note_id' => $note->id,
            'sku' => $lain->sku,
            'product_id' => $lain->id,
            'qty' => $qty,
        ]);

        return $note->fresh();
    }

    /**
     * Dilaporkan pemilik produk saat uji coba: pesanan 5Kg, dipicking 5Kg,
     * tetapi SJ menyebut SKU lain dengan qty SAMA.
     *
     * Sebelum diperbaiki, sistem membiarkannya berangkat dan menulis tiga hal
     * salah sekaligus — mengeluarkan barang yang tak pernah diambil,
     * mengembalikan barang yang sudah naik kendaraan, dan meninggalkan
     * pesanan yang outstanding-nya tidak akan pernah tertutup.
     */
    public function test_sku_berbeda_menghentikan_pengiriman(): void
    {
        $order = $this->pesananSudahDipicking(2, 2);
        $note = $this->suratJalanBedaSku($order, $this->produkLain(), 2);

        $this->loginAt($this->karawang);
        $this->kirim($note)->assertSessionHas('error');

        $this->assertSame(
            SalesOrder::STATUS_READY_TO_SHIP,
            $order->fresh()->status,
            'Pesanan tidak boleh berangkat sebelum SKU-nya diputuskan.'
        );

        // Yang paling penting: TIDAK SATU BARIS STOK PUN bergerak. Blokir
        // yang terjadi setelah stok tersentuh hanya memindahkan kerusakan.
        $this->assertSame(0, StockMovement::query()->whereIn('movement_type', [
            StockMovement::TYPE_OUT, StockMovement::TYPE_IN,
        ])->count());
    }

    public function test_selisih_qty_pada_sku_yang_sama_tetap_boleh_jalan(): void
    {
        // Penjagaan supaya blokir SKU tidak ikut menahan kasus yang memang
        // sudah diputuskan pemilik produk: qty berbeda = dokumen BC menang.
        $order = $this->pesananSudahDipicking(15, 10);
        $note = $this->suratJalan($order, 8);

        $this->loginAt($this->karawang);
        $this->kirim($note)->assertSessionHas('warning');

        $this->assertSame(SalesOrder::STATUS_SHIPPING, $order->fresh()->status);
    }

    public function test_substitusi_yang_dikonfirmasi_boleh_berangkat(): void
    {
        $lain = $this->produkLain();
        $order = $this->pesananSudahDipicking(2, 2);
        $note = $this->suratJalanBedaSku($order, $lain, 2);

        $this->loginAt($this->karawang);

        $this->post(route('wms.delivery.substitution', $note), [
            'substitution_reason' => 'Pelanggan setuju diganti ukuran 5Kg karena 20Kg kosong.',
        ])->assertSessionHas('warning');

        $this->kirim($note->fresh())->assertSessionHas('warning');

        $this->assertSame(SalesOrder::STATUS_SHIPPING, $order->fresh()->status);

        // Barang pengganti keluar, barang yang semula dipicking kembali ke rak.
        $this->assertSame(-2, (int) StockMovement::query()
            ->where('product_id', $lain->id)
            ->where('movement_type', StockMovement::TYPE_OUT)
            ->sum('qty_change'));

        $this->assertSame(2, (int) StockMovement::query()
            ->where('product_id', $this->produk->id)
            ->where('movement_type', StockMovement::TYPE_IN)
            ->sum('qty_change'));
    }

    public function test_baris_yang_digantikan_ditutup_bukan_dibiarkan_outstanding(): void
    {
        $order = $this->pesananSudahDipicking(2, 2);
        $note = $this->suratJalanBedaSku($order, $this->produkLain(), 2);

        $this->loginAt($this->karawang);
        $this->post(route('wms.delivery.substitution', $note), [
            'substitution_reason' => 'Pelanggan setuju diganti ukuran; sudah dikonfirmasi Sales.',
        ]);
        $this->kirim($note->fresh());

        $detail = SalesOrderDetail::query()->where('sales_order_id', $order->id)->firstOrFail();

        // Membiarkannya outstanding berarti pesanan terlihat terutang
        // selamanya, dan Sales menagih barang yang sudah diterima pelanggan.
        $this->assertSame(0, (int) $detail->outstanding_qty);
        $this->assertStringContainsString('Digantikan SKU lain', (string) $detail->substitution_note);
    }

    public function test_substitusi_dicatat_sebagai_penggantian_bukan_temuan_stok(): void
    {
        $lain = $this->produkLain();
        $order = $this->pesananSudahDipicking(2, 2);
        $note = $this->suratJalanBedaSku($order, $lain, 2);

        $this->loginAt($this->karawang);
        $this->post(route('wms.delivery.substitution', $note), [
            'substitution_reason' => 'Pelanggan setuju diganti ukuran; sudah dikonfirmasi Sales.',
        ]);
        $this->kirim($note->fresh());

        $catatan = (string) StockMovement::query()
            ->where('product_id', $lain->id)
            ->where('movement_type', StockMovement::TYPE_OUT)
            ->value('notes');

        // Kalimatnya menentukan ke mana orang mencari nanti. Menyebutnya
        // selisih stok akan mengirim opname berikutnya mengejar selisih yang
        // tidak pernah ada.
        $this->assertStringContainsString('PENGGANTI', $catatan);
        $this->assertStringNotContainsString('opname', $catatan);
    }

    public function test_konfirmasi_tanpa_alasan_ditolak(): void
    {
        $order = $this->pesananSudahDipicking(2, 2);
        $note = $this->suratJalanBedaSku($order, $this->produkLain(), 2);

        $this->loginAt($this->karawang);
        $this->post(route('wms.delivery.substitution', $note), ['substitution_reason' => 'salah'])
            ->assertSessionHasErrors('substitution_reason');

        $this->assertNull($note->fresh()->substitution_confirmed_at);
    }

    public function test_konfirmasi_ditolak_bila_sku_nya_sebenarnya_cocok(): void
    {
        // Pintu ini hanya untuk SKU berbeda. Membiarkannya dipakai pada
        // dokumen biasa berarti ia jadi tombol "lewati pemeriksaan".
        $order = $this->pesananSudahDipicking(10, 10);
        $note = $this->suratJalan($order, 8);

        $this->loginAt($this->karawang);
        $this->post(route('wms.delivery.substitution', $note), [
            'substitution_reason' => 'Mencoba melewati pemeriksaan tanpa sebab.',
        ])->assertSessionHas('error');

        $this->assertNull($note->fresh()->substitution_confirmed_at);
    }

    public function test_layar_surat_jalan_menyebut_sku_berbeda_bukan_selisih_stok(): void
    {
        $order = $this->pesananSudahDipicking(2, 2);
        $note = $this->suratJalanBedaSku($order, $this->produkLain(), 2);

        $this->loginAt($this->karawang);

        $this->get(route('wms.delivery.show', $note))
            ->assertOk()
            ->assertSee('Pengiriman Ditahan')
            ->assertSee('tidak pernah dipicking')
            // Formulir supir TIDAK boleh ikut tergambar: selama ia terlihat,
            // orang mengisinya dulu lalu bertanya belakangan.
            ->assertDontSee('Nyatakan Berangkat');
    }

    /* --------------------------------------------------------- Qty dari BC */

    public function test_qty_surat_jalan_yang_dipakai_bukan_qty_picking(): void
    {
        // Contoh persis dari pemilik produk: dipesan 15, dipicking 10,
        // di SJ hanya 8.
        $order = $this->pesananSudahDipicking(15, 10);
        $note = $this->suratJalan($order, 8);

        $this->loginAt($this->karawang);
        $this->kirim($note)->assertRedirect();

        $detail = $order->details()->first()->fresh();

        $this->assertSame(8, $detail->qty_shipped, 'Yang berangkat adalah qty dokumen BC.');
        $this->assertSame(7, $detail->outstanding_qty, 'Outstanding = dipesan 15 dikurangi terkirim 8.');
    }

    public function test_kelebihan_picking_dikembalikan_ke_rak(): void
    {
        $stok = InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->karawang->id,
            'location_id' => $this->rak->id,
            'batch_no' => 'BT-2601',
            'production_date' => '2026-01-15',
            'expiry_date' => '2028-01-15',
            'qty_available' => 100,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);

        $order = $this->pesananSudahDipicking(15, 10, $stok);
        $note = $this->suratJalan($order, 8);

        // Sesudah picking: 10 sudah turun dari rak, sisa tercatat 90.
        $this->assertSame(90, $stok->fresh()->qty_available);

        $this->loginAt($this->karawang);
        $this->kirim($note)->assertRedirect();

        // Yang berangkat hanya 8, jadi 2 pail kembali ke raknya. Tanpa ini,
        // stok tercatat berkurang 10 sementara yang pergi hanya 8.
        $this->assertSame(92, $stok->fresh()->qty_available);

        $masuk = StockMovement::where('movement_type', StockMovement::TYPE_IN)->sum('qty_change');
        $this->assertSame(2, (int) $masuk);
    }

    public function test_pengembalian_diberi_peringatan_bukan_pesan_sukses(): void
    {
        $order = $this->pesananSudahDipicking(15, 10);
        $note = $this->suratJalan($order, 8);

        $this->loginAt($this->karawang);

        // Barang fisik baru saja berpindah kembali ke rak; yang menaruhnya
        // di dock perlu tahu bahwa ia TIDAK boleh dinaikkan ke kendaraan.
        $this->kirim($note)->assertSessionHas('warning');
    }

    public function test_surat_jalan_lebih_banyak_dari_picking_adalah_temuan_stok_kurang(): void
    {
        // Keputusan pemilik produk, mengoreksi rancangan awal yang menolak
        // kasus ini: dokumen BC adalah kebenaran yang disetujui. Kalau SJ
        // menyebut 12 keluar sementara yang tercatat dipicking 10, yang
        // keliru bukan dokumennya melainkan angka stok kami.
        $stok = InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->karawang->id,
            'location_id' => $this->rak->id,
            'batch_no' => 'BT-2601',
            'production_date' => '2026-01-15',
            'expiry_date' => '2028-01-15',
            'qty_available' => 100,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);

        $order = $this->pesananSudahDipicking(15, 10, $stok);
        $note = $this->suratJalan($order, 12);

        // Sesudah picking: 10 turun dari rak, tersisa 90.
        $this->assertSame(90, $stok->fresh()->qty_available);

        $this->loginAt($this->karawang);
        $this->kirim($note)->assertRedirect();

        $note->refresh();
        $order->refresh();

        $this->assertSame(DeliveryNote::STATUS_SHIPPED, $note->status);
        $this->assertSame(SalesOrder::STATUS_SHIPPING, $order->status);

        // Selisih 2 ikut keluar: isi rak sebenarnya memang lebih sedikit
        // daripada angka di sistem.
        $this->assertSame(88, $stok->fresh()->qty_available);
        $this->assertSame(12, $order->details()->first()->qty_shipped);
        $this->assertSame(3, $order->details()->first()->outstanding_qty);
    }

    public function test_temuan_stok_kurang_diberi_peringatan_dan_jejak_ledger(): void
    {
        $order = $this->pesananSudahDipicking(15, 10);
        $note = $this->suratJalan($order, 12);

        $this->loginAt($this->karawang);

        // Ini justru temuan paling berharga dari seluruh pencocokan;
        // menyembunyikannya di balik kata "berhasil" membuat opname
        // berikutnya menemukan selisih yang tidak bisa dilacak asalnya.
        $this->kirim($note)->assertSessionHas('warning');

        $mutasi = StockMovement::where('movement_type', StockMovement::TYPE_OUT)
            ->latest('id')->first();

        $this->assertSame(-2, $mutasi->qty_change);
        $this->assertStringContainsString('lebih banyak daripada yang tercatat dipicking', $mutasi->notes);
        $this->assertStringContainsString('opname', $mutasi->notes);
    }

    public function test_kekurangan_yang_stoknya_tidak_cukup_dilaporkan_bukan_dipaksakan(): void
    {
        // Stok tercatat pun tidak menutupi kekurangannya. TIDAK dipaksakan:
        // inventory_stocks punya CHECK (qty_available >= 0), jadi memaksanya
        // bukan menghasilkan angka minus melainkan membatalkan seluruh
        // transaksi dengan galat constraint mentah.
        $stok = InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->karawang->id,
            'location_id' => $this->rak->id,
            'batch_no' => 'BT-2601',
            'production_date' => '2026-01-15',
            'expiry_date' => '2028-01-15',
            'qty_available' => 11,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);

        $order = $this->pesananSudahDipicking(20, 10, $stok);
        $note = $this->suratJalan($order, 15);

        $this->loginAt($this->karawang);
        $this->kirim($note)->assertSessionHas('warning');

        // Sisa 1 setelah picking 10; kekurangan 5 hanya bisa ditutup 1.
        $this->assertSame(0, $stok->fresh()->qty_available);
        $this->assertSame(DeliveryNote::STATUS_SHIPPED, $note->fresh()->status);
        $this->assertSame(15, $order->details()->first()->qty_shipped, 'Dokumen BC tetap yang berlaku.');
    }

    public function test_qty_sama_persis_tidak_mengembalikan_apa_pun(): void
    {
        $order = $this->pesananSudahDipicking(10, 10);
        $note = $this->suratJalan($order, 10);

        $this->loginAt($this->karawang);
        $this->kirim($note)->assertSessionHas('success');

        $this->assertSame(0, StockMovement::where('movement_type', StockMovement::TYPE_IN)->count());
    }

    /* ------------------------------------------------------------- Status */

    public function test_pengiriman_mengubah_status_pesanan_dan_dokumen(): void
    {
        $order = $this->pesananSudahDipicking(10, 10);
        $note = $this->suratJalan($order, 10);

        $this->loginAt($this->karawang);
        $this->kirim($note);

        $note->refresh();
        $order->refresh();

        $this->assertSame(DeliveryNote::STATUS_SHIPPED, $note->status);
        $this->assertSame(SalesOrder::STATUS_SHIPPING, $order->status);
        $this->assertNotNull($order->shipped_at, 'Argo SLA mulai berjalan di sini.');
        $this->assertNotNull($note->epod_token);
        $this->assertSame('6281234567890', $note->driver_phone, 'Nomor disimpan dalam bentuk kirim WhatsApp.');
    }

    /**
     * Ditemukan saat uji coba: halaman "Nyatakan Berangkat" ada, rutenya ada,
     * tapi tidak ada satu pun tautan menuju ke sana dari daftar. Fiturnya
     * secara efektif tidak ada bagi pengguna.
     */
    public function test_daftar_surat_jalan_menautkan_ke_halaman_pengiriman(): void
    {
        $order = $this->pesananSudahDipicking(10, 10);
        $note = $this->suratJalan($order, 10);

        $this->loginAt($this->karawang);

        $this->get(route('wms.delivery.index'))
            ->assertOk()
            ->assertSee(route('wms.delivery.show', $note), false);
    }

    public function test_dokumen_tanpa_pesanan_tidak_bisa_dikirim(): void
    {
        $note = DeliveryNote::factory()->create([
            'document_no' => '206299',
            'warehouse_id' => $this->karawang->id,
            'sales_order_id' => null,
        ]);
        DeliveryNoteLine::factory()->create(['delivery_note_id' => $note->id, 'qty' => 5]);

        $this->loginAt($this->karawang);
        $this->kirim($note)->assertSessionHas('error');
    }

    public function test_dokumen_yang_sudah_berangkat_tidak_bisa_dikirim_lagi(): void
    {
        // Sengaja memakai kasus berselisih: pengiriman kedua yang lolos akan
        // MENGEMBALIKAN 2 pail lagi ke rak, padahal barangnya cuma dua. Itu
        // kerusakan yang tidak terlihat dari status dokumen saja.
        $order = $this->pesananSudahDipicking(15, 10);
        $note = $this->suratJalan($order, 8);

        $this->loginAt($this->karawang);
        $this->kirim($note);
        $this->kirim($note->fresh())->assertSessionHas('error');

        $this->assertSame(
            2,
            (int) StockMovement::where('movement_type', StockMovement::TYPE_IN)->sum('qty_change'),
            'Pengiriman kedua tidak boleh mengembalikan barang untuk kedua kalinya.'
        );
    }

    public function test_surat_jalan_gudang_lain_ditolak_403(): void
    {
        $note = DeliveryNote::factory()->create([
            'document_no' => '206288',
            'warehouse_id' => $this->pekanbaru->id,
        ]);

        $this->loginAt($this->karawang);

        // 403 tanpa bergantung pada isian yang lengkap: batasnya ditegakkan
        // di authorize(), yang berjalan sebelum validasi.
        $this->post(route('wms.delivery.ship', $note), [])->assertForbidden();
        $this->get(route('wms.delivery.show', $note))->assertForbidden();
    }

    /* -------------------------------------------------------- Nomor supir */

    public function test_nomor_supir_yang_tidak_wajar_ditolak(): void
    {
        $order = $this->pesananSudahDipicking(10, 10);
        $note = $this->suratJalan($order, 10);

        $this->loginAt($this->karawang);

        // Salah ketik nomor gagalnya DIAM: pesan "terkirim" ke nomor orang
        // lain, dan yang menemukan masalahnya adalah Logistik keesokan
        // harinya. Pemeriksaan bentuk ini satu-satunya jaring yang ada.
        $this->kirim($note, ['driver_phone' => '0812345'])
            ->assertSessionHasErrors('driver_phone');

        $this->assertSame(DeliveryNote::STATUS_IMPORTED, $note->fresh()->status);
    }

    public function test_dua_nomor_sekaligus_ditolak(): void
    {
        $order = $this->pesananSudahDipicking(10, 10);
        $note = $this->suratJalan($order, 10);

        $this->loginAt($this->karawang);

        // Pesan hanya bisa dikirim ke satu tujuan, dan menebak mana yang
        // dimaksud lebih buruk daripada menolaknya.
        $this->kirim($note, ['driver_phone' => '081234567890/081298765432'])
            ->assertSessionHasErrors('driver_phone');
    }

    /* ---------------------------------------------------------- WhatsApp */

    public function test_pesan_ke_supir_diantrekan_bukan_dikirim_saat_tombol_ditekan(): void
    {
        Queue::fake();

        $order = $this->pesananSudahDipicking(10, 10);
        $note = $this->suratJalan($order, 10);

        $this->loginAt($this->karawang);
        $this->kirim($note);

        // Panggilan ke penyedia pihak ketiga bisa menggantung; menjalankannya
        // di dalam permintaan HTTP membuat tombol Kirim seolah rusak padahal
        // pengirimannya sudah tercatat.
        Queue::assertPushed(SendDeliveryNotification::class);
    }

    public function test_kegagalan_whatsapp_tidak_membatalkan_pengiriman(): void
    {
        // Queue::fake() DIPERLUKAN di sini, dan alasannya bukan kerapian:
        // antrean test memakai driver `sync`, sehingga job berjalan di dalam
        // permintaan HTTP dan kegagalannya ikut mengulang beserta jeda
        // backoff 30 detik. Job-nya dijalankan sendiri di bawah, sekali.
        Queue::fake();

        $order = $this->pesananSudahDipicking(10, 10);
        $note = $this->suratJalan($order, 10);

        $this->swap(WhatsAppSender::class, new class implements WhatsAppSender
        {
            public function send(string $phone, string $message): DispatchResult
            {
                return DispatchResult::failed('Nomor tidak terdaftar di WhatsApp.');
            }
        });

        $this->loginAt($this->karawang);
        $this->kirim($note)->assertRedirect();

        try {
            (new SendDeliveryNotification($note->id))->handle(app(WhatsAppSender::class));
        } catch (\RuntimeException) {
            // Dilempar supaya antrean mencoba lagi; statusnya sudah tersimpan.
        }

        $note->refresh();

        // Truk tetap berangkat — keputusan pemilik produk. Yang gagal hanya
        // PESANNYA, dan kegagalannya harus terlihat.
        $this->assertSame(DeliveryNote::STATUS_SHIPPED, $note->status);
        $this->assertSame(DeliveryNote::NOTIFY_FAILED, $note->notify_status);
        $this->assertStringContainsString('tidak terdaftar', $note->notify_error);
    }

    public function test_mode_manual_bukan_kegagalan(): void
    {
        $order = $this->pesananSudahDipicking(10, 10);
        $note = $this->suratJalan($order, 10);

        $this->loginAt($this->karawang);
        $this->kirim($note);

        (new SendDeliveryNotification($note->id))->handle(app(WhatsAppSender::class));

        // Bawaan sistem adalah mode manual. "Belum terkirim" di situ adalah
        // cara kerja normal yang menunggu satu ketukan manusia — bukan
        // sesuatu yang rusak. Menyamakannya dengan gagal membuat layar penuh
        // peringatan merah pada hari yang berjalan normal.
        $this->assertSame(DeliveryNote::NOTIFY_MANUAL, $note->fresh()->notify_status);
    }

    public function test_pesan_tidak_dikirim_dua_kali(): void
    {
        // Tanpa Queue::fake(), driver `sync` sudah menjalankan job sekali di
        // dalam permintaan HTTP — dan test ini akan lulus karena alasan yang
        // salah, bukan karena penjagaan "sudah terkirim, jangan ulang".
        Queue::fake();

        $order = $this->pesananSudahDipicking(10, 10);
        $note = $this->suratJalan($order, 10);

        $this->swap(WhatsAppSender::class, new class implements WhatsAppSender
        {
            public function send(string $phone, string $message): DispatchResult
            {
                return DispatchResult::sent();
            }
        });

        $this->loginAt($this->karawang);
        $this->kirim($note);

        (new SendDeliveryNotification($note->id))->handle(app(WhatsAppSender::class));
        (new SendDeliveryNotification($note->id))->handle(app(WhatsAppSender::class));

        // Antrean bisa menjalankan ulang job setelah gangguan, dan supir yang
        // menerima pesan yang sama tiga kali akan berhenti membacanya.
        $this->assertSame(1, $note->fresh()->notify_attempts);
    }

    public function test_pesan_memuat_tautan_konfirmasi(): void
    {
        $order = $this->pesananSudahDipicking(10, 10);
        $note = $this->suratJalan($order, 10);

        $this->loginAt($this->karawang);
        $this->kirim($note);

        $note->refresh();

        $this->assertStringContainsString($note->epod_token, $note->pesanUntukSupir());
        $this->assertStringContainsString('206215', $note->pesanUntukSupir());
    }

    /* -------------------------------------------------------------- E-POD */

    public function test_supir_membuka_tautan_tanpa_login(): void
    {
        $order = $this->pesananSudahDipicking(10, 10);
        $note = $this->suratJalan($order, 10);

        $this->loginAt($this->karawang);
        $this->kirim($note);

        $token = $note->fresh()->epod_token;

        // Keluar dari sesi: supir tidak punya akun dan tidak akan pernah punya.
        auth()->logout();
        $this->flushSession();

        $this->get(route('epod.show', $token))
            ->assertOk()
            ->assertSee('206215')
            ->assertSee('Barang Sudah Sampai');
    }

    public function test_token_yang_tidak_dikenal_dijawab_404(): void
    {
        // Halaman ini terbuka ke internet. Token yang tidak dikenal dijawab
        // 404 polos — tanpa menyebut apa pun tentang dokumen yang ada.
        $this->get(route('epod.show', Str::random(48)))->assertNotFound();
    }

    public function test_dokumen_yang_belum_berangkat_tidak_bisa_dibuka_supir(): void
    {
        $order = $this->pesananSudahDipicking(10, 10);
        $note = $this->suratJalan($order, 10);

        $note->forceFill(['epod_token' => Str::random(48)])->save();

        // Token ada, tetapi barangnya belum dinyatakan berangkat. Dijawab
        // sama dengan token yang tidak dikenal.
        $this->get(route('epod.show', $note->epod_token))->assertNotFound();
    }

    public function test_konfirmasi_supir_menandai_sampai_dan_menunggu_verifikasi(): void
    {
        $order = $this->pesananSudahDipicking(10, 10);
        $note = $this->suratJalan($order, 10);

        $this->loginAt($this->karawang);
        $this->kirim($note);

        $token = $note->fresh()->epod_token;

        auth()->logout();
        $this->flushSession();

        $this->post(route('epod.confirm', $token), ['received_by_name' => 'Ibu Sari'])
            ->assertRedirect();

        $note->refresh();
        $order->refresh();

        $this->assertSame(DeliveryNote::STATUS_DELIVERED, $note->status);
        $this->assertSame('Ibu Sari', $note->received_by_name);
        $this->assertNotNull($note->delivered_at);

        // Sampai BUKAN selesai: bukti Surat Jalan bertanda tangan masih
        // harus diunggah dan diverifikasi (F-OUT-05, tahap 5).
        $this->assertSame(SalesOrder::STATUS_PROOF_UPLOADED, $order->status);
        $this->assertNotNull($order->delivered_at);
    }

    public function test_konfirmasi_kedua_ditolak(): void
    {
        $order = $this->pesananSudahDipicking(10, 10);
        $note = $this->suratJalan($order, 10);

        $this->loginAt($this->karawang);
        $this->kirim($note);

        $token = $note->fresh()->epod_token;

        auth()->logout();
        $this->flushSession();

        $this->post(route('epod.confirm', $token));
        $waktuPertama = $note->fresh()->delivered_at;

        $this->travel(5)->minutes();
        $this->post(route('epod.confirm', $token))->assertSessionHas('error');

        // Menerima konfirmasi kedua diam-diam akan menggeser waktu sampainya.
        $this->assertEquals($waktuPertama, $note->fresh()->delivered_at);
    }

    public function test_nama_penerima_boleh_dikosongkan(): void
    {
        $order = $this->pesananSudahDipicking(10, 10);
        $note = $this->suratJalan($order, 10);

        $this->loginAt($this->karawang);
        $this->kirim($note);

        $token = $note->fresh()->epod_token;

        auth()->logout();
        $this->flushSession();

        // Supir sering tidak sempat menanyakan nama penerima; menahan
        // konfirmasi karenanya berarti pengiriman yang sudah sampai tidak
        // pernah tercatat sampai.
        $this->post(route('epod.confirm', $token), [])->assertRedirect();

        $this->assertSame(DeliveryNote::STATUS_DELIVERED, $note->fresh()->status);
    }
}
