<?php

namespace Tests\Feature\Wms;

use App\Models\Customer;
use App\Models\DeliveryProof;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\SalesReturn;
use App\Models\SalesReturnDetail;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Returns\CustomerRejection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * PENOLAKAN CUSTOMER — dari depan toko sampai kembali jadi stok.
 *
 * YANG PALING PERLU DIKUNCI DI SINI
 * ---------------------------------
 * 1. STOK TIDAK BERGERAK SAMA SEKALI SEBELUM VERIFIKASI. Melapor, menyetujui,
 *    dan menaikkan ke rak semuanya hanya menulis catatan. Kalau salah satu
 *    diam-diam menambah stok, barang yang belum diperiksa siapa pun sudah
 *    bisa terjual — dan kekeliruannya baru ketahuan saat picking.
 * 2. BATCH DAN UMURNYA IKUT PULANG. Barang yang kembali harus kembali sebagai
 *    batch yang sama dengan yang berangkat. Memberinya batch atau tanggal
 *    produksi baru membuat barang lama terbaca muda lalu mengantre paling
 *    belakang di FIFO — kesalahan yang tidak pernah terlihat sampai ada yang
 *    mengirim barang kedaluwarsa.
 * 3. YANG MENAIKKAN BUKAN YANG MENGESAHKAN. Operator memisah bagus/DDP,
 *    Logistik yang memutuskan pemisahan itu benar. Keputusan itu bernilai
 *    uang di kedua arah.
 */
class CustomerRejectionTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $gudang;

    private Warehouse $gudangLain;

    private Customer $customer;

    private Product $produk;

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->create(['code' => 'WH-01']);
        $this->gudangLain = Warehouse::factory()->create(['code' => 'WH-02']);
        $this->customer = Customer::factory()->create(['name' => 'PT Bangun Menara Abadi']);
        $this->produk = Product::factory()->create([
            'sku' => 'ID1-F00113202225',
            'uom' => 'PAIL',
            'shelf_life_months' => 24,
        ]);

        $this->sales = User::factory()->withRole(Role::SALES)->create([
            'warehouse_id' => $this->gudang->id,
        ]);
    }

    /* ------------------------------------------------------------ Perkakas */

    private function login(string $slug, ?Warehouse $gudang = null): User
    {
        $user = User::factory()->withRole($slug)->create([
            'warehouse_id' => ($gudang ?? $this->gudang)->id,
        ]);

        return $this->masuk($user);
    }

    private function masuk(User $user): User
    {
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

    private function bin(string $code): Location
    {
        $parts = Location::parseCode($code);

        return Location::factory()->create(array_merge($parts, [
            'warehouse_id' => $this->gudang->id,
            'code' => $code,
        ]));
    }

    /**
     * Pesanan yang sudah berangkat, lengkap dengan jejak picking-nya —
     * dari situlah batch dan tanggal produksi barang tolakan diambil.
     */
    private function pesananTerkirim(int $qty = 10, string $batch = 'BT-001', bool $denganBukti = true): SalesOrder
    {
        $order = SalesOrder::factory()->create([
            'user_id' => $this->sales->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->gudang->id,
            'status' => SalesOrder::STATUS_SHIPPING,
            'submitted_at' => now()->subDays(3),
            'shipped_at' => now()->subDay(),
        ]);

        $detail = SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => $qty,
            'qty_approved' => $qty,
            'qty_shipped' => $qty,
            'outstanding_qty' => 0,
        ]);

        $daftar = PickingList::create([
            'list_number' => 'PL-'.Str::upper(Str::random(6)),
            'warehouse_id' => $this->gudang->id,
            'status' => PickingList::STATUS_COMPLETED,
            'completed_at' => now()->subDay(),
        ]);

        PickingListItem::create([
            'picking_list_id' => $daftar->id,
            'sales_order_id' => $order->id,
            'sales_order_detail_id' => $detail->id,
            'product_id' => $this->produk->id,
            'location_id' => $this->bin('ZA-01-01')->id,
            'batch_no' => $batch,
            'production_date' => now()->subMonths(3)->toDateString(),
            'qty_to_pick' => $qty,
            'qty_picked' => $qty,
            'status' => PickingListItem::STATUS_PICKED,
            'picked_at' => now()->subDay(),
        ]);

        /*
         * Foto Surat Jalan bertanda tangan. Sejak laporan penolakan tidak
         * bisa dikirim tanpa bukti, hampir seluruh test di berkas ini
         * membutuhkannya — jadi disiapkan di sini, bukan diulang satu per
         * satu. Yang menguji penjagaannya memanggil dengan $denganBukti false.
         */
        if ($denganBukti) {
            DeliveryProof::create([
                'sales_order_id' => $order->id,
                'path' => 'delivery-proofs/sj.jpg',
                'original_name' => 'sj.jpg',
                'size' => 1024,
                'mime' => 'image/jpeg',
                'status' => DeliveryProof::STATUS_PENDING,
                'uploaded_by' => $this->sales->id,
                'uploaded_at' => now()->subHours(2),
            ]);
        }

        return $order->refresh();
    }

    private function jasa(): CustomerRejection
    {
        return app(CustomerRejection::class);
    }

    /** Laporan yang sudah disetujui dan siap dinaikkan Operator. */
    private function returDisetujui(int $qtyTolak = 4, ?int $qtySetuju = null): SalesReturn
    {
        $order = $this->pesananTerkirim();
        $detail = $order->details()->first();

        $retur = $this->jasa()->report(
            $order,
            [['detail_id' => $detail->id, 'qty' => $qtyTolak]],
            'Warna tidak sesuai contoh, customer menolak seluruhnya.',
            $this->sales->id,
        );

        $baris = $retur->details()->first();

        $this->jasa()->approve(
            $retur,
            [$baris->id => $qtySetuju ?? $qtyTolak],
            null,
            $this->sales->id,
        );

        return $retur->refresh();
    }

    /* ========================================== TAHAP 1 — SALES MELAPOR === */

    public function test_sales_melaporkan_penolakan_dengan_batch_yang_benar_benar_berangkat(): void
    {
        $order = $this->pesananTerkirim(batch: 'BT-777');
        $detail = $order->details()->first();

        $retur = $this->jasa()->report(
            $order,
            [['detail_id' => $detail->id, 'qty' => 3]],
            'Tutup penyok saat diturunkan dari truk.',
            $this->sales->id,
        );

        $this->assertSame(SalesReturn::STATUS_REPORTED, $retur->status);
        $this->assertStringStartsWith('RJ', $retur->reference);

        $baris = $retur->details()->first();

        // Batch dan tanggal produksi disalin dari catatan picking — bukan
        // dikarang, dan bukan diisi hari ini.
        $this->assertSame('BT-777', $baris->batch_no);
        $this->assertSame(
            now()->subMonths(3)->toDateString(),
            $baris->production_date->toDateString(),
        );
        $this->assertSame(3, $baris->qty_rejected);
        $this->assertNull($baris->qty_approved);
    }

    public function test_tidak_bisa_menolak_lebih_banyak_daripada_yang_terkirim(): void
    {
        $order = $this->pesananTerkirim(qty: 5);
        $detail = $order->details()->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('yang terkirim 5');

        $this->jasa()->report(
            $order,
            [['detail_id' => $detail->id, 'qty' => 8]],
            'Customer menolak semuanya.',
            $this->sales->id,
        );
    }

    public function test_pesanan_yang_belum_berangkat_belum_bisa_dilaporkan_ditolak(): void
    {
        $order = $this->pesananTerkirim();
        $order->forceFill(['status' => SalesOrder::STATUS_PICKING])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sedang atau sudah dikirim');

        $this->jasa()->report(
            $order->refresh(),
            [['detail_id' => $order->details()->first()->id, 'qty' => 1]],
            'Customer menolak.',
            $this->sales->id,
        );
    }

    /**
     * Laporan kedua akan menagih barang yang sama dua kali ke gudang, dan
     * Operator tidak punya cara membedakan mana yang sudah ia naikkan.
     */
    public function test_satu_pesanan_hanya_boleh_punya_satu_laporan_berjalan(): void
    {
        $order = $this->pesananTerkirim();
        $detail = $order->details()->first();

        $this->jasa()->report(
            $order,
            [['detail_id' => $detail->id, 'qty' => 2]],
            'Customer menolak dua unit.',
            $this->sales->id,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum selesai');

        $this->jasa()->report(
            $order->refresh(),
            [['detail_id' => $detail->id, 'qty' => 1]],
            'Ada satu lagi yang ditolak.',
            $this->sales->id,
        );
    }

    /* ======================================= TAHAP 2 — LOGISTIK MENILAI === */

    public function test_logistik_boleh_menyetujui_lebih_sedikit_daripada_yang_dilaporkan(): void
    {
        $retur = $this->returDisetujui(qtyTolak: 5, qtySetuju: 3);
        $baris = $retur->details()->first();

        // Ketiga angka tetap ada dan tetap berbeda — itulah jejaknya.
        $this->assertSame(5, $baris->qty_rejected);
        $this->assertSame(3, $baris->qty_approved);
        $this->assertSame(SalesReturn::STATUS_PUTAWAY_PENDING, $retur->status);
    }

    /**
     * Menyetujui nol untuk seluruh baris sama dengan menolak — dan lebih jujur
     * dicatat begitu daripada menggantung di antrean put-away selamanya.
     */
    public function test_menyetujui_nol_seluruhnya_dicatat_sebagai_penolakan_laporan(): void
    {
        $retur = $this->returDisetujui(qtyTolak: 4, qtySetuju: 0);

        $this->assertSame(SalesReturn::STATUS_REJECTED, $retur->status);
    }

    public function test_logistik_menolak_laporan_dan_alasannya_tersimpan(): void
    {
        $order = $this->pesananTerkirim();

        $retur = $this->jasa()->report(
            $order,
            [['detail_id' => $order->details()->first()->id, 'qty' => 2]],
            'Customer menolak.',
            $this->sales->id,
        );

        $logistik = $this->login(Role::LOGISTICS);

        $this->jasa()->reject($retur, 'Barangnya tidak pernah sampai gudang.', $logistik->id);

        $retur->refresh();

        $this->assertSame(SalesReturn::STATUS_REJECTED, $retur->status);
        $this->assertSame('Barangnya tidak pernah sampai gudang.', $retur->approval_note);
    }

    /* ================================= TAHAP 3 — OPERATOR MENAIKKAN RAK === */

    /**
     * INI YANG PALING PENTING DI BERKAS INI. Menaikkan ke rak hanya menulis
     * catatan; kalau ia diam-diam menambah stok, barang yang belum diperiksa
     * siapa pun sudah bisa terjual.
     */
    public function test_menaikkan_ke_rak_belum_menyentuh_stok_sama_sekali(): void
    {
        $retur = $this->returDisetujui(qtyTolak: 4);
        $baris = $retur->details()->first();

        $stokSebelum = InventoryStock::sum('qty_available');
        $ledgerSebelum = StockMovement::count();

        $operator = $this->login(Role::WAREHOUSE_OPERATOR);
        $this->bin('ZB-02-01');

        $this->jasa()->putaway($baris, 3, 1, 'ZB-02-01', 'ZB-02-01', 'Satu pail penyok.', $operator->id);

        $this->assertSame($stokSebelum, InventoryStock::sum('qty_available'));
        $this->assertSame($ledgerSebelum, StockMovement::count());

        $baris->refresh();
        $this->assertSame(3, $baris->qty_good);
        $this->assertSame(1, $baris->qty_ddp);
        $this->assertNotNull($baris->putaway_at);
        $this->assertFalse($baris->is_verified);

        // Begitu seluruh barisnya naik, dokumennya pindah sendiri ke antrean
        // Logistik — tidak ada tombol "kirim" yang bisa lupa ditekan.
        $this->assertSame(SalesReturn::STATUS_VERIFICATION_PENDING, $retur->refresh()->status);
    }

    public function test_tidak_boleh_menaikkan_lebih_banyak_daripada_yang_disetujui(): void
    {
        $retur = $this->returDisetujui(qtyTolak: 4, qtySetuju: 3);
        $baris = $retur->details()->first();

        $operator = $this->login(Role::WAREHOUSE_OPERATOR);
        $this->bin('ZB-02-01');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tidak bisa menaikkan lebih banyak');

        $this->jasa()->putaway($baris, 4, 0, 'ZB-02-01', null, null, $operator->id);
    }

    /**
     * Kurang justru sering terjadi — sebagian hilang atau pecah di perjalanan
     * pulang — dan harus bisa dicatat apa adanya supaya selisihnya terlihat
     * saat verifikasi.
     */
    public function test_menaikkan_lebih_sedikit_boleh_dan_selisihnya_terlihat(): void
    {
        $retur = $this->returDisetujui(qtyTolak: 5);
        $baris = $retur->details()->first();

        $operator = $this->login(Role::WAREHOUSE_OPERATOR);
        $this->bin('ZB-02-01');

        $this->jasa()->putaway($baris, 3, 0, 'ZB-02-01', null, 'Dua pail hilang di jalan.', $operator->id);

        $this->assertSame(-2, $baris->refresh()->selisih);
    }

    public function test_rak_gudang_lain_ditolak(): void
    {
        $retur = $this->returDisetujui();
        $baris = $retur->details()->first();

        // Rak dengan kode itu ADA — tapi di gudang lain. Persis kesalahan
        // yang pernah membuat stok mendarat di Pekanbaru: kode rak tidak unik
        // antar gudang, jadi mencarinya tanpa menyebut gudang akan ketemu.
        Location::factory()->create(array_merge(
            Location::parseCode('ZX-09-09'),
            ['warehouse_id' => $this->gudangLain->id, 'code' => 'ZX-09-09'],
        ));

        $operator = $this->login(Role::WAREHOUSE_OPERATOR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak ada di gudang ini');

        $this->jasa()->putaway($baris, 4, 0, 'ZX-09-09', null, null, $operator->id);
    }

    /* ==================================== TAHAP 4 — LOGISTIK VERIFIKASI === */

    public function test_verifikasi_memasukkan_stok_dengan_batch_dan_umur_yang_sama(): void
    {
        $retur = $this->returDisetujui(qtyTolak: 4);
        $baris = $retur->details()->first();

        $operator = $this->login(Role::WAREHOUSE_OPERATOR);
        $rak = $this->bin('ZB-02-01');
        $this->jasa()->putaway($baris, 4, 0, 'ZB-02-01', null, null, $operator->id);

        $logistik = $this->login(Role::LOGISTICS);
        $jumlah = $this->jasa()->verify($retur->refresh(), [$baris->id], $logistik->id);

        $this->assertSame(1, $jumlah);

        $stok = InventoryStock::query()
            ->where('batch_no', 'BT-001')
            ->where('location_id', $rak->id)
            ->firstOrFail();

        $this->assertSame(4, $stok->qty_available);
        $this->assertSame(InventoryStock::STATUS_ACTIVE, $stok->status);

        // Umurnya tidak ikut mundur karena barangnya sempat pulang.
        $this->assertSame(
            now()->subMonths(3)->toDateString(),
            $stok->production_date->toDateString(),
        );
        $this->assertSame(
            now()->subMonths(3)->addMonths(24)->toDateString(),
            $stok->expiry_date->toDateString(),
        );

        // Ledger mencatatnya sebagai barang retur, bukan barang produksi.
        $gerak = StockMovement::query()
            ->where('movement_type', StockMovement::TYPE_RETURN_IN)
            ->firstOrFail();

        $this->assertSame(4, $gerak->qty_change);
        $this->assertSame(0, $gerak->qty_before);
        $this->assertSame($retur->id, $gerak->reference_id);

        $this->assertSame(SalesReturn::STATUS_VERIFIED, $retur->refresh()->status);
    }

    /**
     * Barang bagus dan barang DDP dari batch yang sama TIDAK boleh menyatu di
     * satu baris stok: yang satu boleh dijual, yang satu tidak.
     */
    public function test_barang_ddp_masuk_sebagai_baris_terpisah_yang_tidak_bisa_dijual(): void
    {
        $retur = $this->returDisetujui(qtyTolak: 5);
        $baris = $retur->details()->first();

        $operator = $this->login(Role::WAREHOUSE_OPERATOR);
        $rakBagus = $this->bin('ZB-02-01');
        $rakDdp = $this->bin('ZD-01-01');

        $this->jasa()->putaway($baris, 3, 2, 'ZB-02-01', 'ZD-01-01', 'Dua pail penyok.', $operator->id);

        $logistik = $this->login(Role::LOGISTICS);
        $this->jasa()->verify($retur->refresh(), [$baris->id], $logistik->id);

        $bagus = InventoryStock::where('location_id', $rakBagus->id)->firstOrFail();
        $ddp = InventoryStock::where('location_id', $rakDdp->id)->firstOrFail();

        $this->assertSame(3, $bagus->qty_available);
        $this->assertSame(InventoryStock::STATUS_ACTIVE, $bagus->status);

        $this->assertSame(2, $ddp->qty_available);
        $this->assertSame(InventoryStock::STATUS_DDP, $ddp->status);
        // Baris DDP tidak pernah muncul tanpa keterangan kenapa.
        $this->assertStringContainsString('Ditolak customer', $ddp->ddp_reason);

        // Yang DDP tidak boleh ikut terjual.
        $this->assertSame(3, (int) InventoryStock::query()->sellable()->sum('qty_available'));
    }

    public function test_baris_yang_belum_naik_rak_tidak_bisa_diverifikasi(): void
    {
        $retur = $this->returDisetujui();
        $baris = $retur->details()->first();

        $retur->forceFill(['status' => SalesReturn::STATUS_VERIFICATION_PENDING])->save();

        $logistik = $this->login(Role::LOGISTICS);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum dinaikkan Operator');

        $this->jasa()->verify($retur->refresh(), [$baris->id], $logistik->id);
    }

    /**
     * Dokumen yang SELURUHNYA sudah diverifikasi ditutup oleh penjaga status,
     * dan itu memang lapis pertamanya.
     */
    public function test_laporan_yang_sudah_selesai_tidak_bisa_dinaikkan_lagi(): void
    {
        $retur = $this->returDisetujui(qtyTolak: 4);
        $baris = $retur->details()->first();

        $operator = $this->login(Role::WAREHOUSE_OPERATOR);
        $this->bin('ZB-02-01');
        $this->jasa()->putaway($baris, 4, 0, 'ZB-02-01', null, null, $operator->id);

        $logistik = $this->login(Role::LOGISTICS);
        $this->jasa()->verify($retur->refresh(), [$baris->id], $logistik->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('berstatus "Selesai"');

        $this->jasa()->putaway($baris->refresh(), 2, 0, 'ZB-02-01', null, null, $operator->id);
    }

    /**
     * Lapis kedua, dan inilah yang sebenarnya menjaga: saat dokumennya masih
     * SEBAGIAN terverifikasi, penjaga status tidak menutup apa pun karena
     * dokumennya memang masih boleh dikerjakan. Yang menahan hanya penanda di
     * barisnya sendiri — tanpa itu, baris yang stoknya sudah masuk bisa
     * dinaikkan ulang dan barang yang sama terhitung dua kali.
     */
    public function test_baris_yang_stoknya_sudah_masuk_tidak_bisa_dinaikkan_ulang(): void
    {
        $order = $this->pesananTerkirim(qty: 10);
        $satu = $order->details()->first();

        // Baris kedua pada pesanan yang sama, dengan batch berbeda.
        $dua = SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => Product::factory()->create([
                'sku' => 'ID1-F00573202825',
                'shelf_life_months' => 24,
            ])->id,
            'qty_ordered' => 6,
            'qty_approved' => 6,
            'qty_shipped' => 6,
            'outstanding_qty' => 0,
        ]);

        PickingListItem::create([
            'picking_list_id' => PickingList::first()->id,
            'sales_order_id' => $order->id,
            'sales_order_detail_id' => $dua->id,
            'product_id' => $dua->product_id,
            'location_id' => Location::first()->id,
            'batch_no' => 'BT-002',
            'production_date' => now()->subMonths(2)->toDateString(),
            'qty_to_pick' => 6,
            'qty_picked' => 6,
            'status' => PickingListItem::STATUS_PICKED,
            'picked_at' => now()->subDay(),
        ]);

        $retur = $this->jasa()->report($order, [
            ['detail_id' => $satu->id, 'qty' => 4],
            ['detail_id' => $dua->id, 'qty' => 2],
        ], 'Dua SKU ditolak customer.', $this->sales->id);

        $barisSatu = $retur->details()->orderBy('id')->first();
        $barisDua = $retur->details()->orderByDesc('id')->first();

        $this->jasa()->approve($retur, [$barisSatu->id => 4, $barisDua->id => 2], null, $this->sales->id);

        $operator = $this->login(Role::WAREHOUSE_OPERATOR);
        $this->bin('ZB-02-01');

        $this->jasa()->putaway($barisSatu, 4, 0, 'ZB-02-01', null, null, $operator->id);
        $this->jasa()->putaway($barisDua, 2, 0, 'ZB-02-01', null, null, $operator->id);

        // Hanya baris pertama yang diverifikasi -> dokumen jadi SEBAGIAN.
        $logistik = $this->login(Role::LOGISTICS);
        $this->jasa()->verify($retur->refresh(), [$barisSatu->id], $logistik->id);

        $this->assertSame(SalesReturn::STATUS_PARTIAL_VERIFIED, $retur->refresh()->status);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah diverifikasi');

        $this->jasa()->putaway($barisSatu->refresh(), 1, 0, 'ZB-02-01', null, null, $operator->id);
    }

    /* ------------------------------------------------------ Batas wewenang */

    public function test_operator_tidak_boleh_menyetujui_maupun_memverifikasi(): void
    {
        $retur = $this->returDisetujui();

        $this->login(Role::WAREHOUSE_OPERATOR);

        $this->post(route('wms.returns.approve', $retur), ['qty' => []])->assertForbidden();
        $this->post(route('wms.returns.verify', $retur), ['baris' => [1]])->assertForbidden();
    }

    public function test_logistik_tidak_boleh_menaikkan_barang_ke_rak(): void
    {
        $retur = $this->returDisetujui();
        $baris = $retur->details()->first();

        $this->login(Role::LOGISTICS);

        $this->post(route('wms.returns.putaway', $baris), [
            'qty_good' => 4, 'qty_ddp' => 0, 'location_good' => 'ZB-02-01',
        ])->assertForbidden();
    }

    public function test_operator_tetap_boleh_melihat_antreannya(): void
    {
        $this->returDisetujui();
        $this->login(Role::WAREHOUSE_OPERATOR);

        $this->get(route('wms.returns.index'))
            ->assertOk()
            ->assertSee('Penolakan Customer');
    }

    public function test_gudang_lain_ditolak(): void
    {
        $retur = $this->returDisetujui();

        $this->login(Role::LOGISTICS, $this->gudangLain);

        $this->get(route('wms.returns.show', $retur))->assertForbidden();
    }

    public function test_riwayat_penolakan_tidak_bisa_dihapus_lewat_pesanan_yang_masih_ada(): void
    {
        $retur = $this->returDisetujui();

        $this->assertDatabaseHas('sales_returns', ['id' => $retur->id]);
        $this->assertSame(1, SalesReturnDetail::where('sales_return_id', $retur->id)->count());
    }

    /* ------------------------------------------- Formulir di sisi Sales */

    /**
     * LUBANG YANG PERNAH ADA: seluruh alur backend sudah jalan, tetapi
     * formulirnya tidak pernah dipasang di halaman Sales — jadi tidak ada
     * satu pun cara memanggilnya dari layar. Test ini yang menahannya.
     */
    public function test_formulir_lapor_penolakan_muncul_di_detail_pesanan_sales(): void
    {
        $order = $this->pesananTerkirim();
        $this->masuk($this->sales);

        $this->get('/sales/orders/'.$order->id)
            ->assertOk()
            ->assertSee('Ada barang yang ditolak')
            ->assertSee('Laporkan Penolakan')
            ->assertSee('/sales/report-return', false);
    }

    public function test_sales_melaporkan_penolakan_lewat_formulir_halaman(): void
    {
        $order = $this->pesananTerkirim();
        $detail = $order->details()->first();

        $this->masuk($this->sales);

        $this->post('/sales/report-return', [
            'order_id' => $order->id,
            'reason' => 'Warna tidak sesuai contoh, customer menolak tiga pail.',
            'qty' => [$detail->id => 3],
        ])->assertRedirect();

        $retur = SalesReturn::where('sales_order_id', $order->id)->firstOrFail();

        $this->assertSame(3, $retur->details()->first()->qty_rejected);
        $this->assertSame(SalesReturn::STATUS_REPORTED, $retur->status);
    }

    /** Baris yang dikosongkan Sales bukan kesalahan — ia hanya tidak ditolak. */
    public function test_baris_yang_dikosongkan_tidak_ikut_dilaporkan(): void
    {
        $order = $this->pesananTerkirim();
        $detail = $order->details()->first();

        $this->masuk($this->sales);

        $this->post('/sales/report-return', [
            'order_id' => $order->id,
            'reason' => 'Hanya sebagian yang ditolak customer.',
            'qty' => [$detail->id => 0],
        ])->assertRedirect();

        $this->assertSame(0, SalesReturn::where('sales_order_id', $order->id)->count());
        $this->assertNotNull(session('error'));
    }

    /** Setelah melapor, formulirnya berganti jadi keadaan laporannya. */
    public function test_setelah_melapor_yang_tampil_adalah_keadaan_laporannya(): void
    {
        $retur = $this->returDisetujui(qtyTolak: 4);

        $this->masuk($this->sales);

        $this->get('/sales/orders/'.$retur->sales_order_id)
            ->assertOk()
            ->assertSee($retur->reference)
            ->assertSee('Menunggu Naik Rak')
            ->assertDontSee('Laporkan Penolakan');
    }

    /** Alasan Logistik menolak klaim WAJIB terlihat Sales tanpa menelepon. */
    public function test_alasan_logistik_menolak_laporan_terlihat_oleh_sales(): void
    {
        $order = $this->pesananTerkirim();

        $retur = $this->jasa()->report(
            $order,
            [['detail_id' => $order->details()->first()->id, 'qty' => 2]],
            'Customer menolak dua unit.',
            $this->sales->id,
        );

        $logistik = $this->login(Role::LOGISTICS);
        $this->jasa()->reject($retur, 'Barangnya tidak pernah sampai gudang.', $logistik->id);

        $this->masuk($this->sales);

        $this->get('/sales/orders/'.$order->id)
            ->assertOk()
            ->assertSee('Laporan ditolak Logistik')
            ->assertSee('Barangnya tidak pernah sampai gudang.');
    }

    public function test_pesanan_yang_belum_berangkat_tidak_menampilkan_formulirnya(): void
    {
        $order = $this->pesananTerkirim();
        $order->forceFill(['status' => SalesOrder::STATUS_PICKING])->save();

        $this->masuk($this->sales);

        $this->get('/sales/orders/'.$order->id)
            ->assertOk()
            ->assertDontSee('Ada barang yang ditolak');
    }

    /* ---------------------------------------------------------- Halaman */

    public function test_halaman_daftar_menampilkan_antrean_dan_istilah_barunya(): void
    {
        $this->returDisetujui();
        $this->login(Role::LOGISTICS);

        $this->get(route('wms.returns.index'))
            ->assertOk()
            ->assertSee('Penolakan Customer')
            ->assertSee('Menunggu Naik Rak')
            ->assertSee('PT Bangun Menara Abadi')
            // Istilah lama yang tidak menyebut peristiwanya.
            ->assertDontSee('Penerimaan Retur');
    }

    public function test_halaman_detail_mengatakan_stok_belum_bertambah(): void
    {
        $retur = $this->returDisetujui();
        $this->login(Role::WAREHOUSE_OPERATOR);

        $this->get(route('wms.returns.show', $retur))
            ->assertOk()
            ->assertSee($retur->reference)
            ->assertSee('belum menambah stok', false);
    }

    /**
     * Formulirnya TERTUTUP sampai diminta, dan itu bukan soal rapi-rapian.
     * Dahulu setiap item pesanan langsung digambar sebagai satu kolom angka;
     * pesanan dua puluh item berarti dua puluh kolom yang harus digulir
     * setiap kali halaman dibuka, padahal penolakan itu perkara yang jarang.
     */
    public function test_formulir_penolakan_tertutup_sampai_dibuka(): void
    {
        $order = $this->pesananTerkirim();
        $this->masuk($this->sales);

        $html = $this->get('/sales/orders/'.$order->id)->assertOk()->getContent();

        $this->assertStringContainsString('id="bukaTolak"', $html);
        $this->assertStringContainsString('id="formTolak" class="d-none"', $html);

        // Tidak ada satu pun kolom qty yang tergambar duluan: baris hanya
        // lahir setelah Sales memilih produknya.
        $this->assertStringNotContainsString('name="qty[', $html);
    }

    public function test_item_penolakan_dicari_sambil_diketik(): void
    {
        $order = $this->pesananTerkirim();
        $this->masuk($this->sales);

        $html = $this->get('/sales/orders/'.$order->id)->assertOk()->getContent();

        $this->assertStringContainsString('templateTolak', $html);
        $this->assertStringContainsString('Ketik SKU atau nama produk', $html);
        $this->assertStringContainsString($this->produk->sku, $html);
    }

    /**
     * Yang tidak berangkat tidak bisa ditolak, dan penjagaannya harus sudah
     * ada di daftar pilihan — bukan cuma di validasi server. Sales tidak
     * boleh sampai mengetik nama produk yang memang tidak pernah dikirim.
     */
    public function test_barang_yang_tidak_berangkat_tidak_muncul_di_pilihan(): void
    {
        $order = $this->pesananTerkirim();

        $lain = Product::factory()->create(['sku' => 'SKU-TIDAK-BERANGKAT']);

        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $lain->id,
            'qty_ordered' => 5,
            'qty_approved' => 5,
            'qty_shipped' => 0,
            'outstanding_qty' => 5,
        ]);

        $this->masuk($this->sales);

        $html = $this->get('/sales/orders/'.$order->id)->assertOk()->getContent();

        // Namanya tetap muncul di kartu Item Pesanan, jadi yang diperiksa
        // adalah isi daftar pilihan formulirnya.
        $this->assertStringNotContainsString('"sku":"SKU-TIDAK-BERANGKAT"', $html);
        $this->assertStringContainsString('"sku":"'.$this->produk->sku.'"', $html);
    }

    /* --------------------------------- Foto Surat Jalan wajib lebih dulu */

    /**
     * LUBANG YANG PERNAH ADA: laporan penolakan bisa dikirim tanpa satu pun
     * foto Surat Jalan. Laporan itu adalah tagihan barang kembali ke gudang,
     * dan Logistik yang menilainya tidak ikut ke toko — satu-satunya hal yang
     * bisa ia periksa adalah Surat Jalan bertanda tangan. Laporan tanpa foto
     * memaksanya memutuskan berdasarkan kalimat saja, dan pada saat fotonya
     * menyusul, barangnya sudah terlanjur dijadwalkan naik rak.
     */
    public function test_laporan_penolakan_tanpa_foto_surat_jalan_ditolak(): void
    {
        $order = $this->pesananTerkirim(denganBukti: false);
        $detail = $order->details()->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unggah dulu foto Surat Jalan');

        $this->jasa()->report(
            $order,
            [['detail_id' => $detail->id, 'qty' => 2]],
            'Warna tidak sesuai contoh, customer menolak sebagian.',
            $this->sales->id,
        );
    }

    public function test_tidak_ada_laporan_yang_tersimpan_saat_fotonya_belum_ada(): void
    {
        $order = $this->pesananTerkirim(denganBukti: false);
        $detail = $order->details()->first();

        $this->masuk($this->sales);

        $this->post('/sales/report-return', [
            'order_id' => $order->id,
            'reason' => 'Warna tidak sesuai contoh, customer menolak sebagian.',
            'qty' => [$detail->id => 2],
        ])->assertSessionHas('error');

        $this->assertSame(0, SalesReturn::query()->count());
    }

    /**
     * Foto yang sudah DITOLAK Logistik tidak dihitung: laporan yang bersandar
     * padanya berarti bersandar pada bukti yang sudah dinyatakan tidak sah.
     */
    public function test_foto_yang_sudah_ditolak_logistik_tidak_membuka_formulir(): void
    {
        $order = $this->pesananTerkirim();
        $logistik = $this->login(Role::LOGISTICS);

        $order->proofs()->first()->forceFill([
            'status' => DeliveryProof::STATUS_REJECTED,
            'rejection_reason' => 'Tanda tangan pelanggan tidak terbaca.',
            'verified_by' => $logistik->id,
            'verified_at' => now(),
        ])->save();

        $this->assertFalse(
            $this->jasa()->bolehMelapor($order->refresh(), $this->sales),
            'Bukti yang sudah ditolak bukan bukti.',
        );
    }

    /**
     * Formulirnya tidak sekadar hilang: halaman MENGATAKAN kenapa. Kartu yang
     * lenyap tanpa keterangan terbaca sebagai fitur yang rusak, dan Sales
     * menelepon Logistik untuk sesuatu yang bisa dijawab satu kalimat.
     */
    public function test_halaman_mengatakan_fotonya_harus_diunggah_dulu(): void
    {
        $order = $this->pesananTerkirim(denganBukti: false);

        $this->masuk($this->sales);

        $this->get('/sales/orders/'.$order->id)
            ->assertOk()
            ->assertSee('Ada barang yang ditolak customer?')
            ->assertSee('Unggah dulu foto Surat Jalan')
            // Formulirnya sendiri belum ada.
            ->assertDontSee('id="formTolak"', false);
    }
}
