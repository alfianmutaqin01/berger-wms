<?php

namespace Tests\Feature\Wms;

use App\Models\Customer;
use App\Models\InboundDetail;
use App\Models\InboundHeader;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\StockBooking;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Outbound\FifoAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Booking produk — menahan jatah customer sebelum pesanannya masuk.
 *
 * KEJADIAN YANG MELAHIRKANNYA: customer minta jatah dari batch yang belum
 * diproduksi. Barangnya belum ada, jadi tidak ada apa pun yang memegang janji
 * itu; begitu produksi naik rak, barang mendarat bebas dan pesanan lain
 * menyambarnya lewat FIFO.
 *
 * EMPAT HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * --------------------------------------------------
 * 1. Stok yang dibooking HILANG dari angka yang boleh dijanjikan, dan FIFO
 *    tidak boleh menyentuhnya sama sekali.
 * 2. Booking saat gudang kosong tetap sah — jatahnya diambilkan otomatis
 *    begitu barang produksi diverifikasi. Inilah inti keluhannya.
 * 3. SATU JANJI, SATU PEMILIK. Begitu pesanan customer itu diterima, jatahnya
 *    berpindah dari booking ke pesanan. Tanpa itu, keduanya sama-sama
 *    memegang unit yang sama dan gudang menjanjikan dua kali lipat.
 * 4. Membatalkan booking mengembalikan qty ke BATCH YANG BENAR, bukan ke
 *    batch sembarang yang kebetulan punya sisa.
 */
class ProductBookingTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $gudang;

    private Location $rak;

    private Product $produk;

    private Customer $pemesanDuluan;

    private Customer $pemesanLain;

    private PaymentTerm $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->withProduction()->create(['code' => 'WH-01', 'name' => 'Karawang']);
        $this->rak = Location::factory()->create([
            'warehouse_id' => $this->gudang->id, 'code' => 'B-01-01',
            'rack' => 'B-01', 'level' => 1, 'cell' => 1, 'is_active' => true,
        ]);
        $this->produk = Product::factory()->create([
            'sku' => 'APKO-001', 'name' => 'Bocor Guard 2 Base 1Kg', 'uom' => 'TIN',
            'is_active' => true, 'shelf_life_months' => 24,
        ]);
        $this->pemesanDuluan = Customer::factory()->create(['is_active' => true, 'name' => 'PT Duluan']);
        $this->pemesanLain = Customer::factory()->create(['is_active' => true, 'name' => 'PT Belakangan']);
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

    private function stok(int $qty, string $batch = 'BT-LAMA', string $produksi = '2026-01-15'): InventoryStock
    {
        return InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->gudang->id,
            'location_id' => $this->rak->id,
            'batch_no' => $batch,
            'production_date' => $produksi,
            'expiry_date' => '2028-01-15',
            'qty_available' => $qty,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);
    }

    private function buatBooking(int $qty, ?Customer $customer = null, array $ubah = [])
    {
        return $this->post(route('wms.booking.store'), array_merge([
            'warehouse_id' => $this->gudang->id,
            'customer_id' => ($customer ?? $this->pemesanDuluan)->id,
            'product_id' => $this->produk->id,
            'qty' => $qty,
        ], $ubah));
    }

    /** Pesanan milik satu customer yang menunggu diterima Logistik. */
    private function pesanan(Customer $customer, int $qty): SalesOrder
    {
        $sales = User::factory()->withRole(Role::SALES)->create(['warehouse_id' => $this->gudang->id]);

        $order = SalesOrder::factory()->submitted()->create([
            'user_id' => $sales->id,
            'customer_id' => $customer->id,
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

    private function terima(SalesOrder $order, int $qty, string $nomorSo)
    {
        return $this->post(route('wms.approval.accept', $order), [
            'bc_so_number' => $nomorSo,
            'item' => [[
                'product_id' => $this->produk->id,
                'qty_approved' => $qty,
                'qty_ordered' => $qty,
            ]],
        ]);
    }

    /** Menjalankan verifikasi inbound sungguhan — jalur produksi ke rak. */
    private function verifikasiProduksi(int $qty, string $batch = 'BT-BARU'): void
    {
        $header = InboundHeader::factory()->create([
            'warehouse_id' => $this->gudang->id,
            'document_number' => 'IN-'.Str::random(6),
            'status' => InboundHeader::STATUS_VERIFICATION_PENDING,
            'production_date' => '2026-03-01',
        ]);

        $detail = InboundDetail::factory()->create([
            'inbound_header_id' => $header->id,
            'product_id' => $this->produk->id,
            'production_order_no' => 'RMO26080294',
            'batch_no' => $batch,
            'total_qty' => $qty,
            'pallet_no' => 1,
            'pallet_qty' => $qty,
            'location_id' => $this->rak->id,
            'qty_actual' => $qty,
            'putaway_at' => now(),
            'is_verified' => false,
        ]);

        $this->post('/wms/inbound/verify/'.$header->document_number, [
            'pallets' => [
                $detail->id => ['verified' => 1, 'location_code' => $this->rak->code, 'qty_actual' => $qty],
            ],
        ])->assertSessionHasNoErrors();
    }

    /* ============================================================== Akses */

    public function test_sales_dan_operator_tidak_boleh_membuka_booking(): void
    {
        foreach ([Role::SALES, Role::WAREHOUSE_OPERATOR, Role::PRODUCTION] as $slug) {
            $this->loginAs($slug);

            $this->get('/wms/outbound/booking')->assertForbidden();
        }
    }

    public function test_logistik_boleh_membuka_booking(): void
    {
        $this->loginAs();

        $this->get('/wms/outbound/booking')->assertOk();
    }

    /* ====================================================== Menahan jatah */

    public function test_booking_menahan_stok_dan_menghilangkannya_dari_yang_bisa_dijanjikan(): void
    {
        $this->loginAs();
        $stok = $this->stok(10);

        $this->buatBooking(5)->assertSessionHasNoErrors();

        $segar = $stok->fresh();

        $this->assertSame(5, $segar->qty_available, 'Yang bebas tinggal 5.');
        $this->assertSame(5, $segar->qty_allocated, 'Lima lagi ditahan booking.');

        // Inilah janji intinya: yang bisa dipesan tinggal 5.
        $tersedia = app(FifoAllocator::class)->availableFor([$this->produk->id], $this->gudang->id);

        $this->assertSame(5, $tersedia[$this->produk->id]);
    }

    /** FIFO tidak boleh menyentuh jatah yang sudah dibooking. */
    public function test_pesanan_customer_lain_tidak_bisa_mengambil_jatah_yang_dibooking(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->buatBooking(5);

        $order = $this->pesanan($this->pemesanLain, 10);
        $this->terima($order, 10, 'SO-LAIN-1')->assertSessionHasNoErrors();

        $detail = $order->details()->firstOrFail();

        $this->assertSame(5, $detail->qty_allocated, 'Hanya 5 yang bebas yang boleh diambil.');
        $this->assertSame(5, $detail->qty_pending_stock, 'Sisanya menunggu stok, bukan menyerobot booking.');
    }

    /**
     * Booking saat gudang KOSONG tetap sah.
     *
     * Inilah keluhan aslinya: customer minta jatah dari batch yang belum
     * diproduksi.
     */
    public function test_booking_saat_stok_kosong_menunggu_produksi(): void
    {
        $this->loginAs();

        $this->buatBooking(5)->assertSessionHas('warning');

        $booking = StockBooking::firstOrFail();

        $this->assertSame(0, $booking->qty_reserved);
        $this->assertSame(5, $booking->qty_waiting);
        $this->assertTrue($booking->masihBerlaku());
    }

    /**
     * INTI PERBAIKANNYA: barang produksi yang diverifikasi langsung mengisi
     * booking yang menunggu.
     *
     * Jalur produksi -> cek operator -> naik rak dulunya satu-satunya jalan
     * masuk stok yang TIDAK melayani janji yang sudah menumpuk.
     */
    public function test_verifikasi_produksi_langsung_mengisi_booking_yang_menunggu(): void
    {
        $this->loginAs();
        $this->buatBooking(5);

        $this->verifikasiProduksi(10);

        $booking = StockBooking::firstOrFail();

        $this->assertSame(5, $booking->qty_reserved, 'Jatahnya diambilkan otomatis.');
        $this->assertSame(0, $booking->qty_waiting);

        // Dan yang bisa dijual dari 10 unit baru itu hanya 5.
        $tersedia = app(FifoAllocator::class)->availableFor([$this->produk->id], $this->gudang->id);

        $this->assertSame(5, $tersedia[$this->produk->id]);
    }

    /**
     * Antreannya SATU untuk dua bentuk janji, urut siapa yang dijanjikan
     * lebih dulu — bukan booking selalu menang atau selalu kalah.
     */
    public function test_janji_terlama_dilayani_lebih_dulu(): void
    {
        $this->loginAs();

        // Pesanan disubmit tiga minggu lalu dan diterima saat stok kosong.
        $order = $this->pesanan($this->pemesanLain, 6);
        $order->forceFill(['submitted_at' => now()->subWeeks(3)])->save();
        $this->terima($order, 6, 'SO-LAMA-1')->assertSessionHas('warning');

        // Booking dibuat hari ini.
        $this->buatBooking(6);

        // Produksi hanya menghasilkan 6 — tidak cukup untuk keduanya.
        $this->verifikasiProduksi(6);

        $this->assertSame(
            6,
            $order->details()->firstOrFail()->qty_allocated,
            'Pesanan yang menunggu tiga minggu dilayani lebih dulu.'
        );
        $this->assertSame(0, StockBooking::firstOrFail()->qty_reserved);
    }

    /* ================================================= Satu janji, satu pemilik */

    /**
     * YANG PALING BERBAHAYA KALAU SALAH: booking dan pesanan sama-sama
     * memegang unit yang sama.
     */
    public function test_pesanan_customer_yang_sama_memakai_jatah_bookingnya(): void
    {
        $this->loginAs();
        $stok = $this->stok(10);
        $this->buatBooking(5);

        $order = $this->pesanan($this->pemesanDuluan, 5);
        $this->terima($order, 5, 'SO-DULU-1')->assertSessionHasNoErrors();

        $segar = $stok->fresh();

        // Kalau jatahnya TIDAK berpindah, teralokasi akan jadi 10 untuk
        // barang yang cuma dijanjikan 5.
        $this->assertSame(5, $segar->qty_allocated, 'Tetap 5 — pemiliknya yang berpindah, bukan jumlahnya.');
        $this->assertSame(5, $segar->qty_available);
        $this->assertSame(5, $order->details()->firstOrFail()->qty_allocated);

        $booking = StockBooking::firstOrFail();

        $this->assertSame(0, $booking->qty_reserved, 'Booking sudah melepas jatahnya.');
        $this->assertSame(StockBooking::STATUS_CLOSED, $booking->status);
    }

    /**
     * Booking yang masih MENUNGGU stok pun ikut ditutup saat pesanannya masuk.
     *
     * Kalau tidak, satu unit yang sama akan antre dua kali begitu barang baru
     * datang — sekali atas nama booking, sekali atas nama pesanan.
     */
    public function test_booking_yang_menunggu_tidak_antre_dua_kali_setelah_pesanannya_masuk(): void
    {
        $this->loginAs();
        $this->buatBooking(5);

        $order = $this->pesanan($this->pemesanDuluan, 5);
        $this->terima($order, 5, 'SO-DULU-2')->assertSessionHas('warning');

        $booking = StockBooking::firstOrFail();

        $this->assertSame(5, $booking->qty_used);
        $this->assertSame(0, $booking->qty_waiting, 'Janjinya kini dipikul pesanan.');
        $this->assertSame(StockBooking::STATUS_CLOSED, $booking->status);

        // Produksi 5 unit hanya boleh mengisi pesanan, bukan dihitung dua kali.
        $this->verifikasiProduksi(5);

        $this->assertSame(5, $order->details()->firstOrFail()->qty_allocated);
        $this->assertSame(0, StockBooking::firstOrFail()->qty_reserved);
    }

    /** Booking milik customer LAIN tidak boleh ikut terpakai. */
    public function test_pesanan_tidak_memakai_booking_milik_customer_lain(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->buatBooking(5, $this->pemesanDuluan);

        $order = $this->pesanan($this->pemesanLain, 5);
        $this->terima($order, 5, 'SO-LAIN-2')->assertSessionHasNoErrors();

        $booking = StockBooking::firstOrFail();

        $this->assertSame(5, $booking->qty_reserved, 'Jatah PT Duluan tidak tersentuh.');
        $this->assertSame(0, $booking->qty_used);
    }

    /* ============================================================ Pembatalan */

    public function test_pembatalan_mengembalikan_jatah_ke_batch_yang_benar(): void
    {
        $this->loginAs();
        $tua = $this->stok(4, 'BT-TUA', '2026-01-01');
        $muda = $this->stok(10, 'BT-MUDA', '2026-02-01');

        // FIFO: 4 dari batch tua, 3 dari yang muda.
        $this->buatBooking(7);

        $this->assertSame(0, $tua->fresh()->qty_available);
        $this->assertSame(7, $muda->fresh()->qty_available);

        $booking = StockBooking::firstOrFail();

        $this->post(route('wms.booking.cancel', $booking), [
            'cancel_reason' => 'Customer membatalkan permintaannya.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(4, $tua->fresh()->qty_available, 'Kembali ke batch asalnya, bukan ke batch lain.');
        $this->assertSame(0, $tua->fresh()->qty_allocated);
        $this->assertSame(10, $muda->fresh()->qty_available);
        $this->assertSame(0, $muda->fresh()->qty_allocated);

        $this->assertSame(StockBooking::STATUS_CANCELLED, $booking->fresh()->status);
    }

    public function test_pembatalan_wajib_beralasan(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->buatBooking(5);

        $this->post(route('wms.booking.cancel', StockBooking::firstOrFail()), ['cancel_reason' => ''])
            ->assertSessionHasErrors('cancel_reason');

        $this->assertSame(StockBooking::STATUS_OPEN, StockBooking::firstOrFail()->status);
    }

    public function test_booking_yang_sudah_dibatalkan_tidak_bisa_dibatalkan_lagi(): void
    {
        $this->loginAs();
        $this->stok(10);
        $this->buatBooking(5);

        $booking = StockBooking::firstOrFail();
        $alasan = ['cancel_reason' => 'Customer membatalkan permintaannya.'];

        $this->post(route('wms.booking.cancel', $booking), $alasan);
        $this->post(route('wms.booking.cancel', $booking), $alasan)->assertSessionHas('error');

        $this->assertSame(10, InventoryStock::firstOrFail()->qty_available, 'Tidak boleh dilepas dua kali.');
    }

    public function test_booking_gudang_lain_tidak_bisa_dibatalkan(): void
    {
        $lain = Warehouse::factory()->create(['code' => 'WH-02', 'name' => 'Pekanbaru']);

        $this->loginAs();
        $this->stok(10);
        $this->buatBooking(5);

        $booking = StockBooking::firstOrFail();
        $booking->forceFill(['warehouse_id' => $lain->id])->save();

        $this->post(route('wms.booking.cancel', $booking), [
            'cancel_reason' => 'Mencoba dari gudang lain.',
        ])->assertForbidden();
    }

    /* ================================================================ Ledger */

    /**
     * Menahan dan melepas booking harus tetap membuat ledger setara dengan
     * qty_available — invarian yang menjaga seluruh angka stok.
     */
    public function test_ledger_tetap_setara_dengan_stok_setelah_booking_dan_pembatalan(): void
    {
        $this->loginAs();
        $stok = $this->stok(10);

        $this->buatBooking(6);
        $this->post(route('wms.booking.cancel', StockBooking::firstOrFail()), [
            'cancel_reason' => 'Customer membatalkan permintaannya.',
        ]);

        $ledger = StockMovement::where('batch_no', $stok->batch_no)->sum('qty_change');

        $this->assertSame(10, $stok->fresh()->qty_available);
        // Stok awal dibuat lewat factory tanpa mutasi, jadi yang dijumlahkan
        // di sini hanya mutasi booking: -6 lalu +6.
        $this->assertSame(0, (int) $ledger);
    }

    /* ------------------------------------------- Kolom ketik-lalu-pilih */

    /**
     * Dropdown berisi seluruh master data DIHAPUS.
     *
     * Dulu halaman ini mengirim seluruh customer aktif dan seluruh produk
     * aktif sebagai <option> — ribuan baris yang hampir seluruhnya tidak
     * pernah dipakai, dan customer yang kebetulan ada di tengah harus dicari
     * dengan menggulir. Dikunci di sini supaya tidak diam-diam kembali saat
     * ada yang merasa dropdown "lebih sederhana".
     */
    public function test_halaman_tidak_lagi_memuat_seluruh_master_sebagai_dropdown(): void
    {
        $this->loginAs();

        // Customer yang TIDAK dicari tidak boleh ikut terkirim ke halaman.
        Customer::factory()->create(['is_active' => true, 'name' => 'PT Tidak Pernah Dipakai']);

        $halaman = $this->get(route('wms.booking.index'))->assertOk();

        $halaman->assertDontSee('PT Tidak Pernah Dipakai');
        $halaman->assertDontSee('APKO-001');
        $halaman->assertSee('Ketik nama atau kode customer...', false);
        $halaman->assertSee('Ketik SKU atau nama produk...', false);
    }

    public function test_pencarian_customer_menjawab_yang_cocok_saja(): void
    {
        $this->loginAs();

        $hasil = $this->getJson(route('wms.booking.lookup.customers', ['q' => 'Duluan']))
            ->assertOk()
            ->json();

        $this->assertCount(1, $hasil);
        $this->assertSame('PT Duluan', $hasil[0]['name']);

        // Satu huruf tidak menyempitkan apa pun; jawabannya kosong, bukan
        // seluruh isi master data.
        $this->getJson(route('wms.booking.lookup.customers', ['q' => 'P']))
            ->assertOk()
            ->assertExactJson([]);
    }

    /**
     * Hasil pencarian produk membawa stok bebasnya.
     *
     * Tanpa itu, produk yang stoknya nol baru ketahuan setelah dipilih — dan
     * orang mencoba satu per satu sampai ketemu yang ada barangnya.
     */
    public function test_pencarian_produk_menyertakan_stok_bebas(): void
    {
        $this->loginAs();

        $this->stok(10);

        $hasil = $this->getJson(route('wms.booking.lookup.products', [
            'q' => 'APKO', 'warehouse_id' => $this->gudang->id,
        ]))->assertOk()->json();

        $this->assertCount(1, $hasil);
        $this->assertSame('APKO-001', $hasil[0]['sku']);
        $this->assertSame(10, $hasil[0]['tersedia']);
    }

    /** Stok yang sedang dibooking tidak lagi terhitung bebas di daftar saran. */
    public function test_stok_yang_dibooking_hilang_dari_saran_pencarian(): void
    {
        $this->loginAs();

        $this->stok(10);
        $this->buatBooking(4)->assertSessionHasNoErrors();

        $hasil = $this->getJson(route('wms.booking.lookup.products', [
            'q' => 'APKO', 'warehouse_id' => $this->gudang->id,
        ]))->assertOk()->json();

        $this->assertSame(6, $hasil[0]['tersedia'], '10 dikurangi 4 yang sudah ditahan.');
    }

    /** Gudang di URL tidak bisa dipakai mengintip gudang yang bukan wewenangnya. */
    public function test_pencarian_produk_gudang_lain_ditolak(): void
    {
        $this->loginAs();

        $lain = Warehouse::factory()->create(['code' => 'WH-99', 'name' => 'Pekanbaru']);

        $this->getJson(route('wms.booking.lookup.products', [
            'q' => 'APKO', 'warehouse_id' => $lain->id,
        ]))->assertForbidden();
    }

    public function test_pencarian_hanya_untuk_yang_berhak_membuka_booking(): void
    {
        foreach ([Role::SALES, Role::WAREHOUSE_OPERATOR] as $slug) {
            $this->loginAs($slug);

            $this->getJson(route('wms.booking.lookup.customers', ['q' => 'Duluan']))->assertForbidden();
            $this->getJson(route('wms.booking.lookup.products', ['q' => 'APKO']))->assertForbidden();
        }
    }
}
