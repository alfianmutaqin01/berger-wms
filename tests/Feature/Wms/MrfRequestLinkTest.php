<?php

namespace Tests\Feature\Wms;

use App\Models\Department;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\MaterialRequisition;
use App\Models\MrfRequestLink;
use App\Models\PickingList;
use App\Models\Product;
use App\Models\ProductionMaterialConsumption;
use App\Models\ProductionMaterialHolding;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Outbound\PickingRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Permintaan material dari divisi yang TIDAK punya akun WMS.
 *
 * QC dan R&D meminta material beberapa kali setahun. Membuatkan mereka akun
 * berarti satu peran baru dengan matriks izinnya sendiri dan kata sandi yang
 * pasti lupa; jalur ini memakai tautan bertoken, pola yang sudah dipakai
 * atasan yang menyetujui MRF dan supir yang mengisi ePOD.
 *
 * EMPAT HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * ---------------------------------------------------
 * 1. HALAMANNYA TERBUKA KE INTERNET. Token yang tidak dikenal atau sudah
 *    dinonaktifkan harus dijawab 404 polos — membedakan keduanya memberi tahu
 *    penebak bahwa ia sedang mendekati token yang benar.
 * 2. ATASAN YANG DIKUNCI TIDAK BOLEH BISA DIGANTI lewat permintaan HTTP
 *    langsung. Tanpa itu, pemegang tautan mengetik nomornya sendiri dan
 *    menyetujui permintaannya sendiri — dan seluruh pemeriksaan jadi hiasan.
 * 3. BARANGNYA SELESAI SAAT DIAMBIL, tidak menumpuk di daftar sisa berjalan
 *    sebagai baris 1-2 pcs yang tidak pernah ditutup siapa pun.
 * 4. TETAPI TETAP MASUK RIWAYAT. Kalau tidak, Logistik kehilangan satu-satunya
 *    cara menelusuri ke mana barang itu pergi.
 */
class MrfRequestLinkTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $gudang;

    private Location $rak;

    private Product $produk;

    private Department $qc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->withProduction()->create(['code' => 'ID11_1001', 'name' => 'Karawang']);
        $this->rak = Location::factory()->create([
            'warehouse_id' => $this->gudang->id, 'code' => 'A-01-01', 'is_active' => true,
        ]);
        $this->produk = Product::factory()->create(['sku' => 'APKO-001', 'uom' => 'PAIL', 'is_active' => true]);
        // Migrasi sudah membuat QC dan R&D; test ini tidak boleh berpura-pura
        // merekalah yang pertama membuatnya.
        $this->qc = Department::firstOrCreate(
            ['slug' => 'qc'],
            ['name' => 'Quality Control', 'is_active' => true],
        );
    }

    /* --------------------------------------------------------------- Bantu */

    private function tautan(array $ganti = []): MrfRequestLink
    {
        return MrfRequestLink::create(array_merge([
            'warehouse_id' => $this->gudang->id,
            'department_id' => $this->qc->id,
            'token' => MrfRequestLink::tokenBaru(),
            'approver_name' => 'Bu Rina',
            'approver_phone' => '6281234567890',
            'is_active' => true,
        ], $ganti));
    }

    private function loginAs(string $slug): User
    {
        $user = User::factory()->withRole($slug)->create(['warehouse_id' => $this->gudang->id]);
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

    private function isiFormulir(MrfRequestLink $tautan, array $ganti = [])
    {
        return $this->post(route('mrf.minta.store', $tautan->token), array_merge([
            'requester_name' => 'Andi QC',
            'requester_phone' => '081298765432',
            'request_type' => MaterialRequisition::TYPE_TESTING,
            'purpose' => 'Uji tahan cuaca untuk klaim pelanggan PT ABC.',
            'items' => [['product_id' => $this->produk->id, 'qty' => 2]],
        ], $ganti));
    }

    /** Sampai barangnya turun dari rak dan menunggu pemohon. */
    private function sampaiSiapDiambil(MrfRequestLink $tautan): MaterialRequisition
    {
        $this->isiFormulir($tautan);
        $mrf = MaterialRequisition::latest('id')->firstOrFail();

        $this->post(route('mrf.approval.approve', $mrf->approval_token), ['note' => null]);

        $stok = InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->gudang->id,
            'location_id' => $this->rak->id,
            'batch_no' => 'BT-2601',
            'production_date' => '2026-01-15',
            'expiry_date' => '2028-01-15',
            'qty_available' => 50,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);

        $this->loginAs(Role::LOGISTICS);
        $this->post(route('wms.mrf.approve', $mrf->refresh()), [
            'baris' => [['item_id' => $mrf->items()->firstOrFail()->id, 'stock_id' => $stok->id, 'qty' => 2]],
        ]);

        $operator = $this->loginAs(Role::WAREHOUSE_OPERATOR);
        $daftar = PickingList::findOrFail($mrf->refresh()->picking_list_id);
        $picking = app(PickingRun::class);

        $picking->claim($daftar, $operator);
        foreach ($daftar->items as $baris) {
            $picking->pick($baris, $operator);
        }

        $transit = Location::where('warehouse_id', $this->gudang->id)
            ->where('zone', Location::ZONE_TRANSIT_LOGISTIK)->firstOrFail();

        $this->post(route('wms.picking.complete', $daftar), ['handover_location_id' => $transit->id]);

        return $mrf->refresh();
    }

    /* ------------------------------------------------------ Halaman publik */

    public function test_formulir_terbuka_tanpa_login(): void
    {
        $tautan = $this->tautan();

        $this->get(route('mrf.minta.show', $tautan->token))
            ->assertOk()
            ->assertSee('Quality Control')
            // Atasan sudah dikunci, jadi isiannya tidak ditawarkan sama sekali.
            ->assertSee('Bu Rina')
            ->assertDontSee('name="approver_phone"', false);
    }

    /**
     * Tautan mati dan tautan yang tidak pernah ada dijawab SAMA.
     *
     * Membedakan keduanya memberi tahu penebak bahwa ia sedang mendekati token
     * yang benar.
     */
    public function test_token_tidak_dikenal_dan_tautan_mati_sama_sama_404(): void
    {
        $mati = $this->tautan(['is_active' => false]);

        $this->get(route('mrf.minta.show', $mati->token))->assertNotFound();
        $this->get(route('mrf.minta.show', Str::random(64)))->assertNotFound();
        $this->post(route('mrf.minta.store', $mati->token), [])->assertNotFound();
    }

    public function test_permintaan_lewat_tautan_tersimpan_sebagai_mrf_biasa(): void
    {
        $tautan = $this->tautan();

        $this->isiFormulir($tautan)->assertRedirect();

        $mrf = MaterialRequisition::firstOrFail();

        $this->assertSame(MaterialRequisition::STATUS_PENDING_APPROVAL, $mrf->status);
        $this->assertSame($tautan->id, $mrf->request_link_id);
        // TANPA akun pemohon: memalsukannya sebagai akun siapa pun membuat
        // dokumen ini berbohong soal siapa yang meminta.
        $this->assertNull($mrf->requested_by);
        $this->assertSame('Andi QC', $mrf->requester_name);
        $this->assertSame('Quality Control', $mrf->department_name);
        $this->assertSame('6281298765432', $mrf->requester_phone);
        $this->assertStringStartsWith('MRF', $mrf->mrf_number);
        $this->assertTrue($mrf->lewatTautan());
    }

    /**
     * Atasan yang dikunci tidak bisa diganti lewat permintaan HTTP langsung.
     *
     * Ini pagar yang membuat tautan publik aman: tanpanya, pemegang tautan
     * mengetik nomornya sendiri, menerima tautan persetujuannya, lalu
     * menyetujui permintaannya sendiri.
     */
    public function test_atasan_yang_dikunci_tidak_bisa_ditimpa(): void
    {
        $tautan = $this->tautan();

        $this->isiFormulir($tautan, [
            'approver_name' => 'Diri Sendiri',
            'approver_phone' => '081200000000',
        ]);

        $mrf = MaterialRequisition::firstOrFail();

        $this->assertSame('Bu Rina', $mrf->approver_name);
        $this->assertSame('6281234567890', $mrf->approver_phone);
    }

    /** Tautan tanpa atasan tetap menuntut pengisi menyebutkan satu. */
    public function test_tautan_tanpa_atasan_menuntut_pengisi_menyebutkannya(): void
    {
        $tautan = $this->tautan(['approver_name' => null, 'approver_phone' => null]);

        $this->isiFormulir($tautan)->assertSessionHas('error');

        $this->assertSame(0, MaterialRequisition::count());

        $this->isiFormulir($tautan, [
            'approver_name' => 'Pak Budi',
            'approver_phone' => '081211112222',
        ])->assertRedirect();

        $this->assertSame('Pak Budi', MaterialRequisition::firstOrFail()->approver_name);
    }

    public function test_nomor_whatsapp_pemohon_yang_tidak_terbaca_ditolak(): void
    {
        $tautan = $this->tautan();

        $this->isiFormulir($tautan, ['requester_phone' => 'bukan nomor'])
            ->assertSessionHas('error');

        $this->assertSame(0, MaterialRequisition::count());
    }

    /** Pencarian produk tidak membocorkan isi gudang. */
    public function test_pencarian_produk_tidak_menyebut_stok(): void
    {
        $tautan = $this->tautan();

        $this->getJson(route('mrf.minta.produk', $tautan->token).'?q=APKO')
            ->assertOk()
            ->assertJsonStructure([['id', 'sku', 'name', 'uom']])
            ->assertJsonMissingPath('0.qty_available');

        $this->getJson(route('mrf.minta.produk', Str::random(64)).'?q=APKO')->assertNotFound();
    }

    /* ------------------------------------------------- Akhir: selesai saat diambil */

    /**
     * Barangnya BERHENTI di "siap diambil", tidak langsung berpindah tangan.
     *
     * Pemohonnya tidak punya akun dan tidak berdiri di gudang — ia baru
     * dikabari lewat WhatsApp bahwa barangnya bisa diambil.
     */
    public function test_serah_terima_berhenti_di_siap_diambil(): void
    {
        $mrf = $this->sampaiSiapDiambil($this->tautan());

        $this->assertSame(MaterialRequisition::STATUS_READY_FOR_PICKUP, $mrf->status);
        $this->assertNotNull($mrf->handover_location_id);
        $this->assertNull($mrf->received_at);
        // Belum masuk buku pemakaian: tidak ada yang memegangnya.
        $this->assertSame(0, ProductionMaterialHolding::count());
    }

    /**
     * Saat diambil: selesai sekaligus, dan tercatat di riwayat.
     *
     * Divisi lain lazimnya minta satu-dua pcs yang langsung habis. Barisnya
     * tidak boleh menumpuk di daftar sisa berjalan, tetapi juga tidak boleh
     * hilang dari penelusuran — jadi ia lahir dan habis dalam satu langkah.
     */
    public function test_saat_diambil_langsung_selesai_dan_masuk_riwayat(): void
    {
        $mrf = $this->sampaiSiapDiambil($this->tautan());

        $this->loginAs(Role::LOGISTICS);

        $this->post(route('wms.mrf.collect', $mrf), ['collected_by_name' => 'Andi QC'])
            ->assertSessionHas('success');

        $mrf->refresh();

        $this->assertSame(MaterialRequisition::STATUS_RECEIVED, $mrf->status);
        $this->assertSame('Andi QC', $mrf->collected_by_name);

        $holding = ProductionMaterialHolding::firstOrFail();

        $this->assertSame(2, $holding->qty_received);
        $this->assertSame(2, $holding->qty_consumed);
        $this->assertNotNull($holding->finished_at, 'Habis sekaligus — tidak menunggak di daftar sisa.');

        $pakai = ProductionMaterialConsumption::firstOrFail();

        $this->assertSame(2, $pakai->qty);
        $this->assertStringContainsString('Andi QC', $pakai->note);
        $this->assertStringContainsString('Quality Control', $pakai->note);
    }

    public function test_riwayat_pemakaian_memuat_permintaan_lewat_tautan(): void
    {
        $mrf = $this->sampaiSiapDiambil($this->tautan());

        $this->loginAs(Role::LOGISTICS);
        $this->post(route('wms.mrf.collect', $mrf), ['collected_by_name' => 'Andi QC']);

        $this->get(route('wms.material-produksi.riwayat'))
            ->assertOk()
            ->assertSee('APKO-001')
            ->assertSee($mrf->mrf_number)
            ->assertViewHas('stats', fn (array $s) => $s['baris'] === 1 && $s['unit'] === 2);
    }

    public function test_nama_pengambil_wajib_diisi(): void
    {
        $mrf = $this->sampaiSiapDiambil($this->tautan());

        $this->loginAs(Role::LOGISTICS);

        $this->post(route('wms.mrf.collect', $mrf), ['collected_by_name' => ''])
            ->assertSessionHasErrors('collected_by_name');

        $this->assertSame(MaterialRequisition::STATUS_READY_FOR_PICKUP, $mrf->refresh()->status);
    }

    /** MRF dari akun tidak lewat jalur ini — ia tidak pernah "siap diambil". */
    public function test_mrf_dari_akun_tidak_bisa_ditutup_lewat_tombol_diambil(): void
    {
        $tautan = $this->tautan();
        $mrf = $this->sampaiSiapDiambil($tautan);

        // Dipaksa seolah datang dari akun.
        $mrf->forceFill(['request_link_id' => null])->save();

        $this->loginAs(Role::LOGISTICS);

        $this->post(route('wms.mrf.collect', $mrf), ['collected_by_name' => 'Andi QC'])
            ->assertNotFound();
    }

    /* --------------------------------------------------------- Pengelolaan */

    public function test_super_admin_menerbitkan_dan_menonaktifkan_tautan(): void
    {
        $rnd = Department::firstOrCreate(['slug' => 'rnd'], ['name' => 'Research & Development', 'is_active' => true]);

        $this->loginAs(Role::SUPER_ADMIN);

        $this->post(route('wms.admin.mrf-link.store'), [
            'warehouse_id' => $this->gudang->id,
            'department_id' => $rnd->id,
            'approver_name' => 'Pak Dedi',
            'approver_phone' => '081233334444',
        ])->assertSessionHas('success');

        $tautan = MrfRequestLink::where('department_id', $rnd->id)->firstOrFail();

        $this->assertTrue($tautan->atasanTerkunci());
        $this->assertSame(64, strlen($tautan->token));
        $this->get(route('mrf.minta.show', $tautan->token))->assertOk();

        // Dua tautan untuk divisi yang sama ditolak: dua alamat yang beredar
        // sekaligus membuat pencabutan tidak pernah pasti mengenai yang dipakai.
        $this->post(route('wms.admin.mrf-link.store'), [
            'warehouse_id' => $this->gudang->id,
            'department_id' => $rnd->id,
        ])->assertSessionHas('error');

        $this->assertSame(1, MrfRequestLink::where('department_id', $rnd->id)->count());

        $this->put(route('wms.admin.mrf-link.update', $tautan), ['aksi' => 'nonaktifkan']);
        $this->get(route('mrf.minta.show', $tautan->fresh()->token))->assertNotFound();
    }

    /** Terbitkan ulang: alamat lamanya mati seketika. */
    public function test_terbitkan_ulang_mematikan_alamat_lama(): void
    {
        $tautan = $this->tautan();
        $lama = $tautan->token;

        $this->loginAs(Role::SUPER_ADMIN);
        $this->put(route('wms.admin.mrf-link.update', $tautan), ['aksi' => 'terbitkan_ulang']);

        $baru = $tautan->fresh();

        $this->assertNotSame($lama, $baru->token);
        $this->get(route('mrf.minta.show', $lama))->assertNotFound();
        $this->get(route('mrf.minta.show', $baru->token))->assertOk();
    }

    public function test_selain_super_admin_tidak_bisa_mengatur_tautan(): void
    {
        $this->loginAs(Role::LOGISTICS);

        $this->post(route('wms.admin.mrf-link.store'), [
            'warehouse_id' => $this->gudang->id,
            'department_id' => $this->qc->id,
        ])->assertForbidden();
    }
}
