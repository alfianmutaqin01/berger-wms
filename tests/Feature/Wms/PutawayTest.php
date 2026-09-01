<?php

namespace Tests\Feature\Wms;

use App\Models\InboundDetail;
use App\Models\InboundHeader;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Put-away — PRD §6.3 F-INB-02.
 *
 * Dua aturan yang paling dijaga di sini:
 *   1. Put-away boleh SEBAGIAN — palet yang belum ditempatkan tidak boleh
 *      menggagalkan penyimpanan palet yang sudah.
 *   2. Status dokumen hanya naik ke verifikasi bila SELURUH palet punya lokasi.
 */
class PutawayTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create(['code' => 'WH-01']);
    }

    private function loginAs(string $roleSlug = Role::WAREHOUSE_OPERATOR): User
    {
        $user = User::factory()->withRole($roleSlug)->create();
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

    private function bin(string $code, ?Warehouse $warehouse = null, bool $active = true): Location
    {
        $parts = Location::parseCode($code);

        return Location::factory()->create([
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'code' => $code,
            'rack' => $parts['rack'],
            'level' => $parts['level'],
            'cell' => $parts['cell'],
            'zone' => Location::ZONE_FAST,
            'is_active' => $active,
        ]);
    }

    /** Dokumen dua palet: 180 dan 55, dari satu baris produksi 235 pcs. */
    private function makeDocument(array $overrides = []): InboundHeader
    {
        $header = InboundHeader::factory()->create(array_merge([
            'warehouse_id' => $this->warehouse->id,
            'document_number' => 'IN-260901-001',
            'status' => InboundHeader::STATUS_PUTAWAY_PENDING,
        ], $overrides));

        $product = Product::factory()->create(['max_qty_per_pallet' => 180, 'uom' => 'TIN']);

        foreach ([[1, 180], [2, 55]] as [$no, $qty]) {
            InboundDetail::factory()->create([
                'inbound_header_id' => $header->id,
                'product_id' => $product->id,
                'production_order_no' => 'RMO26080294',
                'batch_no' => 'I126080071',
                'total_qty' => 235,
                'pallet_no' => $no,
                'pallet_qty' => $qty,
            ]);
        }

        return $header;
    }

    /* ---------------------------------------------------------------- Akses */

    public function test_operator_dan_super_admin_boleh_membuka_putaway(): void
    {
        foreach ([Role::WAREHOUSE_OPERATOR, Role::SUPER_ADMIN] as $slug) {
            $this->loginAs($slug);
            $this->get('/wms/inbound/putaway')->assertOk()->assertViewHas('documents');
        }
    }

    public function test_role_tanpa_hak_ditolak(): void
    {
        foreach ([Role::PRODUCTION, Role::LOGISTICS, Role::MANAGER] as $slug) {
            $this->loginAs($slug);
            $this->get('/wms/inbound/putaway')->assertForbidden();
        }
    }

    /* --------------------------------------------------------------- Daftar */

    public function test_hanya_dokumen_menunggu_putaway_yang_tampil(): void
    {
        $this->loginAs();
        $this->makeDocument();
        InboundHeader::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'document_number' => 'IN-260901-002',
            'status' => InboundHeader::STATUS_VERIFIED,
        ]);

        $documents = $this->get('/wms/inbound/putaway')->viewData('documents');

        $this->assertCount(1, $documents);
        $this->assertSame('IN-260901-001', $documents->first()->document_number);
    }

    public function test_daftar_menunjukkan_kemajuan_penempatan(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $header->details()->first()->update(['location_id' => $this->bin('B-01-01')->id]);

        $doc = $this->get('/wms/inbound/putaway')->viewData('documents')->first();

        $this->assertSame(2, $doc->details_count);
        $this->assertSame(1, $doc->details_placed_count);
    }

    public function test_dokumen_terlama_didahulukan(): void
    {
        $this->loginAs();
        $this->makeDocument(['document_number' => 'IN-260828-001', 'production_date' => '2026-08-28']);
        $this->makeDocument(['document_number' => 'IN-260801-001', 'production_date' => '2026-08-01']);

        $documents = $this->get('/wms/inbound/putaway')->viewData('documents');

        $this->assertSame('IN-260801-001', $documents->first()->document_number);
    }

    /* ---------------------------------------------------------------- Layar */

    public function test_layar_proses_hanya_menawarkan_bin_gudang_dokumen(): void
    {
        $this->loginAs();
        $this->makeDocument();
        $this->bin('B-01-01');
        $gudangLain = Warehouse::factory()->create(['code' => 'WH-02']);
        $this->bin('B-01-02', $gudangLain);

        $locations = $this->get('/wms/inbound/putaway/IN-260901-001')->assertOk()->viewData('locations');

        $this->assertSame(['B-01-01'], $locations->pluck('code')->all());
    }

    public function test_bin_nonaktif_tidak_ditawarkan(): void
    {
        $this->loginAs();
        $this->makeDocument();
        $this->bin('B-01-01');
        $this->bin('B-01-02', active: false);

        $locations = $this->get('/wms/inbound/putaway/IN-260901-001')->viewData('locations');

        $this->assertSame(['B-01-01'], $locations->pluck('code')->all());
    }

    public function test_isi_bin_menampilkan_produk_qty_kapasitas_dan_satuan(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $bin = $this->bin('B-01-01');
        $palet = $header->details()->first();
        $palet->update(['location_id' => $bin->id, 'qty_actual' => 178]);

        $occupancy = $this->get('/wms/inbound/putaway/IN-260901-001')->viewData('occupancy');

        $this->assertSame([
            'product_id' => $palet->product_id,
            'qty' => 178,
            'capacity' => 180,
            'uom' => 'TIN',
        ], $occupancy['B-01-01']);
    }

    /**
     * $locations TIDAK LAGI menyaring bin yang sudah terisi — ketersediaannya
     * kini tergantung SKU baris yang sedang diisi (bisa berbeda per baris),
     * jadi penyaringan dipindah ke sisi klien. Server hanya menyaring
     * berdasarkan gudang & status aktif.
     */
    public function test_locations_memuat_seluruh_bin_aktif_termasuk_yang_sudah_terisi(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $terisi = $this->bin('B-01-01');
        $kosong = $this->bin('B-01-02');
        $header->details()->first()->update(['location_id' => $terisi->id]);

        $locations = $this->get('/wms/inbound/putaway/IN-260901-001')->viewData('locations');

        $this->assertSame(['B-01-01', 'B-01-02'], $locations->pluck('code')->all());
    }

    public function test_dokumen_yang_sudah_terverifikasi_tidak_bisa_diproses_ulang(): void
    {
        $this->loginAs();
        $this->makeDocument(['status' => InboundHeader::STATUS_VERIFIED]);

        $this->get('/wms/inbound/putaway/IN-260901-001')->assertNotFound();
    }

    /* -------------------------------------------------------------- Simpan */

    public function test_menyimpan_seluruh_palet_menaikkan_status_ke_verifikasi(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $satu = $this->bin('B-01-01');
        $dua = $this->bin('B-01-02');
        [$paletA, $paletB] = $header->details()->orderBy('pallet_no')->get()->all();

        $this->post('/wms/inbound/putaway/IN-260901-001', [
            'pallets' => [
                $paletA->id => ['location_code' => 'B-01-01', 'qty_actual' => 180],
                $paletB->id => ['location_code' => 'B-01-02', 'qty_actual' => 55],
            ],
        ])->assertRedirect('/wms/inbound/putaway');

        $this->assertSame($satu->id, $paletA->fresh()->location_id);
        $this->assertSame($dua->id, $paletB->fresh()->location_id);
        $this->assertSame(InboundHeader::STATUS_VERIFICATION_PENDING, $header->fresh()->status);
    }

    /**
     * Put-away sebagian tidak boleh menaikkan status.
     *
     * Kalau status naik saat baru separuh palet ditempatkan, Logistik akan
     * memverifikasi barang yang belum ada di raknya.
     */
    public function test_putaway_sebagian_tersimpan_tapi_status_tetap(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $this->bin('B-01-01');
        [$paletA, $paletB] = $header->details()->orderBy('pallet_no')->get()->all();

        $this->post('/wms/inbound/putaway/IN-260901-001', [
            'pallets' => [
                $paletA->id => ['location_code' => 'B-01-01', 'qty_actual' => 180],
                $paletB->id => ['location_code' => '', 'qty_actual' => 55],
            ],
        ])->assertRedirect('/wms/inbound/putaway/IN-260901-001');

        $this->assertNotNull($paletA->fresh()->location_id);
        $this->assertNull($paletB->fresh()->location_id);
        $this->assertSame(InboundHeader::STATUS_PUTAWAY_PENDING, $header->fresh()->status);
    }

    public function test_jejak_operator_dan_waktu_tercatat(): void
    {
        $operator = $this->loginAs();
        $header = $this->makeDocument();
        $this->bin('B-01-01');
        $palet = $header->details()->first();

        $this->post('/wms/inbound/putaway/IN-260901-001', [
            'pallets' => [$palet->id => ['location_code' => 'B-01-01', 'qty_actual' => 180]],
        ]);

        $palet->refresh();
        $this->assertSame($operator->id, $palet->putaway_by);
        $this->assertNotNull($palet->putaway_at);
    }

    public function test_qty_aktual_boleh_berbeda_dari_qty_sistem(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $this->bin('B-01-01');
        $palet = $header->details()->where('pallet_qty', 180)->first();

        $this->post('/wms/inbound/putaway/IN-260901-001', [
            'pallets' => [$palet->id => ['location_code' => 'B-01-01', 'qty_actual' => 178]],
        ]);

        $palet->refresh();
        $this->assertSame(178, $palet->qty_actual);
        $this->assertSame(-2, $palet->qty_variance);
    }

    public function test_kode_lokasi_tidak_dikenal_ditolak(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $palet = $header->details()->first();

        $this->post('/wms/inbound/putaway/IN-260901-001', [
            'pallets' => [$palet->id => ['location_code' => 'Z-99-99', 'qty_actual' => 180]],
        ])->assertSessionHasErrors("pallets.{$palet->id}.location_code");

        $this->assertNull($palet->fresh()->location_id);
    }

    /**
     * Bin milik gudang lain ditolak meski kodenya ada.
     *
     * Penamaan rak berulang antar gudang, jadi salah pilih gudang tidak akan
     * terlihat sampai barangnya dicari dan tidak ada di sana.
     */
    public function test_bin_gudang_lain_ditolak(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $gudangLain = Warehouse::factory()->create(['code' => 'WH-02']);
        $this->bin('B-01-01', $gudangLain);
        $palet = $header->details()->first();

        $this->post('/wms/inbound/putaway/IN-260901-001', [
            'pallets' => [$palet->id => ['location_code' => 'B-01-01', 'qty_actual' => 180]],
        ])->assertSessionHasErrors("pallets.{$palet->id}.location_code");
    }

    public function test_lokasi_terisi_tanpa_qty_aktual_ditolak(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $this->bin('B-01-01');
        $palet = $header->details()->first();

        $this->post('/wms/inbound/putaway/IN-260901-001', [
            'pallets' => [$palet->id => ['location_code' => 'B-01-01', 'qty_actual' => null]],
        ])->assertSessionHasErrors("pallets.{$palet->id}.qty_actual");

        $this->assertNull($palet->fresh()->location_id);
    }

    public function test_tanpa_satupun_lokasi_ditolak_dengan_pesan(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $palet = $header->details()->first();

        $this->post('/wms/inbound/putaway/IN-260901-001', [
            'pallets' => [$palet->id => ['location_code' => '', 'qty_actual' => 180]],
        ])->assertSessionHas('error');

        $this->assertSame(InboundHeader::STATUS_PUTAWAY_PENDING, $header->fresh()->status);
    }

    /** Palet milik dokumen lain tidak boleh ikut terubah lewat kiriman form. */
    public function test_palet_dokumen_lain_diabaikan(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $lain = $this->makeDocument(['document_number' => 'IN-260901-002']);
        $this->bin('B-01-01');

        $milikSendiri = $header->details()->first();
        $milikOrangLain = $lain->details()->first();

        $this->post('/wms/inbound/putaway/IN-260901-001', [
            'pallets' => [
                $milikSendiri->id => ['location_code' => 'B-01-01', 'qty_actual' => 180],
                $milikOrangLain->id => ['location_code' => 'B-01-01', 'qty_actual' => 180],
            ],
        ]);

        $this->assertNotNull($milikSendiri->fresh()->location_id);
        $this->assertNull($milikOrangLain->fresh()->location_id);
    }

    /**
     * Dua palet dari SKU yang SAMA boleh digabung dalam satu bin selama
     * jumlahnya masih di bawah kapasitas palet SKU itu — pallet split
     * (PRD §7.1) boleh disatukan kembali di rak.
     */
    public function test_dua_palet_sku_sama_boleh_digabung_dalam_satu_bin(): void
    {
        $this->loginAs();
        $header = $this->makeDocument(); // kapasitas 180: palet A=180, palet B=55
        $bin = $this->bin('B-01-01');
        [$paletA, $paletB] = $header->details()->orderBy('pallet_no')->get()->all();

        $this->post('/wms/inbound/putaway/IN-260901-001', [
            'pallets' => [
                $paletA->id => ['location_code' => 'B-01-01', 'qty_actual' => 100],
                $paletB->id => ['location_code' => 'B-01-01', 'qty_actual' => 55],
            ],
        ])->assertRedirect();

        $this->assertSame($bin->id, $paletA->fresh()->location_id);
        $this->assertSame($bin->id, $paletB->fresh()->location_id);
    }

    /** Gabungan qty yang melebihi kapasitas palet SKU tersebut ditolak. */
    public function test_gabungan_palet_melebihi_kapasitas_ditolak(): void
    {
        $this->loginAs();
        $header = $this->makeDocument(); // kapasitas 180: palet A=180, palet B=55 -> gabungan 235 > 180
        $this->bin('B-01-01');
        [$paletA, $paletB] = $header->details()->orderBy('pallet_no')->get()->all();

        $this->post('/wms/inbound/putaway/IN-260901-001', [
            'pallets' => [
                $paletA->id => ['location_code' => 'B-01-01', 'qty_actual' => 180],
                $paletB->id => ['location_code' => 'B-01-01', 'qty_actual' => 55],
            ],
        ])->assertSessionHasErrors("pallets.{$paletB->id}.location_code");

        // Satu pengiriman ditolak SELURUHNYA bila ada satu saja isian yang
        // salah — palet A pun ikut tidak tersimpan, bukan hanya palet B.
        $this->assertNull($paletA->fresh()->location_id);
        $this->assertNull($paletB->fresh()->location_id);
    }

    /** Dua SKU yang berbeda tidak boleh berbagi satu bin, walau kapasitasnya cukup. */
    public function test_dua_sku_berbeda_tidak_boleh_berbagi_bin(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $this->bin('B-01-01');
        [$paletA, $paletB] = $header->details()->orderBy('pallet_no')->get()->all();
        // Paksa palet B jadi produk lain, seolah dua SKU berbeda menunjuk bin yang sama.
        $produkLain = Product::factory()->create(['max_qty_per_pallet' => 180, 'uom' => 'PAIL']);
        $paletB->update(['product_id' => $produkLain->id]);

        $this->post('/wms/inbound/putaway/IN-260901-001', [
            'pallets' => [
                $paletA->id => ['location_code' => 'B-01-01', 'qty_actual' => 100],
                $paletB->id => ['location_code' => 'B-01-01', 'qty_actual' => 50],
            ],
        ])->assertSessionHasErrors("pallets.{$paletB->id}.location_code");

        $this->assertNull($paletA->fresh()->location_id);
        $this->assertNull($paletB->fresh()->location_id);
    }

    /** SKU yang SAMA dari DOKUMEN LAIN boleh digabung selama masih ada sisa kapasitas. */
    public function test_sku_sama_dari_dokumen_lain_boleh_digabung_bila_masih_ada_sisa(): void
    {
        $this->loginAs();
        $sudahAda = $this->makeDocument(['document_number' => 'IN-260901-002']);
        $bin = $this->bin('B-01-01');
        $produkBersama = $sudahAda->details()->first()->product;
        $sudahAda->details()->first()->update(['location_id' => $bin->id, 'qty_actual' => 50]);

        $header = $this->makeDocument();
        $header->details()->each(fn ($d) => $d->update(['product_id' => $produkBersama->id]));
        $palet = $header->details()->first();

        $this->post('/wms/inbound/putaway/IN-260901-001', [
            'pallets' => [$palet->id => ['location_code' => 'B-01-01', 'qty_actual' => 100]],
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame($bin->id, $palet->fresh()->location_id);
    }

    /** Bin yang ditempati SKU LAIN dari DOKUMEN LAIN tetap ditolak. */
    public function test_sku_berbeda_dari_dokumen_lain_ditolak(): void
    {
        $this->loginAs();
        $sudahAda = $this->makeDocument(['document_number' => 'IN-260901-002']);
        $bin = $this->bin('B-01-01');
        $sudahAda->details()->first()->update(['location_id' => $bin->id]);

        $header = $this->makeDocument();
        $palet = $header->details()->first();

        $this->post('/wms/inbound/putaway/IN-260901-001', [
            'pallets' => [$palet->id => ['location_code' => 'B-01-01', 'qty_actual' => 180]],
        ])->assertSessionHasErrors("pallets.{$palet->id}.location_code");

        $this->assertNull($palet->fresh()->location_id);
    }

    /** Produk tanpa kapasitas palet terdaftar: bin yang sudah terisi APAPUN ditolak (jaring pengaman). */
    public function test_produk_tanpa_kapasitas_terdaftar_tidak_bisa_berbagi_bin(): void
    {
        $this->loginAs();
        $header = InboundHeader::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'document_number' => 'IN-260901-003',
        ]);
        $produk = Product::factory()->withoutPalletCapacity()->create();
        $detailAwal = InboundDetail::factory()->create([
            'inbound_header_id' => $header->id,
            'product_id' => $produk->id,
            'pallet_qty' => 50,
        ]);
        $detailBaru = InboundDetail::factory()->create([
            'inbound_header_id' => $header->id,
            'product_id' => $produk->id,
            'pallet_qty' => 20,
        ]);
        $bin = $this->bin('B-01-01');
        $detailAwal->update(['location_id' => $bin->id]);

        $this->post('/wms/inbound/putaway/IN-260901-003', [
            'pallets' => [$detailBaru->id => ['location_code' => 'B-01-01', 'qty_actual' => 20]],
        ])->assertSessionHasErrors("pallets.{$detailBaru->id}.location_code");

        $this->assertNull($detailBaru->fresh()->location_id);
    }

    /** Menyimpan ulang palet ke bin yang sudah dimilikinya sendiri tetap diterima. */
    public function test_palet_boleh_disimpan_ulang_ke_bin_miliknya_sendiri(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $bin = $this->bin('B-01-01');
        $palet = $header->details()->first();
        $palet->update(['location_id' => $bin->id, 'qty_actual' => 180]);

        $this->post('/wms/inbound/putaway/IN-260901-001', [
            'pallets' => [$palet->id => ['location_code' => 'B-01-01', 'qty_actual' => 178]],
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame(178, $palet->fresh()->qty_actual);
    }

    /**
     * Melanjutkan put-away sebagian: palet yang SUDAH tersimpan di sebuah bin
     * ikut terkirim ulang bersama palet baru yang menuju bin yang sama.
     *
     * Menjaga dari kesalahan hitung ganda: isi bin dari database dan nilai
     * yang sedang dikirim untuk palet yang SAMA tidak boleh dijumlahkan dua
     * kali. A(100) sudah di B-01-01, B(50) menyusul -> 150 dari kapasitas
     * 180, harus DITERIMA.
     */
    public function test_melanjutkan_putaway_ke_bin_yang_sudah_memuat_palet_sendiri(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $bin = $this->bin('B-01-01');
        [$paletA, $paletB] = $header->details()->orderBy('pallet_no')->get()->all();
        $paletA->update(['pallet_qty' => 100, 'location_id' => $bin->id, 'qty_actual' => 100]);
        $paletB->update(['pallet_qty' => 50]);

        $this->post('/wms/inbound/putaway/IN-260901-001', [
            'pallets' => [
                $paletA->id => ['location_code' => 'B-01-01', 'qty_actual' => 100],
                $paletB->id => ['location_code' => 'B-01-01', 'qty_actual' => 50],
            ],
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame($bin->id, $paletA->fresh()->location_id);
        $this->assertSame($bin->id, $paletB->fresh()->location_id);
    }

    public function test_kode_lokasi_huruf_kecil_tetap_diterima(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $bin = $this->bin('B-01-01');
        $palet = $header->details()->first();

        $this->post('/wms/inbound/putaway/IN-260901-001', [
            'pallets' => [$palet->id => ['location_code' => ' b-01-01 ', 'qty_actual' => 180]],
        ]);

        $this->assertSame($bin->id, $palet->fresh()->location_id);
    }

    public function test_role_tanpa_hak_tidak_bisa_menyimpan(): void
    {
        $this->loginAs(Role::PRODUCTION);
        $header = $this->makeDocument();
        $this->bin('B-01-01');
        $palet = $header->details()->first();

        $this->post('/wms/inbound/putaway/IN-260901-001', [
            'pallets' => [$palet->id => ['location_code' => 'B-01-01', 'qty_actual' => 180]],
        ])->assertForbidden();

        $this->assertNull($palet->fresh()->location_id);
    }
}
