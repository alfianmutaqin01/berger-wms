<?php

namespace Tests\Feature\Wms;

use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\MaterialRequisition;
use App\Models\MrfApproverContact;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\Product;
use App\Models\ProductionMaterialHolding;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Outbound\PickingRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MRF — permintaan material Produksi ke Logistik.
 *
 * EMPAT HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * ---------------------------------------------------
 * 1. BARANG YANG DIAMBIL PRODUKSI BUKAN PENJUALAN. Mutasinya wajib
 *    PRODUCTION_OUT, bukan OUT. Kalau tertukar, laporan penjualan jadi lebih
 *    besar daripada yang benar-benar terjual dan selisihnya tidak akan pernah
 *    bisa dijelaskan.
 * 2. BUKU BESAR HARUS TETAP SETARA. Jumlah seluruh mutasi satu baris stok
 *    harus sama dengan qty_available-nya. Alur MRF menulis tiga mutasi
 *    (ALLOCATED, DEALLOCATED, PRODUCTION_OUT) dan salah satu yang terlewat
 *    tidak akan terlihat di layar mana pun.
 * 3. SISA DI TANGAN PRODUKSI TIDAK BOLEH HILANG. Justru inilah sebab fitur
 *    ini dibuat: 300 diminta, 150 dikerjakan, dan sisa 150 selama ini cuma
 *    diingat.
 * 4. URUTAN PERSETUJUAN TIDAK BOLEH BISA DILOMPATI. Logistik yang memproses
 *    permintaan sebelum atasan menyetujui membuat seluruh persetujuan itu
 *    hiasan belaka.
 */
class MaterialRequisitionTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $karawang;

    private Location $rak;

    private Location $rakSerah;

    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->karawang = Warehouse::factory()->withProduction()->create(['code' => 'WH-01', 'name' => 'Karawang']);

        $this->rak = Location::factory()->create([
            'warehouse_id' => $this->karawang->id, 'code' => 'A-01-01', 'is_active' => true,
        ]);
        // Titik serah terima MRF selalu rak transit, bukan rak penyimpanan —
        // keduanya dibuat sendiri untuk tiap gudang baru.
        $this->rakSerah = Location::where('warehouse_id', $this->karawang->id)
            ->where('zone', Location::ZONE_TRANSIT_PRODUKSI)
            ->firstOrFail();

        $this->produk = Product::factory()->create(['sku' => 'APKO-001', 'uom' => 'PAIL', 'is_active' => true]);
    }

    /* --------------------------------------------------------------- Bantu */

    private function loginAt(string $slug, ?Warehouse $gudang = null): User
    {
        $user = User::factory()->withRole($slug)->create([
            'warehouse_id' => ($gudang ?? $this->karawang)->id,
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

    /**
     * Masuk sebagai pengguna yang SUDAH ada — biasanya pemohon MRF-nya.
     *
     * loginAt() selalu membuat orang baru, dan perbaikan MRF hanya boleh
     * dikerjakan pemohonnya sendiri.
     */
    private function masukSebagai(int $userId): User
    {
        $user = User::findOrFail($userId);
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

    private function stok(int $qty, array $atribut = []): InventoryStock
    {
        return InventoryStock::factory()->create(array_merge([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->karawang->id,
            'location_id' => $this->rak->id,
            'batch_no' => 'BT-2601',
            'production_date' => '2026-01-15',
            'expiry_date' => '2028-01-15',
            'qty_available' => $qty,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ], $atribut));
    }

    /** Produksi menyusun permintaan lewat formulirnya yang sungguhan. */
    private function ajukan(int $qty = 300, array $ganti = []): MaterialRequisition
    {
        $this->loginAt(Role::PRODUCTION);

        $this->post(route('wms.mrf.store'), array_merge([
            'warehouse_id' => $this->karawang->id,
            'request_type' => MaterialRequisition::TYPE_REPROSES,
            'purpose' => 'Reproses DDP batch Juli menjadi warna Off White.',
            'approver_name' => 'Pak Ganti',
            'approver_phone' => '081234567890',
            'items' => [['product_id' => $this->produk->id, 'qty' => $qty]],
        ], $ganti));

        return MaterialRequisition::latest('id')->firstOrFail();
    }

    /** Atasan menekan Setuju di tautan WhatsApp — tanpa akun, tanpa login. */
    private function disetujuiAtasan(MaterialRequisition $mrf): MaterialRequisition
    {
        // Sesi Produksi sengaja dibuang: halaman ini memang dibuka orang yang
        // tidak punya akun, dan test yang menjalankannya sambil masih login
        // tidak membuktikan apa-apa soal itu.
        $this->flushSession();
        auth()->logout();

        $this->post(route('mrf.approval.approve', $mrf->approval_token), ['note' => null])
            ->assertRedirect();

        return $mrf->refresh();
    }

    /** Logistik memilih batch dan menyetujui. */
    private function disetujuiLogistik(MaterialRequisition $mrf, InventoryStock $stok, int $qty): MaterialRequisition
    {
        $this->loginAt(Role::LOGISTICS);

        $item = $mrf->items()->firstOrFail();

        $this->post(route('wms.mrf.approve', $mrf), [
            'baris' => [
                ['item_id' => $item->id, 'stock_id' => $stok->id, 'qty' => $qty],
            ],
        ]);

        return $mrf->refresh();
    }

    /**
     * Operator mengerjakan daftar picking sampai menekan Siap Loading.
     *
     * Menempuh PickingRun yang sungguhan, bukan menulis langsung ke kolom
     * status: yang memindahkan barangnya memang mesin picking, dan meniru
     * efeknya dengan tangan membuat test hijau untuk sesuatu yang tidak
     * pernah dijalankan sistem.
     */
    private function pickingSelesai(MaterialRequisition $mrf, ?int $ditemukan = null): MaterialRequisition
    {
        $operator = User::factory()->withRole(Role::WAREHOUSE_OPERATOR)->create([
            'warehouse_id' => $mrf->warehouse_id,
        ]);

        $daftar = PickingList::findOrFail($mrf->picking_list_id);
        $picking = app(PickingRun::class);

        $picking->claim($daftar, $operator);

        foreach ($daftar->items as $baris) {
            if ($ditemukan !== null && $ditemukan < $baris->qty_to_pick) {
                $picking->reportShort($baris, $operator, $ditemukan, 'Di rak cuma segini.');
            } else {
                $picking->pick($baris, $operator);
            }
        }

        $picking->complete($daftar->refresh(), $operator, $this->rakSerah->id, 'Tiga palet di sisi kiri.');

        return $mrf->refresh();
    }

    /* ------------------------------------------------------ Pengajuan MRF */

    public function test_produksi_mengajukan_permintaan_material(): void
    {
        $mrf = $this->ajukan(300);

        $this->assertSame(MaterialRequisition::STATUS_PENDING_APPROVAL, $mrf->status);
        $this->assertSame(MaterialRequisition::TYPE_REPROSES, $mrf->request_type);
        $this->assertSame(300, $mrf->items()->sum('qty_requested'));

        // Nomor atasan dinormalkan ke bentuk yang dikenal WhatsApp. Disimpan
        // apa adanya ("08…"), tautannya tidak akan pernah sampai.
        $this->assertSame('6281234567890', $mrf->approver_phone);

        // Token wajib acak dan panjang: tautan yang bisa ditebak dari nomor
        // urut membuat siapa pun menyetujui permintaan orang lain.
        $this->assertNotNull($mrf->approval_token);
        $this->assertSame(64, strlen($mrf->approval_token));
    }

    public function test_keperluan_wajib_diisi(): void
    {
        $this->loginAt(Role::PRODUCTION);

        $this->post(route('wms.mrf.store'), [
            'warehouse_id' => $this->karawang->id,
            'request_type' => MaterialRequisition::TYPE_SAMPLE,
            'purpose' => '',
            'approver_name' => 'Pak Parji',
            'approver_phone' => '081234567890',
            'items' => [['product_id' => $this->produk->id, 'qty' => 5]],
        ])->assertSessionHasErrors('purpose');

        $this->assertSame(0, MaterialRequisition::count());
    }

    public function test_nomor_approver_bisa_disimpan_untuk_permintaan_berikutnya(): void
    {
        $this->ajukan(10, ['simpan_kontak' => 1]);

        $kontak = MrfApproverContact::firstOrFail();

        $this->assertSame('Pak Ganti', $kontak->name);
        $this->assertSame('6281234567890', $kontak->phone);
        $this->assertSame($this->karawang->id, $kontak->warehouse_id);

        // Nomor yang sama tidak boleh menumpuk jadi dua pilihan kembar: yang
        // memilih di antara keduanya tidak punya cara tahu mana yang benar.
        $this->ajukan(10, ['simpan_kontak' => 1]);

        $this->assertSame(1, MrfApproverContact::count());
    }

    public function test_tanpa_dicentang_nomor_tidak_ikut_tersimpan(): void
    {
        $this->ajukan(10);

        $this->assertSame(0, MrfApproverContact::count());
    }

    /* ------------------------------------------------- Persetujuan atasan */

    public function test_atasan_menyetujui_lewat_tautan_tanpa_login(): void
    {
        $mrf = $this->disetujuiAtasan($this->ajukan());

        $this->assertSame(MaterialRequisition::STATUS_PENDING_LOGISTICS, $mrf->status);
        $this->assertNotNull($mrf->approved_at);
    }

    public function test_atasan_menolak_dengan_alasan_wajib(): void
    {
        $mrf = $this->ajukan();

        $this->flushSession();
        auth()->logout();

        // Tanpa alasan: ditolak formulirnya, bukan diam-diam tersimpan kosong.
        $this->post(route('mrf.approval.reject', $mrf->approval_token), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(MaterialRequisition::STATUS_PENDING_APPROVAL, $mrf->refresh()->status);

        $this->post(route('mrf.approval.reject', $mrf->approval_token), [
            'reason' => 'Bahan bakunya belum datang, tunda dulu minggu depan.',
        ])->assertRedirect();

        $mrf->refresh();

        $this->assertSame(MaterialRequisition::STATUS_REJECTED_APPROVAL, $mrf->status);
        $this->assertStringContainsString('Bahan bakunya belum datang', $mrf->approver_rejection_reason);
    }

    public function test_tautan_yang_sudah_diputus_tidak_bisa_ditekan_dua_kali(): void
    {
        $mrf = $this->disetujuiAtasan($this->ajukan());

        $this->post(route('mrf.approval.reject', $mrf->approval_token), [
            'reason' => 'Berubah pikiran setelah menekan setuju.',
        ]);

        // Tetap pada keputusan pertama. Kalau keputusan kedua menang, satu
        // permintaan yang sudah diteruskan ke Logistik bisa dibatalkan diam-
        // diam dari tautan yang beredar di grup WhatsApp.
        $this->assertSame(MaterialRequisition::STATUS_PENDING_LOGISTICS, $mrf->refresh()->status);
    }

    public function test_token_asing_dijawab_404_polos(): void
    {
        $this->get('/mrf/'.Str::random(64))->assertNotFound();
    }

    /* ----------------------------------------------- Persetujuan Logistik */

    public function test_logistik_tidak_bisa_memproses_sebelum_atasan_menyetujui(): void
    {
        $mrf = $this->ajukan(300);
        $stok = $this->stok(500);

        $this->disetujuiLogistik($mrf, $stok, 300);

        $this->assertSame(MaterialRequisition::STATUS_PENDING_APPROVAL, $mrf->refresh()->status);
        $this->assertSame(0, $mrf->allocations()->count());

        // Stoknya tidak boleh tersentuh sama sekali.
        $this->assertSame(500, $stok->refresh()->qty_available);
        $this->assertSame(0, $stok->qty_allocated);
    }

    public function test_logistik_memilih_batch_dan_stoknya_dicadangkan(): void
    {
        $mrf = $this->disetujuiAtasan($this->ajukan(300));
        $stok = $this->stok(500);

        $mrf = $this->disetujuiLogistik($mrf, $stok, 300);

        $this->assertSame(MaterialRequisition::STATUS_PENDING_PICKING, $mrf->status);
        $this->assertNotNull($mrf->picking_list_id);

        // DICADANGKAN, bukan dikurangi: barangnya masih di rak, tetapi
        // berhenti bisa dijanjikan ke pelanggan mana pun.
        $stok->refresh();
        $this->assertSame(200, $stok->qty_available);
        $this->assertSame(300, $stok->qty_allocated);

        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => StockMovement::TYPE_ALLOCATED,
            'reference_type' => StockMovement::REF_MATERIAL_REQUISITION,
            'reference_id' => $mrf->id,
            'qty_change' => -300,
        ]);

        // Barisnya masuk antrean operator, lengkap dengan rak dan batch-nya.
        $baris = PickingListItem::where('picking_list_id', $mrf->picking_list_id)->firstOrFail();
        $this->assertSame(300, $baris->qty_to_pick);
        $this->assertSame($this->rak->id, $baris->location_id);
        $this->assertNull($baris->sales_order_id);
    }

    public function test_alokasi_tidak_boleh_melebihi_yang_diminta(): void
    {
        $mrf = $this->disetujuiAtasan($this->ajukan(100));
        $stok = $this->stok(500);

        $this->disetujuiLogistik($mrf, $stok, 300);

        $this->assertSame(MaterialRequisition::STATUS_PENDING_LOGISTICS, $mrf->refresh()->status);
        $this->assertSame(500, $stok->refresh()->qty_available);
    }

    public function test_logistik_menolak_dengan_alasan(): void
    {
        $mrf = $this->disetujuiAtasan($this->ajukan());

        $this->loginAt(Role::LOGISTICS);

        $this->post(route('wms.mrf.reject', $mrf), [
            'reason' => 'Batch yang diminta sudah dialokasikan ke PO yang berangkat besok.',
        ])->assertRedirect();

        $this->assertSame(MaterialRequisition::STATUS_REJECTED_LOGISTICS, $mrf->refresh()->status);
    }

    /* ----------------------------------------------------------- Picking */

    public function test_daftar_mrf_tidak_bisa_diselesaikan_tanpa_menyebut_rak_serah_terima(): void
    {
        $mrf = $this->disetujuiAtasan($this->ajukan(300));
        $stok = $this->stok(500);
        $mrf = $this->disetujuiLogistik($mrf, $stok, 300);

        $operator = $this->loginAt(Role::WAREHOUSE_OPERATOR);
        $daftar = PickingList::findOrFail($mrf->picking_list_id);

        $picking = app(PickingRun::class);
        $picking->claim($daftar, $operator);
        $daftar->items->each(fn ($baris) => $picking->pick($baris, $operator));

        $this->post(route('wms.picking.complete', $daftar))
            ->assertSessionHasErrors('handover_location_id');

        // Barangnya HARUS masih di rak. Kalau daftarnya telanjur selesai
        // tanpa alamat, barang yang sudah turun berdiri tanpa keterangan —
        // persis keadaan yang hendak dihapus fitur ini.
        $this->assertSame(MaterialRequisition::STATUS_PENDING_PICKING, $mrf->refresh()->status);
        $this->assertSame(200, $stok->refresh()->qty_available);
    }

    public function test_setelah_picking_barangnya_keluar_stok_sebagai_mutasi_produksi(): void
    {
        $mrf = $this->disetujuiAtasan($this->ajukan(300));
        $stok = $this->stok(500);
        $mrf = $this->disetujuiLogistik($mrf, $stok, 300);

        $mrf = $this->pickingSelesai($mrf);

        // LANGSUNG DITERIMA. Serah terima di layar operator terjadi bersamaan
        // dengan serah terima sungguhan di lantai gudang, jadi di situlah
        // kepemilikannya berpindah — tidak ada lagi konfirmasi susulan yang
        // harus ditekan seseorang di Produksi untuk barang yang sudah dibawa.
        $this->assertSame(MaterialRequisition::STATUS_RECEIVED, $mrf->status);
        $this->assertSame($this->rakSerah->id, $mrf->handover_location_id);

        $stok->refresh();
        $this->assertSame(200, $stok->qty_available);
        $this->assertSame(0, $stok->qty_allocated);

        // PRODUCTION_OUT, BUKAN OUT. Material yang diambil Produksi tidak
        // pernah sampai ke pelanggan mana pun; menghitungnya sebagai OUT
        // membuat laporan penjualan lebih besar daripada yang benar-benar
        // terjual, dan selisih itu tidak akan pernah bisa dijelaskan.
        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => StockMovement::TYPE_PRODUCTION_OUT,
            'reference_type' => StockMovement::REF_MATERIAL_REQUISITION,
            'reference_id' => $mrf->id,
            'qty_change' => -300,
        ]);

        $this->assertDatabaseMissing('stock_movements', [
            'movement_type' => StockMovement::TYPE_OUT,
            'reference_id' => $mrf->id,
            'reference_type' => StockMovement::REF_MATERIAL_REQUISITION,
        ]);
    }

    public function test_buku_besar_tetap_setara_dengan_qty_available(): void
    {
        $mrf = $this->disetujuiAtasan($this->ajukan(300));
        $stok = $this->stok(500);
        $mrf = $this->disetujuiLogistik($mrf, $stok, 300);
        $this->pickingSelesai($mrf);

        // Baris stoknya lahir lewat factory, jadi 500 awalnya tidak punya
        // mutasi. Yang diperiksa: seluruh mutasi MRF berjumlah persis sebesar
        // perubahan yang terjadi pada baris itu.
        $jumlahMutasi = (int) StockMovement::where('reference_type', StockMovement::REF_MATERIAL_REQUISITION)
            ->where('reference_id', $mrf->id)
            ->sum('qty_change');

        $this->assertSame(-300, $jumlahMutasi);
        $this->assertSame(200, $stok->refresh()->qty_available);
    }

    public function test_selisih_picking_terbaca_di_dokumen_mrf(): void
    {
        $mrf = $this->disetujuiAtasan($this->ajukan(300));
        $stok = $this->stok(500);
        $mrf = $this->disetujuiLogistik($mrf, $stok, 300);

        // Di rak ternyata cuma 280.
        $mrf = $this->pickingSelesai($mrf, ditemukan: 280);

        $alokasi = $mrf->allocations()->firstOrFail();

        $this->assertSame(300, $alokasi->qty_allocated);
        $this->assertSame(280, $alokasi->qty_picked);
        $this->assertSame(20, $alokasi->qty_kurang);
        $this->assertStringContainsString('cuma segini', $alokasi->discrepancy_reason);
    }

    /* -------------------------------------------------- Penerimaan & pakai */

    /**
     * Serah terima operator LANGSUNG melahirkan baris di buku Produksi.
     *
     * Dulu barangnya menggantung di "siap diambil" sampai seseorang di
     * Produksi membuka WMS dan menekan Diterima — langkah yang dikerjakan di
     * depan layar, jauh dari barang yang sudah berpindah tangan di lantai
     * gudang. Yang terjadi kemudian selalu sama: barangnya sudah dibawa,
     * tombolnya tidak pernah ditekan, dan buku Produksi kosong sementara stok
     * gudang sudah berkurang.
     */
    public function test_serah_terima_langsung_memindahkan_barang_ke_buku_produksi(): void
    {
        $mrf = $this->disetujuiAtasan($this->ajukan(300));
        $stok = $this->stok(500);
        $mrf = $this->disetujuiLogistik($mrf, $stok, 300);
        $mrf = $this->pickingSelesai($mrf);

        $this->assertSame(MaterialRequisition::STATUS_RECEIVED, $mrf->refresh()->status);

        $holding = ProductionMaterialHolding::firstOrFail();

        $this->assertSame(300, $holding->qty_received);
        $this->assertSame(0, $holding->qty_consumed);
        // Area awalnya dibaca dari titik serah terima yang dipilih operator —
        // keterangan pembuka yang boleh dipindahkan sendiri oleh Produksi.
        $this->assertSame(Location::ZONE_TRANSIT_PRODUKSI, $holding->production_area);
        $this->assertSame('BT-2601', $holding->batch_no);
        $this->assertNull($holding->finished_at);

        // TIDAK ADA MUTASI BARU saat kepemilikannya berpindah: stoknya sudah
        // berkurang waktu barangnya turun dari rak. Mutasi kedua akan
        // mengurangi barang yang sama untuk kedua kalinya.
        $this->assertSame(1, StockMovement::where('reference_type', StockMovement::REF_MATERIAL_REQUISITION)
            ->where('movement_type', StockMovement::TYPE_PRODUCTION_OUT)
            ->count());
    }

    public function test_pemakaian_bertahap_tercatat_sampai_habis(): void
    {
        $holding = $this->sampaiDiterima(300);

        $this->loginAt(Role::PRODUCTION);

        // Setengahnya dulu — keadaan yang persis dikeluhkan pemilik produk.
        $this->post(route('wms.material-produksi.consume', $holding), [
            'qty' => 150,
            'note' => 'Batch pertama reproses.',
        ])->assertRedirect();

        $holding->refresh();

        $this->assertSame(150, $holding->qty_consumed);
        $this->assertSame(150, $holding->qty_sisa);
        $this->assertNull($holding->finished_at, 'Masih ada sisa, jadi belum boleh ditutup.');

        $this->post(route('wms.material-produksi.consume', $holding), ['qty' => 150]);

        $holding->refresh();

        $this->assertSame(300, $holding->qty_consumed);
        $this->assertSame(0, $holding->qty_sisa);
        $this->assertNotNull($holding->finished_at, 'Sudah habis, jadi barisnya wajib ditutup.');

        // Riwayatnya utuh: dua kali pemakaian, masing-masing bertanggal. Kolom
        // sisa saja tahu keadaan hari ini dan melupakan jalan menuju ke sana.
        $this->assertSame(2, $holding->consumptions()->count());
    }

    public function test_pemakaian_tidak_boleh_melebihi_sisa(): void
    {
        $holding = $this->sampaiDiterima(300);

        $this->loginAt(Role::PRODUCTION);

        $this->post(route('wms.material-produksi.consume', $holding), ['qty' => 301])
            ->assertSessionHas('error');

        $this->assertSame(0, $holding->refresh()->qty_consumed);
    }

    public function test_sisa_yang_menua_tetap_terbaca_di_layar_produksi(): void
    {
        $holding = $this->sampaiDiterima(300);

        $this->loginAt(Role::PRODUCTION);

        $this->post(route('wms.material-produksi.consume', $holding), ['qty' => 150]);

        // Diterima 45 hari lalu dan belum habis — inilah baris yang selama ini
        // hilang dari ingatan, dan yang harus muncul sendiri tanpa dicari.
        $holding->refresh()->forceFill(['received_at' => now()->subDays(45)])->save();

        $this->get(route('wms.material-produksi.index'))
            ->assertOk()
            ->assertSee('APKO-001')
            ->assertSee('45 hari belum habis');
    }

    /* ------------------------------------------------------------ Batalkan */

    public function test_pembatalan_sebelum_picking_melepas_cadangan(): void
    {
        $mrf = $this->disetujuiAtasan($this->ajukan(300));
        $stok = $this->stok(500);
        $mrf = $this->disetujuiLogistik($mrf, $stok, 300);

        $this->loginAt(Role::PRODUCTION);

        $this->post(route('wms.mrf.cancel', $mrf), [
            'reason' => 'Rencana reprosesnya batal, bahan pendukungnya belum ada.',
        ])->assertRedirect();

        $mrf->refresh();
        $stok->refresh();

        $this->assertSame(MaterialRequisition::STATUS_CANCELLED, $mrf->status);
        $this->assertSame(500, $stok->qty_available, 'Cadangannya wajib kembali utuh.');
        $this->assertSame(0, $stok->qty_allocated);

        // DEALLOCATED, bukan IN. Barangnya tidak pernah keluar; menulis mutasi
        // masuk akan menambah barang yang tidak pernah berkurang.
        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => StockMovement::TYPE_DEALLOCATED,
            'reference_type' => StockMovement::REF_MATERIAL_REQUISITION,
            'reference_id' => $mrf->id,
        ]);

        // Daftar picking-nya ikut bubar. Dibiarkan hidup, ia menggantung di
        // antrean sebagai tugas yang tidak menuju ke mana-mana.
        $this->assertSame(
            PickingList::STATUS_CANCELLED,
            PickingList::findOrFail($mrf->picking_list_id)->status
        );
    }

    public function test_tidak_bisa_dibatalkan_setelah_barangnya_turun_dari_rak(): void
    {
        $mrf = $this->disetujuiAtasan($this->ajukan(300));
        $stok = $this->stok(500);
        $mrf = $this->disetujuiLogistik($mrf, $stok, 300);
        $mrf = $this->pickingSelesai($mrf);

        $this->loginAt(Role::PRODUCTION);

        $this->post(route('wms.mrf.cancel', $mrf), [
            'reason' => 'Ternyata tidak jadi dipakai.',
        ])->assertSessionHas('error');

        $this->assertSame(MaterialRequisition::STATUS_RECEIVED, $mrf->refresh()->status);
    }

    public function test_daftar_picking_mrf_tidak_bisa_dibubarkan_dari_layar_batching(): void
    {
        $mrf = $this->disetujuiAtasan($this->ajukan(300));
        $stok = $this->stok(500);
        $mrf = $this->disetujuiLogistik($mrf, $stok, 300);

        $this->loginAt(Role::LOGISTICS);

        $daftar = PickingList::findOrFail($mrf->picking_list_id);

        $this->post(route('wms.picking.cancel', $daftar), [
            'cancellation_reason' => 'Salah susun, mau diulang.',
        ])->assertSessionHas('error');

        // Barisnya harus utuh: membubarkannya akan menghapus baris picking dan
        // meninggalkan barangnya tercadang di rak tanpa ada yang bisa
        // mengambilnya, selamanya, tanpa satu layar pun yang menjelaskan.
        $this->assertSame(PickingList::STATUS_OPEN, $daftar->refresh()->status);
        $this->assertSame(1, $daftar->items()->count());
        $this->assertSame(300, $stok->refresh()->qty_allocated);
    }

    /* ----------------------------------------------------------- Hak akses */

    public function test_logistik_tidak_bisa_membuat_permintaan_atas_nama_produksi(): void
    {
        $this->loginAt(Role::LOGISTICS);

        $this->get(route('wms.mrf.create'))->assertForbidden();
    }

    public function test_produksi_tidak_bisa_menyetujui_permintaannya_sendiri(): void
    {
        $mrf = $this->disetujuiAtasan($this->ajukan(300));
        $stok = $this->stok(500);

        $this->loginAt(Role::PRODUCTION);

        $this->get(route('wms.mrf.approve.form', $mrf))->assertForbidden();

        $item = $mrf->items()->firstOrFail();

        $this->post(route('wms.mrf.approve', $mrf), [
            'baris' => [['item_id' => $item->id, 'stock_id' => $stok->id, 'qty' => 300]],
        ])->assertForbidden();

        $this->assertSame(MaterialRequisition::STATUS_PENDING_LOGISTICS, $mrf->refresh()->status);
    }

    public function test_operator_boleh_membaca_mrf_tetapi_tidak_memutus(): void
    {
        $mrf = $this->disetujuiAtasan($this->ajukan(300));

        $this->loginAt(Role::WAREHOUSE_OPERATOR);

        // Operator mengerjakan daftar picking-nya; tanpa akses membaca
        // dokumennya ia mengambil barang tanpa tahu untuk siapa.
        $this->get(route('wms.mrf.show', $mrf))->assertOk();
        $this->get(route('wms.mrf.approve.form', $mrf))->assertForbidden();
    }

    /* --------------------------------------------------------------- Bantu */

    /** Menempuh seluruh alur sampai barangnya tercatat di buku Produksi. */
    private function sampaiDiterima(int $qty): ProductionMaterialHolding
    {
        $mrf = $this->disetujuiAtasan($this->ajukan($qty));
        $stok = $this->stok($qty + 200);
        $mrf = $this->disetujuiLogistik($mrf, $stok, $qty);
        // Serah terima operator sudah memindahkan kepemilikannya; tidak ada
        // lagi tombol Diterima yang perlu ditekan Produksi.
        $this->pickingSelesai($mrf);

        return ProductionMaterialHolding::latest('id')->firstOrFail();
    }

    /* ==================================================== Nomor dokumen */

    /**
     * MRF{YYMM}{urut}, bukan MR{YYMMDD}{urut}.
     *
     * Dokumen ini disebut MRF oleh semua orang yang memakainya, dan tanggal di
     * dalam nomornya tidak pernah menjawab pertanyaan siapa pun — permintaan
     * material diajukan sekitar tiga bulan sekali.
     */
    /**
     * Kode gudang dipendekkan untuk yang dibaca manusia: ID11_1001 -> ID11.
     *
     * Akhiran "_1001" sama untuk ketiga gudang, jadi ia tidak membedakan apa
     * pun — ia hanya mendorong nama gudangnya keluar layar pada HP.
     */
    public function test_kode_gudang_dipendekkan_di_formulir_mrf(): void
    {
        $this->karawang->update(['code' => 'ID11_1001']);

        $this->loginAt(Role::SUPER_ADMIN);

        $this->get(route('wms.mrf.create'))
            ->assertOk()
            ->assertSee('ID11')
            ->assertDontSee('ID11_1001');

        $this->assertSame('ID11', $this->karawang->refresh()->kode_pendek);
        // Kode penuh tidak ikut berubah: ia yang dipakai impor dan ekspor.
        $this->assertSame('ID11_1001', $this->karawang->code);
    }

    /** Gudang tanpa akhiran tetap terbaca utuh, bukan terpotong. */
    public function test_kode_gudang_tanpa_akhiran_tidak_berubah(): void
    {
        $this->assertSame('WH-01', $this->karawang->kode_pendek);
    }

    public function test_nomor_mrf_memakai_awalan_mrf_tanpa_tanggal(): void
    {
        $mrf = $this->ajukan(100);

        $this->assertMatchesRegularExpression('/^MRF\d{4}\d{3}$/', $mrf->mrf_number);
        $this->assertSame('MRF'.now()->format('ym').'001', $mrf->mrf_number);
    }

    public function test_nomor_mrf_kedua_di_bulan_yang_sama_tidak_berebut(): void
    {
        $pertama = $this->ajukan(100);
        $kedua = $this->ajukan(150);

        $this->assertSame('MRF'.now()->format('ym').'001', $pertama->mrf_number);
        $this->assertSame('MRF'.now()->format('ym').'002', $kedua->mrf_number);
    }

    /* ============================================ Serah terima ke transit */

    /** Gudang baru lahir dengan kedua titik serah terimanya. */
    public function test_gudang_baru_langsung_punya_rak_transit(): void
    {
        $baru = Warehouse::factory()->create(['code' => 'WH-77']);

        $this->assertSame(
            [Location::ZONE_TRANSIT_LOGISTIK, Location::ZONE_TRANSIT_PRODUKSI],
            Location::where('warehouse_id', $baru->id)->transit()->orderBy('zone')->pluck('zone')->all(),
        );
    }

    /**
     * Rak transit tidak boleh muncul di layar yang menempatkan stok.
     *
     * Barang di sana sudah bukan milik gudang; menawarkannya untuk put-away
     * akan menumpuk barang baru di atas barang yang sedang berpindah tangan.
     */
    public function test_rak_transit_tidak_ikut_pilihan_rak_penyimpanan(): void
    {
        $transit = Location::where('warehouse_id', $this->karawang->id)->transit()->pluck('id');

        $this->assertNotEmpty($transit);
        $this->assertEmpty(
            Location::penyimpanan()->whereIn('id', $transit)->pluck('id')->all(),
            'Rak transit tidak boleh lolos scope penyimpanan.',
        );
        $this->assertContains(
            $this->rak->id,
            Location::penyimpanan()->pluck('id')->all(),
            'Rak penyimpanan biasa harus tetap ada.',
        );
    }

    /**
     * Rak biasa tetap boleh dipilih, dan areanya memakai KODE raknya.
     *
     * Titik transit didahulukan di layar karena ke situlah barangnya hampir
     * selalu pergi, tetapi kenyataan di lantai gudang tidak selalu begitu —
     * dan daftar yang menolak menyebutkan tempat sebenarnya hanya melahirkan
     * catatan yang tidak cocok dengan keadaan.
     */
    public function test_serah_terima_ke_rak_biasa_memakai_kode_raknya(): void
    {
        $mrf = $this->disetujuiAtasan($this->ajukan(300));
        $stok = $this->stok(500);
        $mrf = $this->disetujuiLogistik($mrf, $stok, 300);

        $operator = $this->loginAt(Role::WAREHOUSE_OPERATOR);
        $daftar = PickingList::findOrFail($mrf->picking_list_id);
        $picking = app(PickingRun::class);

        $picking->claim($daftar, $operator);
        foreach ($daftar->items as $baris) {
            $picking->pick($baris, $operator);
        }

        $this->post(route('wms.picking.complete', $daftar), [
            // Rak penyimpanan biasa, bukan titik transit.
            'handover_location_id' => $this->rak->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(MaterialRequisition::STATUS_RECEIVED, $mrf->refresh()->status);
        $this->assertSame($this->rak->id, $mrf->handover_location_id);
        // Rak biasa disebut dengan kodenya, bukan dengan nama zonanya —
        // "Fast Moving Area" bukan alamat yang bisa didatangi siapa pun.
        $this->assertSame('A-01-01', ProductionMaterialHolding::firstOrFail()->production_area);
    }

    /** Rak gudang lain tetap ditolak: yang dijaga adalah batas gudangnya. */
    public function test_serah_terima_ke_rak_gudang_lain_ditolak(): void
    {
        $mrf = $this->disetujuiAtasan($this->ajukan(300));
        $stok = $this->stok(500);
        $mrf = $this->disetujuiLogistik($mrf, $stok, 300);

        $lain = Warehouse::factory()->create(['code' => 'WH-99']);
        $rakLain = Location::factory()->create(['warehouse_id' => $lain->id, 'is_active' => true]);

        $operator = $this->loginAt(Role::WAREHOUSE_OPERATOR);
        $daftar = PickingList::findOrFail($mrf->picking_list_id);
        $picking = app(PickingRun::class);

        $picking->claim($daftar, $operator);
        foreach ($daftar->items as $baris) {
            $picking->pick($baris, $operator);
        }

        $this->post(route('wms.picking.complete', $daftar), [
            'handover_location_id' => $rakLain->id,
        ])->assertSessionHasErrors('handover_location_id');

        $this->assertSame(MaterialRequisition::STATUS_PENDING_PICKING, $mrf->refresh()->status);
    }

    /* ================================================= Pindah area produksi */

    public function test_produksi_memindahkan_materialnya_ke_area_lain(): void
    {
        $holding = $this->sampaiDiterima(300);

        $this->loginAt(Role::PRODUCTION);

        $this->post(route('wms.material-produksi.move', $holding), [
            'production_area' => 'Lantai 2 Tinting',
        ])->assertSessionHas('success');

        $this->assertSame('Lantai 2 Tinting', $holding->refresh()->production_area);
        // Yang pindah hanya alamatnya. Jumlahnya tidak ikut berubah.
        $this->assertSame(300, $holding->qty_received);
        $this->assertSame(0, $holding->qty_consumed);
    }

    public function test_material_yang_sudah_habis_tidak_bisa_dipindahkan(): void
    {
        $holding = $this->sampaiDiterima(300);

        $this->loginAt(Role::PRODUCTION);
        $this->post(route('wms.material-produksi.consume', $holding), ['qty' => 300]);

        $this->post(route('wms.material-produksi.move', $holding), [
            'production_area' => 'Lantai 2 Tinting',
        ])->assertSessionHas('error');

        $this->assertNotSame('Lantai 2 Tinting', $holding->refresh()->production_area);
    }

    /* ================================= Siapa mengerjakan apa di Produksi */

    /**
     * Produksi bukan satu orang.
     *
     * Yang meminta, yang menerima, yang memindahkan, dan yang mencatat
     * pemakaian bisa empat orang berbeda — dan pertanyaan yang muncul
     * berbulan-bulan kemudian selalu berbentuk "siapa yang memegang ini".
     */
    public function test_setiap_tindakan_di_produksi_tercatat_pelakunya(): void
    {
        $holding = $this->sampaiDiterima(300);

        $pemindah = $this->loginAt(Role::PRODUCTION);
        $this->post(route('wms.material-produksi.move', $holding), ['production_area' => 'Lantai 2 Tinting']);

        $pencatat = $this->loginAt(Role::PRODUCTION);
        $this->post(route('wms.material-produksi.consume', $holding), ['qty' => 10, 'note' => 'Batch pertama.']);

        $holding->refresh();

        $this->assertSame($pemindah->id, $holding->area_moved_by);
        $this->assertNotNull($holding->area_moved_at);
        $this->assertSame($pencatat->id, $holding->consumptions()->latest('id')->firstOrFail()->consumed_by);
        // Pemindah dan pencatat memang orang yang berbeda — itu intinya.
        $this->assertNotSame($pemindah->id, $pencatat->id);
    }

    public function test_layar_mrf_picked_menyebut_nama_pencatat_pemakaian(): void
    {
        $holding = $this->sampaiDiterima(300);

        $pencatat = $this->loginAt(Role::PRODUCTION);
        $this->post(route('wms.material-produksi.consume', $holding), ['qty' => 10]);

        $this->get(route('wms.material-produksi.index'))
            ->assertOk()
            ->assertSee($pencatat->full_name);
    }

    /* ============================================= Riwayat pemakaian MRF */

    /**
     * Material yang sudah HABIS tetap bisa ditelusuri.
     *
     * Daftar MRF Picked menjawab "apa yang masih ada di tangan Produksi", dan
     * baris yang habis wajar menghilang dari sana. Riwayatnya menjawab
     * pertanyaan yang berbeda, dan jawabannya tidak boleh ikut hilang.
     */
    public function test_pemakaian_material_yang_sudah_habis_tetap_terbaca_di_riwayat(): void
    {
        $holding = $this->sampaiDiterima(300);

        $pencatat = $this->loginAt(Role::PRODUCTION);
        $this->post(route('wms.material-produksi.consume', $holding), ['qty' => 300, 'note' => 'Habis sekali jalan.']);

        $this->assertNotNull($holding->refresh()->finished_at, 'Materialnya memang sudah habis.');

        $this->get(route('wms.material-produksi.riwayat'))
            ->assertOk()
            ->assertSee('BT-2601')
            ->assertSee($pencatat->full_name)
            ->assertSee('Habis sekali jalan.')
            ->assertViewHas('stats', fn (array $s) => $s['baris'] === 1 && $s['unit'] === 300);
    }

    public function test_riwayat_pemakaian_bisa_disaring_dan_menolak_tanggal_mustahil(): void
    {
        $holding = $this->sampaiDiterima(300);

        $this->loginAt(Role::PRODUCTION);
        $this->post(route('wms.material-produksi.consume', $holding), ['qty' => 50, 'note' => 'Uji warna.']);

        $this->get(route('wms.material-produksi.riwayat', ['search' => 'BT-2601']))
            ->assertOk()->assertSee('Uji warna.');

        $this->get(route('wms.material-produksi.riwayat', ['search' => 'TIDAK-ADA-SKU']))
            ->assertOk()->assertDontSee('Uji warna.');

        // Tanggal mustahil diabaikan, bukan menjatuhkan halaman.
        foreach (['2026-13-45', 'abc', "' OR 1=1 --"] as $salah) {
            $this->get(route('wms.material-produksi.riwayat', ['dari' => $salah, 'sampai' => $salah]))
                ->assertOk()
                ->assertViewHas('filters', fn (array $f) => $f['dari'] === null && $f['sampai'] === null);
        }
    }

    /** Riwayat gudang lain bukan urusan siapa pun di gudang ini. */
    public function test_riwayat_pemakaian_gudang_lain_tidak_terbaca(): void
    {
        $holding = $this->sampaiDiterima(300);

        $this->loginAt(Role::PRODUCTION);
        $this->post(route('wms.material-produksi.consume', $holding), ['qty' => 50, 'note' => 'Uji warna.']);

        $lain = Warehouse::factory()->withProduction()->create(['code' => 'WH-88']);
        $this->loginAt(Role::PRODUCTION, $lain);

        $this->get(route('wms.material-produksi.riwayat'))
            ->assertOk()
            ->assertDontSee('Uji warna.')
            ->assertViewHas('stats', fn (array $s) => $s['baris'] === 0);
    }

    /* ================================================== Pengajuan ulang */

    /** Ditolak atasan lalu diperbaiki: nomornya tetap, alurnya diulang. */
    public function test_mrf_yang_ditolak_atasan_bisa_diperbaiki_dan_diajukan_ulang(): void
    {
        $mrf = $this->ajukan(300);
        $nomor = $mrf->mrf_number;
        $tokenLama = $mrf->approval_token;

        $this->flushSession();
        auth()->logout();
        $this->post(route('mrf.approval.reject', $mrf->approval_token), [
            'reason' => 'Qty-nya kebanyakan untuk satu batch.',
        ]);

        $this->assertSame(MaterialRequisition::STATUS_REJECTED_APPROVAL, $mrf->refresh()->status);

        $this->masukSebagai($mrf->requested_by);

        $this->put(route('wms.mrf.update', $mrf), [
            'request_type' => MaterialRequisition::TYPE_REPROSES,
            'purpose' => 'Reproses DDP batch Juli — qty diturunkan sesuai catatan atasan.',
            'approver_name' => 'Pak Ganti',
            'approver_phone' => '081234567890',
            'items' => [['product_id' => $this->produk->id, 'qty' => 120]],
        ])->assertRedirect(route('wms.mrf.show', $mrf));

        $mrf->refresh();

        $this->assertSame($nomor, $mrf->mrf_number, 'Nomornya dipakai ulang, bukan diganti.');
        $this->assertSame(MaterialRequisition::STATUS_PENDING_APPROVAL, $mrf->status);
        $this->assertSame(120, $mrf->items()->firstOrFail()->qty_requested);
        $this->assertSame(1, $mrf->items()->count(), 'Baris lama ditulis ulang, bukan ditumpuk.');

        // Tautan lama sudah dipakai untuk menolak; membiarkannya hidup berarti
        // keputusan lama masih bisa ditekan ulang atas berkas yang berbeda.
        $this->assertNotSame($tokenLama, $mrf->approval_token);
        $this->assertNull($mrf->approver_rejected_at);

        // Jejak penolakannya TIDAK hilang.
        $this->assertSame(1, $mrf->rejections()->count());
        $this->assertSame('Qty-nya kebanyakan untuk satu batch.', $mrf->rejections()->firstOrFail()->reason);
    }

    public function test_mrf_yang_ditolak_logistik_juga_bisa_diajukan_ulang(): void
    {
        $mrf = $this->disetujuiAtasan($this->ajukan(300));

        $this->loginAt(Role::LOGISTICS);
        $this->post(route('wms.mrf.reject', $mrf), ['reason' => 'Batchnya sedang dikarantina.']);

        $this->assertSame(MaterialRequisition::STATUS_REJECTED_LOGISTICS, $mrf->refresh()->status);

        $this->masukSebagai($mrf->requested_by);

        $this->get(route('wms.mrf.edit', $mrf))->assertOk()->assertSee($mrf->mrf_number);

        $this->put(route('wms.mrf.update', $mrf), [
            'request_type' => MaterialRequisition::TYPE_REPROSES,
            'purpose' => 'Diajukan ulang setelah batch lain tersedia.',
            'approver_name' => 'Pak Ganti',
            'approver_phone' => '081234567890',
            'items' => [['product_id' => $this->produk->id, 'qty' => 300]],
        ])->assertRedirect();

        $this->assertSame(MaterialRequisition::STATUS_PENDING_APPROVAL, $mrf->refresh()->status);
        $this->assertNull($mrf->logistics_rejected_at);
        $this->assertSame(1, $mrf->rejections()->count());
    }

    /** Yang belum ditolak tidak boleh disunting — isinya sudah jadi dasar keputusan. */
    public function test_mrf_yang_belum_ditolak_tidak_bisa_disunting(): void
    {
        $mrf = $this->ajukan(300);

        $this->masukSebagai($mrf->requested_by);

        $this->get(route('wms.mrf.edit', $mrf))->assertForbidden();
    }

    /** Permintaan orang lain bukan milik siapa pun yang kebetulan sedepartemen. */
    public function test_mrf_orang_lain_tidak_bisa_disunting(): void
    {
        $mrf = $this->ajukan(300);

        $this->flushSession();
        auth()->logout();
        $this->post(route('mrf.approval.reject', $mrf->approval_token), ['reason' => 'Belum perlu.']);

        // Orang Produksi yang berbeda.
        $this->loginAt(Role::PRODUCTION);

        $this->get(route('wms.mrf.edit', $mrf->refresh()))->assertForbidden();
    }
}
