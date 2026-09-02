<?php

namespace Tests\Feature\Wms;

use App\Models\Customer;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Transfer stok antar gudang — PRD F-INV-05.
 *
 * TIGA HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * --------------------------------------------------
 * 1. BARANG DI JALAN TIDAK BOLEH MILIK SIAPA PUN. Setelah dikirim, qty sudah
 *    hilang dari gudang asal tetapi belum ada di gudang tujuan. Kalau salah
 *    satunya keliru, angka stok totalnya bertambah atau berkurang sendiri.
 * 2. UMUR BARANG TIDAK BOLEH LAHIR KEMBALI. batch_no, production_date, dan
 *    expiry_date harus sampai apa adanya. Kalau dihitung ulang, FIFO di
 *    gudang tujuan menganggap barang lama sebagai barang baru — dan penarikan
 *    stok yang mendekati kedaluwarsa kembali ke Karawang jadi mustahil.
 * 3. STATUS IKUT PINDAH. Barang DDP yang ditarik ke Karawang tidak boleh
 *    berubah jadi layak jual hanya karena berganti rak.
 */
class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $karawang;

    private Warehouse $pekanbaru;

    private Location $rakKarawang;

    private Location $rakPekanbaru;

    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->karawang = Warehouse::factory()->withProduction()->create(['code' => 'WH-01', 'name' => 'Karawang']);
        $this->pekanbaru = Warehouse::factory()->create(['code' => 'WH-02', 'name' => 'Pekanbaru']);

        $this->rakKarawang = Location::factory()->create([
            'warehouse_id' => $this->karawang->id, 'code' => 'A-01-01', 'is_active' => true,
        ]);
        $this->rakPekanbaru = Location::factory()->create([
            'warehouse_id' => $this->pekanbaru->id, 'code' => 'P-05-02', 'is_active' => true,
        ]);

        $this->produk = Product::factory()->create(['sku' => 'APKO-001', 'uom' => 'PAIL', 'is_active' => true]);
    }

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

    private function stok(int $qty, array $atribut = []): InventoryStock
    {
        return InventoryStock::factory()->create(array_merge([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->karawang->id,
            'location_id' => $this->rakKarawang->id,
            'batch_no' => 'BT-2601',
            'production_date' => '2026-01-15',
            'expiry_date' => '2028-01-15',
            'qty_available' => $qty,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ], $atribut));
    }

    /** Mengirim $qty dari satu baris stok Karawang ke Pekanbaru. */
    private function kirim(InventoryStock $stok, int $qty): StockTransfer
    {
        $this->post(route('wms.transfers.store'), [
            'to_warehouse_id' => $this->pekanbaru->id,
            'item' => [['stock_id' => $stok->id, 'qty' => $qty]],
        ]);

        return StockTransfer::latest('id')->firstOrFail();
    }

    /* ------------------------------------------------------------- Kirim */

    public function test_pengiriman_mengurangi_stok_gudang_asal(): void
    {
        $this->loginAt($this->karawang);
        $stok = $this->stok(100);

        $transfer = $this->kirim($stok, 40);

        $this->assertSame(60, $stok->fresh()->qty_available);
        $this->assertSame(StockTransfer::STATUS_IN_TRANSIT, $transfer->status);
        $this->assertSame(40, $transfer->details()->sum('qty_shipped'));
    }

    /**
     * Selama di jalan, barangnya BELUM ada di gudang tujuan.
     *
     * Ini yang membedakan alur dua langkah dari satu langkah, dan yang
     * mencegah Sales Pekanbaru menjual barang yang masih di atas truk.
     */
    public function test_stok_belum_muncul_di_gudang_tujuan_selama_di_jalan(): void
    {
        $this->loginAt($this->karawang);
        $this->kirim($this->stok(100), 40);

        $this->assertSame(0, (int) InventoryStock::where('warehouse_id', $this->pekanbaru->id)->sum('qty_available'));
    }

    public function test_pengiriman_mencatat_mutasi_transfer_out(): void
    {
        $this->loginAt($this->karawang);
        $transfer = $this->kirim($this->stok(100), 40);

        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => StockMovement::TYPE_TRANSFER_OUT,
            'warehouse_id' => $this->karawang->id,
            'reference_type' => StockMovement::REF_STOCK_TRANSFER,
            'reference_id' => $transfer->id,
            'qty_change' => -40,
            'qty_before' => 100,
            'qty_after' => 60,
        ]);
    }

    public function test_kirim_melebihi_stok_tersedia_ditolak(): void
    {
        $this->loginAt($this->karawang);
        $stok = $this->stok(30);

        $this->post(route('wms.transfers.store'), [
            'to_warehouse_id' => $this->pekanbaru->id,
            'item' => [['stock_id' => $stok->id, 'qty' => 50]],
        ])->assertSessionHas('error');

        $this->assertSame(30, $stok->fresh()->qty_available);
        $this->assertDatabaseCount('stock_transfers', 0);
    }

    /**
     * Stok yang sudah dijanjikan ke pesanan tidak ikut terkirim.
     *
     * qty_available adalah yang BEBAS; qty_allocated sudah menjadi hak
     * pesanan yang disetujui. Mengirimnya berarti pesanan itu kehilangan
     * barangnya tanpa ada yang tahu.
     */
    public function test_stok_yang_sudah_dialokasikan_tidak_bisa_dikirim(): void
    {
        $this->loginAt($this->karawang);
        $stok = $this->stok(10, ['qty_allocated' => 90]);

        $this->post(route('wms.transfers.store'), [
            'to_warehouse_id' => $this->pekanbaru->id,
            'item' => [['stock_id' => $stok->id, 'qty' => 50]],
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('stock_transfers', 0);
    }

    public function test_transfer_ke_gudang_sendiri_ditolak(): void
    {
        $this->loginAt($this->karawang);
        $stok = $this->stok(100);

        $this->post(route('wms.transfers.store'), [
            'to_warehouse_id' => $this->karawang->id,
            'item' => [['stock_id' => $stok->id, 'qty' => 10]],
        ])->assertSessionHasErrors('to_warehouse_id');

        $this->assertSame(100, $stok->fresh()->qty_available);
    }

    public function test_batch_yang_sama_dipilih_dua_kali_ditolak(): void
    {
        $this->loginAt($this->karawang);
        $stok = $this->stok(100);

        $this->post(route('wms.transfers.store'), [
            'to_warehouse_id' => $this->pekanbaru->id,
            'item' => [
                ['stock_id' => $stok->id, 'qty' => 60],
                ['stock_id' => $stok->id, 'qty' => 60],
            ],
        ])->assertSessionHasErrors('item');

        $this->assertSame(100, $stok->fresh()->qty_available);
    }

    /* ------------------------------------------------------------ Terima */

    public function test_penerimaan_menambah_stok_di_gudang_tujuan(): void
    {
        $this->loginAt($this->karawang);
        $transfer = $this->kirim($this->stok(100), 40);

        $this->loginAt($this->pekanbaru);
        $this->post(route('wms.transfers.receive', $transfer), [
            'baris' => [
                $transfer->details()->value('id') => ['qty' => 40, 'location_code' => 'P-05-02'],
            ],
        ])->assertSessionHasNoErrors();

        $tujuan = InventoryStock::where('warehouse_id', $this->pekanbaru->id)->firstOrFail();

        $this->assertSame(40, $tujuan->qty_available);
        $this->assertSame($this->rakPekanbaru->id, $tujuan->location_id);
        $this->assertSame(StockTransfer::STATUS_RECEIVED, $transfer->fresh()->status);
    }

    /**
     * Inti permintaan pemilik produk: rak di-reset, umur barang TIDAK.
     */
    public function test_batch_dan_tanggal_produksi_ikut_pindah_apa_adanya(): void
    {
        $this->loginAt($this->karawang);
        $transfer = $this->kirim($this->stok(100), 40);

        $this->loginAt($this->pekanbaru);
        $this->post(route('wms.transfers.receive', $transfer), [
            'baris' => [
                $transfer->details()->value('id') => ['qty' => 40, 'location_code' => 'P-05-02'],
            ],
        ]);

        $tujuan = InventoryStock::where('warehouse_id', $this->pekanbaru->id)->firstOrFail();

        $this->assertSame('BT-2601', $tujuan->batch_no);
        $this->assertSame('2026-01-15', $tujuan->production_date->toDateString());
        $this->assertSame('2028-01-15', $tujuan->expiry_date->toDateString());

        // Raknya BERGANTI: penomoran rak tiap gudang berbeda.
        $this->assertNotSame($this->rakKarawang->id, $tujuan->location_id);
    }

    public function test_status_ddp_ikut_pindah_dan_tidak_jadi_layak_jual(): void
    {
        $this->loginAt($this->karawang);
        $stok = $this->stok(50, [
            'status' => InventoryStock::STATUS_DDP,
            'ddp_reason' => InventoryStock::DDP_RETURN_DAMAGED,
        ]);
        $transfer = $this->kirim($stok, 50);

        $this->loginAt($this->pekanbaru);
        $this->post(route('wms.transfers.receive', $transfer), [
            'baris' => [
                $transfer->details()->value('id') => ['qty' => 50, 'location_code' => 'P-05-02'],
            ],
        ]);

        $tujuan = InventoryStock::where('warehouse_id', $this->pekanbaru->id)->firstOrFail();

        $this->assertSame(InventoryStock::STATUS_DDP, $tujuan->status);
        $this->assertSame(InventoryStock::DDP_RETURN_DAMAGED, $tujuan->ddp_reason);
    }

    /** Batch yang sama di rak yang sama digabung, bukan jadi baris kembar. */
    public function test_batch_yang_sama_digabung_ke_baris_yang_sudah_ada(): void
    {
        InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->pekanbaru->id,
            'location_id' => $this->rakPekanbaru->id,
            'batch_no' => 'BT-2601',
            'production_date' => '2026-01-15',
            'expiry_date' => '2028-01-15',
            'qty_available' => 25,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);

        $this->loginAt($this->karawang);
        $transfer = $this->kirim($this->stok(100), 40);

        $this->loginAt($this->pekanbaru);
        $this->post(route('wms.transfers.receive', $transfer), [
            'baris' => [
                $transfer->details()->value('id') => ['qty' => 40, 'location_code' => 'P-05-02'],
            ],
        ]);

        $baris = InventoryStock::where('warehouse_id', $this->pekanbaru->id)->get();

        $this->assertCount(1, $baris);
        $this->assertSame(65, $baris->first()->qty_available);
    }

    public function test_qty_kurang_dicatat_beserta_alasannya(): void
    {
        $this->loginAt($this->karawang);
        $transfer = $this->kirim($this->stok(100), 40);
        $detailId = $transfer->details()->value('id');

        $this->loginAt($this->pekanbaru);
        $this->post(route('wms.transfers.receive', $transfer), [
            'baris' => [
                $detailId => ['qty' => 35, 'location_code' => 'P-05-02', 'reason' => 'Lima pail pecah di perjalanan.'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('stock_transfer_details', [
            'id' => $detailId,
            'qty_shipped' => 40,
            'qty_received' => 35,
            'discrepancy_reason' => 'Lima pail pecah di perjalanan.',
        ]);

        // Hanya yang sampai yang jadi stok. Lima sisanya memang hilang —
        // sudah dikurangi di asal dan tidak pernah ditambahkan di tujuan.
        $this->assertSame(35, (int) InventoryStock::where('warehouse_id', $this->pekanbaru->id)->sum('qty_available'));
    }

    public function test_qty_kurang_tanpa_alasan_ditolak(): void
    {
        $this->loginAt($this->karawang);
        $transfer = $this->kirim($this->stok(100), 40);

        $this->loginAt($this->pekanbaru);
        $this->post(route('wms.transfers.receive', $transfer), [
            'baris' => [
                $transfer->details()->value('id') => ['qty' => 35, 'location_code' => 'P-05-02'],
            ],
        ])->assertSessionHas('error');

        $this->assertSame(StockTransfer::STATUS_IN_TRANSIT, $transfer->fresh()->status);
        $this->assertDatabaseCount('inventory_stocks', 1);
    }

    public function test_qty_diterima_melebihi_yang_dikirim_ditolak(): void
    {
        $this->loginAt($this->karawang);
        $transfer = $this->kirim($this->stok(100), 40);

        $this->loginAt($this->pekanbaru);
        $this->post(route('wms.transfers.receive', $transfer), [
            'baris' => [
                $transfer->details()->value('id') => ['qty' => 45, 'location_code' => 'P-05-02'],
            ],
        ])->assertSessionHas('error');

        $this->assertSame(StockTransfer::STATUS_IN_TRANSIT, $transfer->fresh()->status);
    }

    /** Kode rak gudang ASAL tidak berlaku di gudang tujuan. */
    public function test_rak_milik_gudang_lain_ditolak(): void
    {
        $this->loginAt($this->karawang);
        $transfer = $this->kirim($this->stok(100), 40);

        $this->loginAt($this->pekanbaru);
        $this->post(route('wms.transfers.receive', $transfer), [
            'baris' => [
                $transfer->details()->value('id') => ['qty' => 40, 'location_code' => 'A-01-01'],
            ],
        ])->assertSessionHas('error');

        $this->assertSame(StockTransfer::STATUS_IN_TRANSIT, $transfer->fresh()->status);
    }

    public function test_transfer_yang_sudah_diterima_tidak_bisa_diterima_lagi(): void
    {
        $this->loginAt($this->karawang);
        $transfer = $this->kirim($this->stok(100), 40);
        $detailId = $transfer->details()->value('id');

        $this->loginAt($this->pekanbaru);
        $isian = ['baris' => [$detailId => ['qty' => 40, 'location_code' => 'P-05-02']]];

        $this->post(route('wms.transfers.receive', $transfer), $isian);
        $this->post(route('wms.transfers.receive', $transfer), $isian)->assertSessionHas('error');

        $this->assertSame(40, (int) InventoryStock::where('warehouse_id', $this->pekanbaru->id)->sum('qty_available'));
    }

    /**
     * Kiriman yang sampai langsung menutup pesanan yang menunggu stok.
     *
     * Aturan yang sama dengan Penyesuaian Stok dan Impor Stok Awal. Tanpa
     * ini, transfer antar gudang jadi satu-satunya pintu masuk stok yang
     * TIDAK menyusul pesanan tertunda.
     */
    public function test_kiriman_yang_sampai_mengisi_pesanan_yang_menunggu_stok(): void
    {
        $term = PaymentTerm::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Cash / Tunai', 'days' => 0, 'is_active' => true, 'sort_order' => 1]
        );

        $sales = User::factory()->withRole(Role::SALES)->create(['warehouse_id' => $this->pekanbaru->id]);

        $order = SalesOrder::factory()->submitted()->create([
            'user_id' => $sales->id,
            'customer_id' => Customer::factory()->create(['is_active' => true])->id,
            'warehouse_id' => $this->pekanbaru->id,
            'payment_term_id' => $term->id,
            'status' => SalesOrder::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 30,
            'qty_approved' => 30,
        ]);

        $this->loginAt($this->karawang);
        $transfer = $this->kirim($this->stok(100), 40);

        $this->loginAt($this->pekanbaru);
        $this->post(route('wms.transfers.receive', $transfer), [
            'baris' => [
                $transfer->details()->value('id') => ['qty' => 40, 'location_code' => 'P-05-02'],
            ],
        ]);

        $this->assertSame(30, (int) $order->details()->first()->qty_allocated);
    }

    /* ------------------------------------------------------- Pembatalan */

    public function test_pembatalan_mengembalikan_stok_ke_rak_asal(): void
    {
        $this->loginAt($this->karawang);
        $stok = $this->stok(100);
        $transfer = $this->kirim($stok, 40);

        $this->assertSame(60, $stok->fresh()->qty_available);

        $this->post(route('wms.transfers.cancel', $transfer), [
            'cancellation_reason' => 'Truk batal berangkat sore ini.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(100, $stok->fresh()->qty_available);
        $this->assertSame(StockTransfer::STATUS_CANCELLED, $transfer->fresh()->status);
        $this->assertSame(0, (int) InventoryStock::where('warehouse_id', $this->pekanbaru->id)->sum('qty_available'));
    }

    public function test_transfer_yang_sudah_diterima_tidak_bisa_dibatalkan(): void
    {
        $this->loginAt($this->karawang);
        $stok = $this->stok(100);
        $transfer = $this->kirim($stok, 40);

        $this->loginAt($this->pekanbaru);
        $this->post(route('wms.transfers.receive', $transfer), [
            'baris' => [$transfer->details()->value('id') => ['qty' => 40, 'location_code' => 'P-05-02']],
        ]);

        $this->loginAt($this->karawang);
        $this->post(route('wms.transfers.cancel', $transfer), [
            'cancellation_reason' => 'Percobaan membatalkan yang sudah sampai.',
        ])->assertSessionHas('error');

        $this->assertSame(StockTransfer::STATUS_RECEIVED, $transfer->fresh()->status);
        $this->assertSame(60, $stok->fresh()->qty_available);
    }

    /* ------------------------------------------------- Pembatasan gudang */

    public function test_gudang_tujuan_tidak_bisa_membatalkan(): void
    {
        $this->loginAt($this->karawang);
        $transfer = $this->kirim($this->stok(100), 40);

        $this->loginAt($this->pekanbaru);
        $this->post(route('wms.transfers.cancel', $transfer), [
            'cancellation_reason' => 'Percobaan dari gudang tujuan.',
        ])->assertForbidden();

        $this->assertSame(StockTransfer::STATUS_IN_TRANSIT, $transfer->fresh()->status);
    }

    public function test_gudang_asal_tidak_bisa_menerima_kirimannya_sendiri(): void
    {
        $this->loginAt($this->karawang);
        $transfer = $this->kirim($this->stok(100), 40);

        $this->post(route('wms.transfers.receive', $transfer), [
            'baris' => [$transfer->details()->value('id') => ['qty' => 40, 'location_code' => 'P-05-02']],
        ])->assertForbidden();

        $this->assertSame(StockTransfer::STATUS_IN_TRANSIT, $transfer->fresh()->status);
    }

    /** Dokumen ini milik DUA gudang — keduanya berhak membacanya. */
    public function test_kedua_gudang_yang_terlibat_dapat_melihat_dokumennya(): void
    {
        $this->loginAt($this->karawang);
        $transfer = $this->kirim($this->stok(100), 40);

        $this->get(route('wms.transfers.show', $transfer))->assertOk()->assertSee($transfer->transfer_number);

        $this->loginAt($this->pekanbaru);
        $this->get(route('wms.transfers.show', $transfer))->assertOk()->assertSee($transfer->transfer_number);
    }

    public function test_gudang_yang_tidak_terlibat_tidak_dapat_melihatnya(): void
    {
        $surabaya = Warehouse::factory()->create(['code' => 'WH-03', 'name' => 'Surabaya']);

        $this->loginAt($this->karawang);
        $transfer = $this->kirim($this->stok(100), 40);

        $this->loginAt($surabaya);
        $this->get(route('wms.transfers.show', $transfer))->assertForbidden();
    }

    public function test_daftar_transfer_hanya_memuat_yang_menyangkut_gudang_sendiri(): void
    {
        $surabaya = Warehouse::factory()->create(['code' => 'WH-03', 'name' => 'Surabaya']);

        $this->loginAt($this->karawang);
        $milikKita = $this->kirim($this->stok(100), 40);

        $asing = StockTransfer::factory()->create([
            'from_warehouse_id' => $surabaya->id,
            'to_warehouse_id' => $this->pekanbaru->id,
        ]);

        $this->get(route('wms.transfers.index'))
            ->assertOk()
            ->assertSee($milikKita->transfer_number)
            ->assertDontSee($asing->transfer_number);
    }

    public function test_operator_tidak_boleh_mengirim(): void
    {
        $this->loginAt($this->karawang, Role::WAREHOUSE_OPERATOR);

        $this->get(route('wms.transfers.create'))->assertForbidden();
    }

    /** Akun lintas gudang boleh melihat dan menutup transfer mana pun. */
    public function test_akun_lintas_gudang_dapat_menerima_di_gudang_mana_pun(): void
    {
        $this->loginAt($this->karawang);
        $transfer = $this->kirim($this->stok(100), 40);

        $this->loginAt(null, Role::SUPER_ADMIN);
        $this->post(route('wms.transfers.receive', $transfer), [
            'baris' => [$transfer->details()->value('id') => ['qty' => 40, 'location_code' => 'P-05-02']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(StockTransfer::STATUS_RECEIVED, $transfer->fresh()->status);
    }
}
