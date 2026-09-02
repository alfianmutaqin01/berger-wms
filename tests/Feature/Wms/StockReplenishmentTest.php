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
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Outbound\FifoAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Penambahan stok dan pelengkapan alokasi — Fase 6 tahap 2.
 *
 * Yang paling dijaga:
 *   1. Stok yang bertambah OTOMATIS mengisi pesanan yang tertahan, urut
 *      pesanan terlama, dan hasilnya DILAPORKAN — alokasi diam-diam membuat
 *      Manager tidak tahu ke mana stoknya pergi.
 *   2. Impor Stok Awal IDEMPOTEN: berkas yang sama diimpor dua kali tidak
 *      melipatgandakan stok.
 *   3. Batch dan tanggal produksi tidak pernah boleh kosong.
 */
class StockReplenishmentTest extends TestCase
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

        Storage::fake('local');

        $this->gudang = Warehouse::factory()->create(['code' => 'KRW', 'name' => 'Karawang']);
        $this->lokasi = Location::factory()->create([
            'warehouse_id' => $this->gudang->id,
            'code' => 'A-01-02',
            'is_active' => true,
        ]);
        $this->produk = Product::factory()->create([
            'sku' => 'APKO-001',
            'uom' => 'PAIL',
            'is_active' => true,
            'shelf_life_months' => 30,
        ]);
        $this->customer = Customer::factory()->create(['is_active' => true]);
        $this->term = PaymentTerm::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Cash / Tunai', 'days' => 0, 'is_active' => true, 'sort_order' => 1]
        );
    }

    private function loginAs(string $slug = Role::MANAGER): User
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
        $this->actingAs($user);

        return $user;
    }

    /**
     * Pesanan yang sudah diterima dengan sebagian qty menunggu stok.
     *
     * Dibuat lewat state langsung, bukan lewat layar penerimaan: yang diuji
     * di sini adalah PELENGKAPAN-nya, dan menempuh alur penerimaan hanya
     * membuat kegagalan test ini menunjuk ke modul yang salah.
     */
    private function pesananMenunggu(int $disetujui, ?string $disubmit = null): SalesOrderDetail
    {
        $sales = User::factory()->withRole(Role::SALES)->create(['warehouse_id' => $this->gudang->id]);

        $order = SalesOrder::factory()->submitted()->create([
            'user_id' => $sales->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->gudang->id,
            'payment_term_id' => $this->term->id,
            'status' => SalesOrder::STATUS_APPROVED,
            'submitted_at' => $disubmit ?? now()->subHour(),
            'approved_at' => now(),
        ]);

        return SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => $disetujui,
            'qty_approved' => $disetujui,
        ]);
    }

    private function tambahStok(array $ganti = [])
    {
        return $this->post('/wms/inventory/stocks', array_merge([
            'sku' => 'APKO-001',
            'location_code' => 'A-01-02',
            'batch_no' => 'BT-001',
            'production_date' => now()->subMonths(2)->toDateString(),
            'qty' => 50,
            'reason' => 'Stok opname awal, barang sudah di rak.',
        ], $ganti));
    }

    /* ------------------------------------------------------- Tambah stok */

    public function test_menambah_stok_membuat_baris_baru_dan_entri_ledger(): void
    {
        $this->loginAs();

        $this->tambahStok()->assertSessionHas('success');

        $stok = InventoryStock::firstOrFail();

        $this->assertSame(50, $stok->qty_available);
        $this->assertSame('BT-001', $stok->batch_no);
        $this->assertSame($this->lokasi->id, $stok->location_id);
        // Gudang diturunkan dari lokasi, tidak diminta terpisah.
        $this->assertSame($this->gudang->id, $stok->warehouse_id);

        $gerak = StockMovement::where('movement_type', StockMovement::TYPE_ADJUSTMENT)->firstOrFail();
        $this->assertSame(50, $gerak->qty_change);
        $this->assertSame(0, $gerak->qty_before);
        $this->assertStringContainsString('opname', $gerak->notes);
    }

    /** Tanggal kedaluwarsa dihitung dengan aturan yang sama seperti inbound. */
    public function test_tanggal_kedaluwarsa_dihitung_dari_tanggal_produksi(): void
    {
        $this->loginAs();

        $produksi = now()->subMonths(2)->startOfDay();
        $this->tambahStok(['production_date' => $produksi->toDateString()]);

        $this->assertSame(
            $produksi->copy()->addMonths(30)->toDateString(),
            InventoryStock::firstOrFail()->expiry_date->toDateString()
        );
    }

    /** Batch, rak, dan tanggal produksi yang sama = baris yang sama. */
    public function test_batch_yang_sama_digabung_bukan_dibuat_kembar(): void
    {
        $this->loginAs();

        $this->tambahStok(['qty' => 50]);
        $this->tambahStok(['qty' => 30]);

        $this->assertSame(1, InventoryStock::count());
        $this->assertSame(80, InventoryStock::firstOrFail()->qty_available);
    }

    public function test_logistik_tidak_boleh_menambah_stok(): void
    {
        $this->loginAs(Role::LOGISTICS);

        $this->tambahStok()->assertForbidden();

        $this->assertSame(0, InventoryStock::count());
    }

    public function test_sku_dan_lokasi_tidak_dikenal_ditolak(): void
    {
        $this->loginAs();

        $this->tambahStok(['sku' => 'TIDAK-ADA'])->assertSessionHasErrors('sku');
        $this->tambahStok(['location_code' => 'Z-99-99'])->assertSessionHasErrors('location_code');

        $this->assertSame(0, InventoryStock::count());
    }

    /**
     * Batch bertanggal masa depan selalu tampak paling muda sehingga FIFO
     * tidak akan pernah mengeluarkannya, dan kedaluwarsanya ikut meleset.
     */
    public function test_tanggal_produksi_masa_depan_ditolak(): void
    {
        $this->loginAs();

        $this->tambahStok(['production_date' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors('production_date');
    }

    public function test_alasan_wajib_diisi(): void
    {
        $this->loginAs();

        $this->tambahStok(['reason' => ''])->assertSessionHasErrors('reason');
    }

    /* ------------------------------------------- Pelengkapan alokasi */

    /** Stok baru langsung mengisi pesanan yang menunggu, dan itu DILAPORKAN. */
    public function test_stok_baru_langsung_mengisi_pesanan_yang_menunggu(): void
    {
        $this->loginAs();

        $detail = $this->pesananMenunggu(20);
        $this->assertSame(20, $detail->qty_pending_stock);

        $this->tambahStok(['qty' => 50])
            ->assertSessionHas('warning', fn ($pesan) => str_contains($pesan, '20 unit langsung dialokasikan')
                && str_contains($pesan, $detail->salesOrder->order_number));

        $detail->refresh();

        $this->assertSame(20, $detail->qty_allocated);
        $this->assertSame(0, $detail->qty_pending_stock);

        // 50 masuk, 20 tersedot ke pesanan, 30 bebas.
        $stok = InventoryStock::firstOrFail();
        $this->assertSame(30, $stok->qty_available);
        $this->assertSame(20, $stok->qty_allocated);
    }

    /** Pesanan yang paling lama menunggu dilayani lebih dulu (§7.6 SLA). */
    public function test_pesanan_terlama_dilayani_lebih_dulu(): void
    {
        $this->loginAs();

        $lama = $this->pesananMenunggu(30, now()->subDays(3)->toDateTimeString());
        $baru = $this->pesananMenunggu(30, now()->subHour()->toDateTimeString());

        // Hanya cukup untuk satu pesanan.
        $this->tambahStok(['qty' => 30]);

        $this->assertSame(30, $lama->fresh()->qty_allocated, 'Pesanan terlama harus dilayani dulu.');
        $this->assertSame(0, $baru->fresh()->qty_allocated);
    }

    public function test_sisa_stok_dibagi_ke_pesanan_berikutnya(): void
    {
        $this->loginAs();

        $lama = $this->pesananMenunggu(10, now()->subDays(3)->toDateTimeString());
        $baru = $this->pesananMenunggu(10, now()->subHour()->toDateTimeString());

        $this->tambahStok(['qty' => 15]);

        $this->assertSame(10, $lama->fresh()->qty_allocated);
        $this->assertSame(5, $baru->fresh()->qty_allocated, 'Sisanya jatuh ke pesanan berikutnya.');
        $this->assertSame(0, InventoryStock::firstOrFail()->qty_available);
    }

    /**
     * Pesanan yang sudah lewat picking TIDAK ditambahi alokasi susulan:
     * barangnya sudah diambil dari rak dan daftar pickingnya sudah dicetak,
     * jadi alokasi susulan tidak akan pernah ikut terkirim.
     */
    public function test_pesanan_yang_sudah_lewat_picking_tidak_diisi_lagi(): void
    {
        $this->loginAs();

        $detail = $this->pesananMenunggu(20);
        $detail->salesOrder->update(['status' => SalesOrder::STATUS_READY_TO_SHIP]);

        $this->tambahStok(['qty' => 50])->assertSessionHas('success');

        $this->assertSame(0, $detail->fresh()->qty_allocated);
        $this->assertSame(50, InventoryStock::firstOrFail()->qty_available);
    }

    /** Koreksi yang MENAMBAH qty juga memicu pelengkapan. */
    public function test_koreksi_yang_menambah_qty_ikut_mengisi_pesanan(): void
    {
        $this->loginAs();

        $this->tambahStok(['qty' => 5]);
        $stok = InventoryStock::firstOrFail();

        $detail = $this->pesananMenunggu(20);

        $this->post('/wms/inventory/adjust', [
            'stock_id' => $stok->id,
            'qty_new' => 25,
            'reason' => 'Hasil hitung ulang di rak.',
        ])->assertSessionHas('warning', fn ($p) => str_contains($p, 'langsung dialokasikan'));

        $this->assertSame(20, $detail->fresh()->qty_allocated);
    }

    /** Koreksi yang MENGURANGI tidak punya apa pun untuk dibagikan. */
    public function test_koreksi_yang_mengurangi_tidak_memicu_alokasi(): void
    {
        $this->loginAs();

        $this->tambahStok(['qty' => 50]);
        $stok = InventoryStock::firstOrFail();
        $detail = $this->pesananMenunggu(20);

        $this->post('/wms/inventory/adjust', [
            'stock_id' => $stok->id,
            'qty_new' => 40,
            'reason' => 'Ada yang rusak saat dipindah.',
        ])->assertSessionHas('success');

        $this->assertSame(0, $detail->fresh()->qty_allocated);
    }

    /* ------------------------------------------------ Impor Stok Awal */

    private function berkasStok(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['SKU', 'Batch', 'Tanggal Produksi', 'Qty', 'Lokasi'], null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'stok').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'stok-awal.xlsx', null, null, true);
    }

    private function imporStok(array $rows)
    {
        $preview = $this->post('/wms/inventory/import/preview', ['file' => $this->berkasStok($rows)]);

        return $this->post('/wms/inventory/import', [
            'token' => $preview->viewData('token'),
            'extension' => $preview->viewData('extension'),
        ]);
    }

    public function test_impor_stok_awal_membuat_baris_stok(): void
    {
        $this->loginAs();

        $this->imporStok([
            ['APKO-001', 'BT-001', '2026-03-15', 120, 'A-01-02'],
        ])->assertSessionHas('success');

        $stok = InventoryStock::firstOrFail();

        $this->assertSame(120, $stok->qty_available);
        $this->assertSame('BT-001', $stok->batch_no);
        $this->assertSame('2026-03-15', $stok->production_date->toDateString());
        $this->assertSame($this->gudang->id, $stok->warehouse_id);
    }

    /**
     * INTI keputusan pemilik produk: berkas dianggap kebenaran, qty
     * DISAMAKAN bukan ditambahkan. Kalau ditambahkan, satu impor ulang yang
     * tidak disengaja melipatgandakan stok seluruh gudang tanpa tanda apa pun.
     */
    public function test_impor_ulang_berkas_yang_sama_tidak_melipatgandakan_stok(): void
    {
        $this->loginAs();

        $baris = [['APKO-001', 'BT-001', '2026-03-15', 120, 'A-01-02']];

        $this->imporStok($baris);
        $this->imporStok($baris);

        $this->assertSame(1, InventoryStock::count());
        $this->assertSame(120, InventoryStock::firstOrFail()->qty_available);
    }

    public function test_impor_menyesuaikan_qty_ke_angka_di_berkas(): void
    {
        $this->loginAs();

        $this->imporStok([['APKO-001', 'BT-001', '2026-03-15', 120, 'A-01-02']]);
        $this->imporStok([['APKO-001', 'BT-001', '2026-03-15', 90, 'A-01-02']]);

        $this->assertSame(90, InventoryStock::firstOrFail()->qty_available);
    }

    public function test_batch_kosong_ditolak_dengan_alasan(): void
    {
        $this->loginAs();

        $this->imporStok([['APKO-001', '', '2026-03-15', 120, 'A-01-02']])
            ->assertSessionHas('warning', fn ($p) => str_contains($p, 'Batch'));

        $this->assertSame(0, InventoryStock::count());
    }

    public function test_tanggal_produksi_kosong_ditolak_dengan_alasan(): void
    {
        $this->loginAs();

        $this->imporStok([['APKO-001', 'BT-001', '', 120, 'A-01-02']])
            ->assertSessionHas('warning', fn ($p) => str_contains($p, 'Tanggal Produksi'));

        $this->assertSame(0, InventoryStock::count());
    }

    /** Lokasi TIDAK dibuat otomatis — kode asing hampir pasti salah ketik. */
    public function test_lokasi_tidak_dikenal_ditolak_tanpa_membuat_lokasi_baru(): void
    {
        $this->loginAs();

        $this->imporStok([['APKO-001', 'BT-001', '2026-03-15', 120, 'Z-99-99']])
            ->assertSessionHas('warning', fn ($p) => str_contains($p, 'Z-99-99'));

        $this->assertSame(1, Location::count());
        $this->assertSame(0, InventoryStock::count());
    }

    /** Satu baris rusak tidak boleh menghentikan sisa berkas. */
    public function test_baris_rusak_tidak_menghentikan_baris_lain(): void
    {
        $this->loginAs();

        Product::factory()->create(['sku' => 'APKO-002', 'is_active' => true, 'shelf_life_months' => 30]);

        $this->imporStok([
            ['APKO-001', 'BT-001', '2026-03-15', 10, 'A-01-02'],
            ['TIDAK-ADA', 'BT-002', '2026-03-15', 20, 'A-01-02'],
            ['APKO-002', 'BT-003', '2026-03-15', 30, 'A-01-02'],
        ])->assertSessionHas('warning');

        $this->assertSame(2, InventoryStock::count());
        $this->assertSame(40, (int) InventoryStock::sum('qty_available'));
    }

    /**
     * Menurunkan qty di bawah yang sudah dijanjikan ke pesanan membuat
     * pesanan yang diterima kehilangan barangnya. CHECK (qty_available >= 0)
     * tidak menangkap ini karena angkanya masih positif.
     */
    public function test_impor_tidak_boleh_menurunkan_qty_di_bawah_yang_teralokasi(): void
    {
        $this->loginAs();

        $this->imporStok([['APKO-001', 'BT-001', '2026-03-15', 100, 'A-01-02']]);

        // 40 dikunci untuk satu pesanan.
        $detail = $this->pesananMenunggu(40);
        app(FifoAllocator::class)->allocate($detail, 40, null);
        $this->assertSame(40, InventoryStock::firstOrFail()->qty_allocated);

        $this->imporStok([['APKO-001', 'BT-001', '2026-03-15', 10, 'A-01-02']])
            ->assertSessionHas('warning', fn ($p) => str_contains($p, 'dialokasikan'));

        $this->assertSame(60, InventoryStock::firstOrFail()->qty_available, 'Qty tidak boleh berubah.');
    }

    /** Impor juga mengisi pesanan yang menunggu, sama seperti Tambah Stok. */
    public function test_impor_ikut_mengisi_pesanan_yang_menunggu(): void
    {
        $this->loginAs();

        $detail = $this->pesananMenunggu(25);

        $this->imporStok([['APKO-001', 'BT-001', '2026-03-15', 100, 'A-01-02']]);

        $this->assertSame(25, $detail->fresh()->qty_allocated);
        $this->assertSame(75, InventoryStock::firstOrFail()->qty_available);
    }

    public function test_logistik_tidak_boleh_mengimpor_stok_awal(): void
    {
        $this->loginAs(Role::LOGISTICS);

        $this->post('/wms/inventory/import/preview', [
            'file' => $this->berkasStok([['APKO-001', 'BT-001', '2026-03-15', 10, 'A-01-02']]),
        ])->assertForbidden();
    }
}
