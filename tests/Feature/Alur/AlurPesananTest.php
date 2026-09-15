<?php

namespace Tests\Feature\Alur;

use App\Models\BillingPayment;
use App\Models\Customer;
use App\Models\CustomerBilling;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\InboundDetail;
use App\Models\InboundHeader;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\PaymentTerm;
use App\Models\PickingList;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderEmail;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ALUR PENUH LINTAS MODUL — pengujian end-to-end sebelum go-live (Fase 13).
 *
 * Test modul menguji tiap langkah dari keadaan yang DISIAPKAN: pesanan yang
 * "sudah dipicking" dibuat langsung lewat factory. Itu cepat dan tepat untuk
 * aturan per langkah, tetapi tidak pernah membuktikan bahwa keadaan yang
 * dihasilkan satu modul memang keadaan yang diterima modul berikutnya. Celah
 * seperti itu hanya kelihatan saat seseorang menjalankan alurnya dari awal.
 *
 * Di sini tidak ada keadaan buatan tangan setelah langkah pertama. Setiap
 * perpindahan status terjadi lewat permintaan HTTP yang sama dengan yang
 * dikirim browser, oleh role yang memang mengerjakannya di gudang.
 *
 * Checklist UAT manual untuk alur yang sama: docs/10_checklist_uat.md.
 */
class AlurPesananTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $gudang;

    private Location $rak;

    private Product $produk;

    private Customer $customer;

    private PaymentTerm $tunai;

    private PaymentTerm $tempo;

    private User $sales;

    private User $logistik;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();

        // Kamis pagi, sebelum batas pesanan 15:00 WIB.
        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', 'Asia/Jakarta'));

        $this->gudang = Warehouse::factory()->withProduction()->create(['code' => 'ID11_1001', 'name' => 'Karawang']);
        $this->rak = $this->lokasi('B-01-01');
        $this->produk = Product::factory()->create([
            'sku' => 'ID1-F0017X002820', 'name' => 'Bocor Guard 20Kg', 'uom' => 'PAIL', 'is_active' => true,
        ]);
        $this->customer = Customer::factory()->create(['code' => 'IDR13302', 'name' => 'TB Sinar Jaya', 'is_active' => true]);
        $this->tunai = PaymentTerm::firstOrCreate(['code' => 'cash'], ['name' => 'Cash / Tunai', 'days' => 0, 'is_active' => true, 'sort_order' => 1]);
        $this->tempo = PaymentTerm::firstOrCreate(['code' => 'net30'], ['name' => 'Tempo 30 Hari', 'days' => 30, 'is_active' => true, 'sort_order' => 2]);

        $this->sales = $this->akun(Role::SALES, ['full_name' => 'Budi Santoso', 'email' => 'budi@contoh.co.id', 'phone_number' => '081298765432']);
        $this->logistik = $this->akun(Role::LOGISTICS);
        $this->operator = $this->akun(Role::WAREHOUSE_OPERATOR);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ================================================================ Alur */

    /** Pesanan tunai: dari Sales menekan Kirim sampai Logistik menutupnya. */
    public function test_pesanan_tunai_dari_sales_sampai_selesai(): void
    {
        $stok = $this->stok(100, 'BT-2601');

        $order = $this->salesMemesan(10, $this->tunai);
        $this->assertSame(SalesOrder::STATUS_PENDING, $order->status);

        $this->logistikMenerima($order, 10);
        $this->assertSame(SalesOrder::STATUS_APPROVED, $order->fresh()->status);
        $this->assertSame(10, $stok->fresh()->qty_allocated, 'Barang dicadangkan saat pesanan diterima.');

        $this->dipicking($order);
        $this->assertSame(SalesOrder::STATUS_READY_TO_SHIP, $order->fresh()->status);

        $note = $this->suratJalanBerangkat($order, 10);
        $this->assertSame(SalesOrder::STATUS_SHIPPING, $order->fresh()->status);

        $this->supirMengonfirmasiSampai($note);
        $this->assertSame(DeliveryNote::STATUS_DELIVERED, $note->fresh()->status);

        $this->salesMengunggahSuratJalan($order);
        $this->assertSame(SalesOrder::STATUS_PROOF_UPLOADED, $order->fresh()->status);

        $this->sebagai($this->logistik);
        $this->post(route('wms.verification.complete', $order))->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(SalesOrder::STATUS_COMPLETED, $order->status, 'Pesanan tunai langsung selesai.');
        $this->assertNotNull($order->completed_at);
        $this->assertSame(90, $stok->fresh()->qty_available, 'Stok rak berkurang sebanyak yang dikirim.');
        $this->assertSame(0, $stok->fresh()->qty_allocated);
        $this->assertSame(0, CustomerBilling::count(), 'Pesanan tunai tidak masuk buku piutang.');

        // Sales pemilik pesanan menerima kabar di setiap tahap.
        $this->assertEqualsCanonicalizing(
            [SalesOrderEmail::TYPE_APPROVED, SalesOrderEmail::TYPE_SHIPPED, SalesOrderEmail::TYPE_DELIVERED, SalesOrderEmail::TYPE_COMPLETED],
            SalesOrderEmail::where('sales_order_id', $order->id)->pluck('type')->all(),
        );
        $this->assertSame(0, SalesOrderEmail::where('recipient_email', '<>', 'budi@contoh.co.id')->count());
    }

    /** Pesanan tempo: selesai dikirim masuk Billing, lunas menutupnya. */
    public function test_pesanan_tempo_masuk_billing_lalu_lunas(): void
    {
        $this->stok(50, 'BT-2601');

        $order = $this->salesMemesan(20, $this->tempo);
        $this->logistikMenerima($order, 20);
        $this->dipicking($order);
        $note = $this->suratJalanBerangkat($order, 20);
        $this->supirMengonfirmasiSampai($note);
        $this->salesMengunggahSuratJalan($order);

        $this->sebagai($this->logistik);
        $this->post(route('wms.verification.complete', $order))->assertSessionHas('success');

        $this->assertSame(SalesOrder::STATUS_COMPLETED_BILLING, $order->fresh()->status);

        $tagihan = CustomerBilling::sole();
        $this->assertSame($order->id, $tagihan->sales_order_id);
        $this->assertSame('2026-10-01', $tagihan->delivered_on->toDateString(), 'Jatuh tempo dihitung dari tanggal barang sampai.');
        $this->assertSame('2026-10-31', $tagihan->due_date->toDateString());

        $this->get(route('wms.billing.index', ['tab' => 'berjalan']))->assertOk()->assertSee($order->fresh()->bc_so_number);

        $this->post(route('wms.billing.pay'), [
            'billing_ids' => [$tagihan->id],
            'paid_on' => '2026-10-01',
            'method' => BillingPayment::METHOD_GIRO,
            'reference' => 'GR-778812',
        ])->assertSessionHas('success');

        $this->assertSame(SalesOrder::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertTrue($tagihan->fresh()->sudahLunas());
        $this->assertSame('GR-778812', $tagihan->fresh()->payment->reference);
    }

    /**
     * Barang hasil produksi: ditempatkan Operator, disahkan Logistik, lalu
     * batch itulah yang diambil untuk pesanan berikutnya.
     */
    public function test_barang_produksi_masuk_rak_lalu_dipakai_untuk_pesanan(): void
    {
        $rakTujuan = $this->lokasi('B-02-01');
        $dokumen = InboundHeader::factory()->create([
            'warehouse_id' => $this->gudang->id,
            'document_number' => 'IN-261001-001',
            'status' => InboundHeader::STATUS_PUTAWAY_PENDING,
        ]);
        $palet = InboundDetail::factory()->create([
            'inbound_header_id' => $dokumen->id,
            'product_id' => $this->produk->id,
            'production_order_no' => 'RMO26100001',
            'batch_no' => 'I126100001',
            'total_qty' => 40,
            'pallet_no' => 1,
            'pallet_qty' => 40,
        ]);

        $this->sebagai($this->operator);
        $this->post('/wms/inbound/putaway/IN-261001-001', [
            'pallets' => [$palet->id => ['location_code' => 'B-02-01', 'qty_actual' => 40]],
        ])->assertRedirect('/wms/inbound/putaway');
        $this->assertSame(InboundHeader::STATUS_VERIFICATION_PENDING, $dokumen->fresh()->status);

        $this->sebagai($this->logistik);
        $this->post('/wms/inbound/verify/IN-261001-001', [
            'pallets' => [$palet->id => ['verified' => 1, 'location_code' => 'B-02-01', 'qty_actual' => 40]],
        ])->assertRedirect('/wms/inbound/verify');
        $this->assertSame(InboundHeader::STATUS_VERIFIED, $dokumen->fresh()->status);

        $stok = InventoryStock::query()
            ->where('batch_no', 'I126100001')
            ->where('location_id', $rakTujuan->id)
            ->sole();
        $this->assertSame(InventoryStock::STATUS_ACTIVE, $stok->status, 'Barang terverifikasi langsung bisa dijual.');
        $this->assertSame(40, $stok->qty_available);

        $order = $this->salesMemesan(15, $this->tunai);
        $this->logistikMenerima($order, 15);
        $daftar = $this->dipicking($order);

        $baris = $daftar->items()->sole();
        $this->assertSame('I126100001', $baris->batch_no, 'Batch dari produksi itulah yang diambil.');
        $this->assertSame($rakTujuan->id, $baris->location_id);
        $this->assertSame(25, $stok->fresh()->qty_available);
    }

    /**
     * Barang kurang di rak: dikirim sebagian, sisanya jadi outstanding, lalu
     * dikirim ulang dengan Surat Jalan kedua pada NOMOR SO YANG SAMA.
     */
    public function test_kurang_saat_picking_lalu_sisanya_dikirim_ulang(): void
    {
        $this->stok(10, 'BT-2601');

        $order = $this->salesMemesan(10, $this->tunai);
        $this->logistikMenerima($order, 10);
        $nomorSo = $order->fresh()->bc_so_number;

        // Rak ternyata hanya berisi 7.
        $daftar = $this->dipicking($order, [7, 'Rak hanya berisi 7 pail, 3 pail penyok disisihkan.']);
        $this->assertSame(7, $daftar->items()->sole()->qty_picked);

        $pertama = $this->suratJalanBerangkat($order, 7);
        $this->supirMengonfirmasiSampai($pertama);
        $this->salesMengunggahSuratJalan($order);
        $this->sebagai($this->logistik);
        $this->post(route('wms.verification.complete', $order))->assertSessionHas('success');

        $detail = $order->details()->sole();
        $this->assertSame(SalesOrder::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertSame(7, $detail->qty_shipped);
        $this->assertSame(3, $detail->outstanding_qty, 'Kekurangan tidak hilang begitu pesanan ditutup.');
        $this->get(route('wms.outstanding.index'))->assertOk()->assertSee($nomorSo);

        // Stok susulan datang, Logistik mengirim ulang kekurangannya.
        $this->stok(5, 'BT-2610');
        $this->post(route('wms.outstanding.reship', $order))->assertSessionHas('success');
        $this->assertSame(SalesOrder::STATUS_APPROVED, $order->fresh()->status);

        $this->dipicking($order);
        $kedua = $this->suratJalanBerangkat($order, 3);
        $this->supirMengonfirmasiSampai($kedua);
        $this->salesMengunggahSuratJalan($order);
        $this->sebagai($this->logistik);
        $this->post(route('wms.verification.complete', $order))->assertSessionHas('success');

        $order->refresh();
        $detail->refresh();
        $this->assertSame(SalesOrder::STATUS_COMPLETED, $order->status);
        $this->assertSame($nomorSo, $order->bc_so_number, 'Nomor SO tetap sama — bukan pesanan baru.');
        $this->assertSame(1, SalesOrder::count());
        $this->assertSame(10, $detail->qty_shipped, 'Kiriman kedua menumpuk, tidak menimpa yang pertama.');
        $this->assertSame(0, $detail->outstanding_qty);
        $this->assertSame(2, DeliveryNote::where('sales_order_id', $order->id)->count());
    }

    /* ============================================================ Langkah */

    private function salesMemesan(int $qty, PaymentTerm $term): SalesOrder
    {
        $this->sebagai($this->sales);

        $this->post('/sales/new-order', [
            'action' => 'submit',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->gudang->id,
            'payment_term_id' => $term->id,
            'order_source' => SalesOrder::SOURCE_MANUAL,
            'items' => [['product_id' => $this->produk->id, 'qty' => $qty]],
        ])->assertRedirect('/sales/my-orders');

        return SalesOrder::query()->latest('id')->firstOrFail();
    }

    private function logistikMenerima(SalesOrder $order, int $qty): void
    {
        $this->sebagai($this->logistik);

        $this->post("/wms/outbound/approval/{$order->id}/accept", [
            'bc_so_number' => 'SO'.random_int(260000, 269999),
            'item' => [['product_id' => $this->produk->id, 'qty_approved' => $qty, 'qty_ordered' => $order->details()->sole()->qty_ordered]],
        ])->assertSessionHas('success');
    }

    /** @param  array{0:int, 1:string}|null  $kurang  [qty yang benar-benar terambil, alasannya] */
    private function dipicking(SalesOrder $order, ?array $kurang = null): PickingList
    {
        $this->sebagai($this->logistik);
        $this->post(route('wms.picking.store'), [
            'warehouse_id' => $this->gudang->id,
            'order_ids' => [$order->id],
        ])->assertRedirect();

        $daftar = PickingList::query()->latest('id')->firstOrFail();

        $this->sebagai($this->operator);
        $this->post(route('wms.picking.claim', $daftar))->assertRedirect();

        foreach ($daftar->items as $baris) {
            if ($kurang === null) {
                $this->post(route('wms.picking.item.pick', [$daftar, $baris]))->assertRedirect();
            } else {
                $this->post(route('wms.picking.item.short', [$daftar, $baris]), [
                    'qty_picked' => $kurang[0],
                    'discrepancy_reason' => $kurang[1],
                ])->assertRedirect();
            }
        }

        $this->post(route('wms.picking.complete', $daftar))->assertSessionHas($kurang === null ? 'success' : 'warning');

        return $daftar->fresh();
    }

    /** Surat Jalan dari BC (hasil impor), dipasangkan, lalu diberangkatkan. */
    private function suratJalanBerangkat(SalesOrder $order, int $qty): DeliveryNote
    {
        $order->refresh();

        $note = DeliveryNote::factory()->create([
            'bc_so_number' => $order->bc_so_number,
            'customer_id' => $this->customer->id,
            'customer_code' => $this->customer->code,
            'warehouse_id' => $this->gudang->id,
        ]);
        DeliveryNoteLine::factory()->create([
            'delivery_note_id' => $note->id,
            'sku' => $this->produk->sku,
            'product_id' => $this->produk->id,
            'qty' => $qty,
        ]);

        $this->sebagai($this->logistik);
        $pasang = $this->post(route('wms.delivery.pair', $note), ['sales_order_id' => $order->id]);
        $this->assertTrue(session()->has('success'), 'Gagal memasangkan: '.session('error'));

        $this->post(route('wms.delivery.ship', $note), [
            'driver_name' => 'Asep',
            'driver_phone' => '081234567890',
            'vehicle_plate' => 'T 1234 AB',
        ])->assertSessionHas('success');

        return $note->fresh();
    }

    private function supirMengonfirmasiSampai(DeliveryNote $note): void
    {
        auth()->logout();
        $this->flushSession();

        $this->post(route('epod.confirm', $note->epod_token), [
            'received_by_name' => 'Ibu Sari',
            'photo_source' => 'camera',
            'photo' => UploadedFile::fake()->image('sampai.jpg', 800, 600),
        ])->assertRedirect();
    }

    private function salesMengunggahSuratJalan(SalesOrder $order): void
    {
        $this->sebagai($this->sales);

        $this->post(route('sales.proofs.store', $order), [
            'photos' => [UploadedFile::fake()->image('surat-jalan.jpg', 800, 600)],
        ])->assertSessionHas('success');
    }

    /* ============================================================ Perkakas */

    private function akun(string $slug, array $atribut = []): User
    {
        return User::factory()->withRole($slug)->create(array_merge(['warehouse_id' => $this->gudang->id], $atribut));
    }

    /** Masuk sebagai akun yang SUDAH ada — alur ini dikerjakan orang yang sama dari awal. */
    private function sebagai(User $user): void
    {
        $token = Str::random(64);

        UserSession::create([
            'user_id' => $user->id, 'session_id' => $token, 'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit', 'last_activity_at' => now(), 'created_at' => now(),
        ]);

        $this->withUnencryptedCookies(['device_token' => $token]);
        $this->actingAs($user);
    }

    private function lokasi(string $kode): Location
    {
        $bagian = Location::parseCode($kode);

        return Location::factory()->create([
            'warehouse_id' => $this->gudang->id, 'code' => $kode,
            'rack' => $bagian['rack'], 'level' => $bagian['level'], 'cell' => $bagian['cell'],
            'zone' => Location::ZONE_FAST, 'is_active' => true,
        ]);
    }

    private function stok(int $qty, string $batch): InventoryStock
    {
        return InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->gudang->id,
            'location_id' => $this->rak->id,
            'batch_no' => $batch,
            'production_date' => '2026-01-15',
            'expiry_date' => '2028-01-15',
            'qty_available' => $qty,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);
    }
}
