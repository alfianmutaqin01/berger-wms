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
 * Verifikasi Maker-Checker — PRD §6.3 F-INB-03.
 *
 * Aturan yang paling dijaga di sini:
 *   1. Verifikasi boleh SEBAGIAN (Logistik boleh menunda) — status turun ke
 *      partial_verified dan dokumen TETAP muncul di daftar.
 *   2. Palet yang sudah terverifikasi TERKUNCI dari layar ini; koreksinya
 *      lewat Menu Stok (F-INB-04).
 *   3. Logistik boleh mengoreksi qty & lokasi, TAPI TIDAK batch/SKU.
 *   4. Perpindahan lokasi tunduk aturan kapasitas bin yang sama dengan
 *      put-away (App\Support\Inbound\BinAllocator).
 */
class VerificationTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create(['code' => 'WH-01']);
    }

    private function loginAs(string $roleSlug = Role::LOGISTICS): User
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

    /** Idempoten: kode bin unik per gudang, jadi pemanggilan berulang memakai yang sudah ada. */
    private function bin(string $code, ?Warehouse $warehouse = null): Location
    {
        $parts = Location::parseCode($code);
        $warehouse ??= $this->warehouse;

        return Location::firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'code' => $code],
            [
                'rack' => $parts['rack'],
                'level' => $parts['level'],
                'cell' => $parts['cell'],
                'zone' => Location::ZONE_FAST,
                'is_active' => true,
            ]
        );
    }

    /**
     * Dokumen siap verifikasi: dua palet (180 & 55) sudah ditempatkan
     * Operator di dua bin berbeda.
     *
     * $binCodes bisa diganti supaya dokumen kedua pada satu test tidak
     * berbagi rak dengan dokumen pertama — berbagi rak antar SKU berbeda
     * adalah kondisi yang justru dilarang aturan bin.
     */
    private function makeDocument(array $overrides = [], array $binCodes = ['B-01-01', 'B-01-02']): InboundHeader
    {
        $header = InboundHeader::factory()->create(array_merge([
            'warehouse_id' => $this->warehouse->id,
            'document_number' => 'IN-260901-001',
            'status' => InboundHeader::STATUS_VERIFICATION_PENDING,
        ], $overrides));

        $product = Product::factory()->create(['max_qty_per_pallet' => 180, 'uom' => 'TIN']);
        $operator = User::factory()->withRole(Role::WAREHOUSE_OPERATOR)->create();

        foreach ([[1, 180, $binCodes[0]], [2, 55, $binCodes[1]]] as [$no, $qty, $code]) {
            InboundDetail::factory()->create([
                'inbound_header_id' => $header->id,
                'product_id' => $product->id,
                'production_order_no' => 'RMO26080294',
                'batch_no' => 'I126080071',
                'total_qty' => 235,
                'pallet_no' => $no,
                'pallet_qty' => $qty,
                'location_id' => $this->bin($code)->id,
                'qty_actual' => $qty,
                'putaway_by' => $operator->id,
                'putaway_at' => now(),
            ]);
        }

        return $header;
    }

    /* ---------------------------------------------------------------- Akses */

    public function test_logistik_manager_dan_super_admin_boleh_membuka_verifikasi(): void
    {
        foreach ([Role::LOGISTICS, Role::MANAGER, Role::SUPER_ADMIN] as $slug) {
            $this->loginAs($slug);
            $this->get('/wms/inbound/verify')->assertOk()->assertViewHas('documents');
        }
    }

    public function test_role_tanpa_hak_ditolak(): void
    {
        foreach ([Role::PRODUCTION, Role::WAREHOUSE_OPERATOR] as $slug) {
            $this->loginAs($slug);
            $this->get('/wms/inbound/verify')->assertForbidden();
        }
    }

    public function test_role_tanpa_hak_tidak_bisa_menyimpan(): void
    {
        $this->loginAs(Role::WAREHOUSE_OPERATOR);
        $header = $this->makeDocument();
        $palet = $header->details()->first();

        $this->post('/wms/inbound/verify/IN-260901-001', [
            'pallets' => [$palet->id => ['verified' => 1, 'location_code' => 'B-01-01', 'qty_actual' => 180]],
        ])->assertForbidden();

        $this->assertFalse($palet->fresh()->is_verified);
    }

    /* --------------------------------------------------------------- Daftar */

    public function test_hanya_dokumen_menunggu_verifikasi_yang_tampil(): void
    {
        $this->loginAs();
        $this->makeDocument();
        InboundHeader::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'document_number' => 'IN-260901-002',
            'status' => InboundHeader::STATUS_PUTAWAY_PENDING,
        ]);
        InboundHeader::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'document_number' => 'IN-260901-003',
            'status' => InboundHeader::STATUS_VERIFIED,
        ]);

        $documents = $this->get('/wms/inbound/verify')->viewData('documents');

        $this->assertCount(1, $documents);
        $this->assertSame('IN-260901-001', $documents->first()->document_number);
    }

    /**
     * Dokumen yang baru sebagian diverifikasi HARUS tetap di daftar — kalau
     * tidak, sisa paletnya tidak bisa diselesaikan lewat layar manapun.
     */
    public function test_dokumen_terverifikasi_sebagian_tetap_tampil(): void
    {
        $this->loginAs();
        $this->makeDocument(['status' => InboundHeader::STATUS_PARTIAL_VERIFIED]);

        $documents = $this->get('/wms/inbound/verify')->viewData('documents');

        $this->assertCount(1, $documents);
    }

    public function test_daftar_menunjukkan_kemajuan_verifikasi(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $header->details()->first()->update(['is_verified' => true]);

        $doc = $this->get('/wms/inbound/verify')->viewData('documents')->first();

        $this->assertSame(2, $doc->details_count);
        $this->assertSame(1, $doc->details_verified_count);
    }

    /** Palet berselisih ditonjolkan: di situlah angka final stok diputuskan. */
    public function test_ringkasan_menghitung_palet_berselisih(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $header->details()->where('pallet_qty', 180)->update(['qty_actual' => 178]);

        $stats = $this->get('/wms/inbound/verify')->viewData('stats');

        $this->assertSame(2, $stats['palet']);
        $this->assertSame(2, $stats['belum']);
        $this->assertSame(1, $stats['selisih']);
    }

    /* ---------------------------------------------------------------- Layar */

    public function test_layar_verifikasi_memuat_palet_dan_lokasinya(): void
    {
        $this->loginAs();
        $this->makeDocument();

        $response = $this->get('/wms/inbound/verify/IN-260901-001')->assertOk();

        $this->assertCount(2, $response->viewData('details'));
        $this->assertSame(2, $response->viewData('totals')['palet']);
        $this->assertSame(0, $response->viewData('totals')['terverifikasi']);
    }

    public function test_dokumen_belum_putaway_tidak_bisa_diverifikasi(): void
    {
        $this->loginAs();
        $this->makeDocument(['status' => InboundHeader::STATUS_PUTAWAY_PENDING]);

        $this->get('/wms/inbound/verify/IN-260901-001')->assertNotFound();
    }

    public function test_dokumen_yang_sudah_selesai_tidak_bisa_diverifikasi_ulang(): void
    {
        $this->loginAs();
        $this->makeDocument(['status' => InboundHeader::STATUS_VERIFIED]);

        $this->get('/wms/inbound/verify/IN-260901-001')->assertNotFound();
    }

    /* -------------------------------------------------------------- Simpan */

    public function test_verifikasi_seluruh_palet_menyelesaikan_dokumen(): void
    {
        $logistik = $this->loginAs();
        $header = $this->makeDocument();
        [$paletA, $paletB] = $header->details()->orderBy('pallet_no')->get()->all();

        $this->post('/wms/inbound/verify/IN-260901-001', [
            'pallets' => [
                $paletA->id => ['verified' => 1, 'location_code' => 'B-01-01', 'qty_actual' => 180],
                $paletB->id => ['verified' => 1, 'location_code' => 'B-01-02', 'qty_actual' => 55],
            ],
        ])->assertRedirect('/wms/inbound/verify');

        $this->assertTrue($paletA->fresh()->is_verified);
        $this->assertTrue($paletB->fresh()->is_verified);
        $this->assertSame($logistik->id, $paletA->fresh()->verified_by);
        $this->assertNotNull($paletA->fresh()->verified_at);
        $this->assertSame(InboundHeader::STATUS_VERIFIED, $header->fresh()->status);
    }

    /**
     * Verifikasi sebagian: yang dicentang tersimpan, yang tidak tetap
     * menunggu, status turun ke partial_verified (PRD langkah 8 "menunda").
     */
    public function test_verifikasi_sebagian_menyisakan_status_partial(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        [$paletA, $paletB] = $header->details()->orderBy('pallet_no')->get()->all();

        $this->post('/wms/inbound/verify/IN-260901-001', [
            'pallets' => [
                $paletA->id => ['verified' => 1, 'location_code' => 'B-01-01', 'qty_actual' => 180],
                $paletB->id => ['verified' => 0, 'location_code' => 'B-01-02', 'qty_actual' => 55],
            ],
        ])->assertRedirect('/wms/inbound/verify/IN-260901-001');

        $this->assertTrue($paletA->fresh()->is_verified);
        $this->assertFalse($paletB->fresh()->is_verified);
        $this->assertSame(InboundHeader::STATUS_PARTIAL_VERIFIED, $header->fresh()->status);
    }

    /** Logistik memutuskan angka final — qty boleh berbeda dari hitungan Operator. */
    public function test_logistik_boleh_mengoreksi_qty_final(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $palet = $header->details()->where('pallet_qty', 180)->first();
        $palet->update(['qty_actual' => 178]);

        $this->post('/wms/inbound/verify/IN-260901-001', [
            'pallets' => [$palet->id => ['verified' => 1, 'location_code' => 'B-01-01', 'qty_actual' => 176]],
        ]);

        $this->assertSame(176, $palet->fresh()->qty_actual);
        $this->assertTrue($palet->fresh()->is_verified);
    }

    /** Logistik boleh memindahkan palet bila lokasi fisiknya ternyata beda. */
    public function test_logistik_boleh_memindahkan_lokasi(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $tujuan = $this->bin('B-01-09');
        $palet = $header->details()->where('pallet_no', 1)->first();

        $this->post('/wms/inbound/verify/IN-260901-001', [
            'pallets' => [$palet->id => ['verified' => 1, 'location_code' => 'B-01-09', 'qty_actual' => 180]],
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame($tujuan->id, $palet->fresh()->location_id);
    }

    /**
     * Palet yang TETAP di raknya sendiri tidak boleh dianggap menabrak
     * dirinya sendiri saat kapasitas dihitung.
     */
    public function test_palet_yang_tetap_di_raknya_tidak_menabrak_dirinya_sendiri(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $palet = $header->details()->where('pallet_qty', 180)->first();

        $this->post('/wms/inbound/verify/IN-260901-001', [
            'pallets' => [$palet->id => ['verified' => 1, 'location_code' => 'B-01-01', 'qty_actual' => 180]],
        ])->assertSessionDoesntHaveErrors();

        $this->assertTrue($palet->fresh()->is_verified);
    }

    /** Aturan kapasitas bin yang sama dengan put-away tetap berlaku. */
    public function test_pindah_ke_rak_yang_dihuni_produk_lain_ditolak(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();

        // Bin tujuan sudah dihuni SKU lain dari dokumen berbeda.
        $produkLain = Product::factory()->create(['max_qty_per_pallet' => 180, 'uom' => 'PAIL']);
        $lain = InboundHeader::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'document_number' => 'IN-260901-009',
        ]);
        $tujuan = $this->bin('B-01-09');
        InboundDetail::factory()->create([
            'inbound_header_id' => $lain->id,
            'product_id' => $produkLain->id,
            'pallet_qty' => 50,
            'location_id' => $tujuan->id,
            'qty_actual' => 50,
        ]);

        $palet = $header->details()->where('pallet_no', 1)->first();

        $this->post('/wms/inbound/verify/IN-260901-001', [
            'pallets' => [$palet->id => ['verified' => 1, 'location_code' => 'B-01-09', 'qty_actual' => 180]],
        ])->assertSessionHasErrors("pallets.{$palet->id}.location_code");

        $this->assertFalse($palet->fresh()->is_verified);
    }

    public function test_pindah_yang_melebihi_kapasitas_ditolak(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        [$paletA, $paletB] = $header->details()->orderBy('pallet_no')->get()->all();

        // 180 + 55 = 235 > kapasitas 180.
        $this->post('/wms/inbound/verify/IN-260901-001', [
            'pallets' => [
                $paletA->id => ['verified' => 1, 'location_code' => 'B-01-01', 'qty_actual' => 180],
                $paletB->id => ['verified' => 1, 'location_code' => 'B-01-01', 'qty_actual' => 55],
            ],
        ])->assertSessionHasErrors("pallets.{$paletB->id}.location_code");

        $this->assertFalse($paletA->fresh()->is_verified);
        $this->assertFalse($paletB->fresh()->is_verified);
    }

    public function test_kode_lokasi_tidak_dikenal_ditolak(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $palet = $header->details()->first();

        $this->post('/wms/inbound/verify/IN-260901-001', [
            'pallets' => [$palet->id => ['verified' => 1, 'location_code' => 'Z-99-99', 'qty_actual' => 180]],
        ])->assertSessionHasErrors("pallets.{$palet->id}.location_code");

        $this->assertFalse($palet->fresh()->is_verified);
    }

    /**
     * F-INB-04: palet yang sudah terverifikasi TERKUNCI dari layar ini —
     * koreksinya hanya lewat Menu Stok oleh Manager/Super Admin.
     */
    public function test_palet_terverifikasi_tidak_bisa_diubah_lagi(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $tujuan = $this->bin('B-01-09');
        [$paletA, $paletB] = $header->details()->orderBy('pallet_no')->get()->all();
        $paletA->update(['is_verified' => true, 'qty_actual' => 180]);

        $this->post('/wms/inbound/verify/IN-260901-001', [
            'pallets' => [
                // Percobaan mengubah palet yang sudah terverifikasi.
                $paletA->id => ['verified' => 1, 'location_code' => 'B-01-09', 'qty_actual' => 1],
                $paletB->id => ['verified' => 1, 'location_code' => 'B-01-02', 'qty_actual' => 55],
            ],
        ]);

        $paletA->refresh();
        $this->assertSame(180, $paletA->qty_actual);
        $this->assertNotSame($tujuan->id, $paletA->location_id);
    }

    /** Batch & SKU tidak pernah berubah dari layar verifikasi. */
    public function test_batch_dan_sku_tidak_dapat_diubah(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $palet = $header->details()->first();
        $batchAsli = $palet->batch_no;
        $produkAsli = $palet->product_id;

        $this->post('/wms/inbound/verify/IN-260901-001', [
            'pallets' => [$palet->id => [
                'verified' => 1,
                'location_code' => 'B-01-01',
                'qty_actual' => 180,
                // Kiriman jahat: keduanya harus diabaikan sepenuhnya.
                'batch_no' => 'DIUBAH-999',
                'product_id' => 999999,
            ]],
        ]);

        $palet->refresh();
        $this->assertSame($batchAsli, $palet->batch_no);
        $this->assertSame($produkAsli, $palet->product_id);
    }

    public function test_tanpa_centang_ditolak_dengan_pesan(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $palet = $header->details()->first();

        $this->post('/wms/inbound/verify/IN-260901-001', [
            'pallets' => [$palet->id => ['verified' => 0, 'location_code' => 'B-01-01', 'qty_actual' => 180]],
        ])->assertSessionHas('error');

        $this->assertSame(InboundHeader::STATUS_VERIFICATION_PENDING, $header->fresh()->status);
    }

    public function test_palet_dicentang_tanpa_lokasi_ditolak(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        $palet = $header->details()->first();

        $this->post('/wms/inbound/verify/IN-260901-001', [
            'pallets' => [$palet->id => ['verified' => 1, 'location_code' => '', 'qty_actual' => 180]],
        ])->assertSessionHasErrors("pallets.{$palet->id}.location_code");

        $this->assertFalse($palet->fresh()->is_verified);
    }

    /** Palet milik dokumen lain tidak boleh ikut terverifikasi lewat kiriman form. */
    public function test_palet_dokumen_lain_diabaikan(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        // Rak berbeda: dokumen kedua memakai SKU lain, dan SKU berbeda memang
        // tidak boleh berbagi rak.
        $lain = $this->makeDocument(['document_number' => 'IN-260901-002'], ['B-02-01', 'B-02-02']);

        $milikSendiri = $header->details()->first();
        $milikOrangLain = $lain->details()->first();

        $this->post('/wms/inbound/verify/IN-260901-001', [
            'pallets' => [
                $milikSendiri->id => ['verified' => 1, 'location_code' => 'B-01-01', 'qty_actual' => 180],
                $milikOrangLain->id => ['verified' => 1, 'location_code' => 'B-02-01', 'qty_actual' => 55],
            ],
        ]);

        $this->assertTrue($milikSendiri->fresh()->is_verified);
        $this->assertFalse($milikOrangLain->fresh()->is_verified);
    }

    /** Status dihitung dari isi palet, bukan dari urutan aksi. */
    public function test_status_dihitung_dari_isi_palet(): void
    {
        $header = $this->makeDocument();

        $this->assertSame(InboundHeader::STATUS_VERIFICATION_PENDING, $header->resolveVerificationStatus());

        $header->details()->first()->update(['is_verified' => true]);
        $this->assertSame(InboundHeader::STATUS_PARTIAL_VERIFIED, $header->resolveVerificationStatus());

        $header->details()->update(['is_verified' => true]);
        $this->assertSame(InboundHeader::STATUS_VERIFIED, $header->resolveVerificationStatus());
    }

    /* --------------------------------------------------- Alur penuh 3a-3b-3c */

    /** Dokumen yang selesai diverifikasi keluar dari daftar put-away & verifikasi. */
    public function test_dokumen_selesai_hilang_dari_kedua_daftar(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        [$paletA, $paletB] = $header->details()->orderBy('pallet_no')->get()->all();

        $this->post('/wms/inbound/verify/IN-260901-001', [
            'pallets' => [
                $paletA->id => ['verified' => 1, 'location_code' => 'B-01-01', 'qty_actual' => 180],
                $paletB->id => ['verified' => 1, 'location_code' => 'B-01-02', 'qty_actual' => 55],
            ],
        ]);

        $this->assertCount(0, $this->get('/wms/inbound/verify')->viewData('documents'));

        $this->loginAs(Role::SUPER_ADMIN);
        $this->assertCount(0, $this->get('/wms/inbound/putaway')->viewData('documents'));

        // Tapi tetap terlihat di riwayat produksi dengan status Selesai.
        $riwayat = $this->get('/wms/inbound/history')->viewData('documents');
        $this->assertSame(InboundHeader::STATUS_VERIFIED, $riwayat->first()->status);
    }

    /**
     * Konsolidasi: dua palet SKU sama disatukan ke bin yang SUDAH memuat
     * salah satunya, dalam satu pengiriman yang sama.
     *
     * Menjaga dari kesalahan hitung ganda: isi bin dari database dan nilai
     * yang sedang dikirim untuk palet yang SAMA tidak boleh dijumlahkan dua
     * kali. A(100) sudah di B-01-01, B(50) menyusul -> 150 dari kapasitas
     * 180, harus DITERIMA.
     */
    public function test_konsolidasi_ke_bin_yang_sudah_memuat_salah_satunya(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();
        [$paletA, $paletB] = $header->details()->orderBy('pallet_no')->get()->all();
        $paletA->update(['pallet_qty' => 100, 'qty_actual' => 100]);
        $paletB->update(['pallet_qty' => 50, 'qty_actual' => 50]);

        $this->post('/wms/inbound/verify/IN-260901-001', [
            'pallets' => [
                $paletA->id => ['verified' => 1, 'location_code' => 'B-01-01', 'qty_actual' => 100],
                $paletB->id => ['verified' => 1, 'location_code' => 'B-01-01', 'qty_actual' => 50],
            ],
        ])->assertSessionDoesntHaveErrors();

        $this->assertTrue($paletA->fresh()->is_verified);
        $this->assertTrue($paletB->fresh()->is_verified);
        $this->assertSame($paletA->fresh()->location_id, $paletB->fresh()->location_id);
    }
}
