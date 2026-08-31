<?php

namespace Tests\Feature\Wms;

use App\Models\InboundDetail;
use App\Models\InboundHeader;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Riwayat Produksi — PRD §6.3 F-INB-01.
 *
 * Yang paling dijaga di sini: satu dokumen memuat BANYAK batch dan banyak
 * palet, sehingga ringkasannya tidak boleh dihitung dari `total_qty` yang
 * sengaja berulang di tiap palet.
 */
class ProductionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create(['code' => 'WH-01']);
    }

    private function loginAs(string $roleSlug = Role::PRODUCTION): User
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

    /**
     * Dokumen berisi dua baris produksi: 235 pcs jadi 2 palet (180+55),
     * dan 100 pcs jadi 1 palet — total 3 palet, 335 pcs, 2 batch.
     */
    private function makeDocument(array $overrides = []): InboundHeader
    {
        $header = InboundHeader::factory()->create(array_merge([
            'warehouse_id' => $this->warehouse->id,
        ], $overrides));

        $produkA = Product::factory()->create(['max_qty_per_pallet' => 180]);
        $produkB = Product::factory()->create(['max_qty_per_pallet' => 180]);

        foreach ([[1, 180], [2, 55]] as [$no, $qty]) {
            InboundDetail::factory()->create([
                'inbound_header_id' => $header->id,
                'product_id' => $produkA->id,
                'production_order_no' => 'RMO26080294',
                'batch_no' => 'I126080071',
                'total_qty' => 235,
                'pallet_no' => $no,
                'pallet_qty' => $qty,
            ]);
        }

        InboundDetail::factory()->create([
            'inbound_header_id' => $header->id,
            'product_id' => $produkB->id,
            'production_order_no' => 'RMO26080300',
            'batch_no' => 'I126080056',
            'total_qty' => 100,
            'pallet_no' => 1,
            'pallet_qty' => 100,
        ]);

        return $header;
    }

    /* ---------------------------------------------------------------- Akses */

    public function test_role_berhak_dapat_membuka_riwayat(): void
    {
        foreach ([Role::PRODUCTION, Role::MANAGER, Role::SUPER_ADMIN] as $slug) {
            $this->loginAs($slug);
            $this->get('/wms/inbound/history')->assertOk()->assertViewHas('documents');
        }
    }

    public function test_role_tanpa_hak_ditolak(): void
    {
        foreach ([Role::LOGISTICS, Role::WAREHOUSE_OPERATOR] as $slug) {
            $this->loginAs($slug);
            $this->get('/wms/inbound/history')->assertForbidden();
        }
    }

    /* --------------------------------------------------------------- Daftar */

    public function test_daftar_menampilkan_dokumen_dengan_jumlah_palet(): void
    {
        $this->loginAs();
        $header = $this->makeDocument();

        $documents = $this->get('/wms/inbound/history')->assertOk()->viewData('documents');

        $this->assertCount(1, $documents);
        // 3 palet, bukan 2 baris produksi.
        $this->assertSame(3, $documents->first()->details_count);
        $this->assertSame($header->document_number, $documents->first()->document_number);
    }

    public function test_ringkasan_menghitung_status(): void
    {
        $this->loginAs();

        InboundHeader::factory()->count(2)->create([
            'warehouse_id' => $this->warehouse->id,
            'status' => InboundHeader::STATUS_PUTAWAY_PENDING,
        ]);
        InboundHeader::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'status' => InboundHeader::STATUS_VERIFIED,
        ]);

        $stats = $this->get('/wms/inbound/history')->viewData('stats');

        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['putaway']);
        $this->assertSame(1, $stats['selesai']);
    }

    public function test_dokumen_terbaru_tampil_lebih_dulu(): void
    {
        $this->loginAs();

        InboundHeader::factory()->onDate('2026-08-01')->create([
            'warehouse_id' => $this->warehouse->id, 'document_number' => 'IN-260801-001',
        ]);
        InboundHeader::factory()->onDate('2026-08-28')->create([
            'warehouse_id' => $this->warehouse->id, 'document_number' => 'IN-260828-001',
        ]);

        $documents = $this->get('/wms/inbound/history')->viewData('documents');

        $this->assertSame('IN-260828-001', $documents->first()->document_number);
    }

    /* ------------------------------------------------------- Filter & cari */

    public function test_pencarian_menemukan_dokumen_lewat_nomor_batch(): void
    {
        $this->loginAs();
        $this->makeDocument(['document_number' => 'IN-260828-001']);
        InboundHeader::factory()->create([
            'warehouse_id' => $this->warehouse->id, 'document_number' => 'IN-260828-002',
        ]);

        $documents = $this->get('/wms/inbound/history?search=I126080056')->viewData('documents');

        $this->assertCount(1, $documents);
        $this->assertSame('IN-260828-001', $documents->first()->document_number);
    }

    public function test_pencarian_menemukan_dokumen_lewat_nomor_produksi(): void
    {
        $this->loginAs();
        $this->makeDocument(['document_number' => 'IN-260828-001']);

        $documents = $this->get('/wms/inbound/history?search=RMO26080294')->viewData('documents');

        $this->assertCount(1, $documents);
    }

    public function test_filter_status(): void
    {
        $this->loginAs();

        InboundHeader::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'status' => InboundHeader::STATUS_PUTAWAY_PENDING,
        ]);
        InboundHeader::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'status' => InboundHeader::STATUS_VERIFIED,
        ]);

        $documents = $this->get('/wms/inbound/history?status='.InboundHeader::STATUS_VERIFIED)
            ->viewData('documents');

        $this->assertCount(1, $documents);
        $this->assertSame(InboundHeader::STATUS_VERIFIED, $documents->first()->status);
    }

    public function test_filter_rentang_tanggal(): void
    {
        $this->loginAs();

        InboundHeader::factory()->onDate('2026-08-01')->create(['warehouse_id' => $this->warehouse->id]);
        InboundHeader::factory()->onDate('2026-08-28')->create(['warehouse_id' => $this->warehouse->id]);

        $documents = $this->get('/wms/inbound/history?from=2026-08-15&to=2026-08-31')
            ->viewData('documents');

        $this->assertCount(1, $documents);
        $this->assertSame('2026-08-28', $documents->first()->production_date->toDateString());
    }

    public function test_filter_gudang(): void
    {
        $this->loginAs();
        $lain = Warehouse::factory()->create(['code' => 'WH-02']);

        InboundHeader::factory()->create(['warehouse_id' => $this->warehouse->id]);
        InboundHeader::factory()->create(['warehouse_id' => $lain->id]);

        $documents = $this->get('/wms/inbound/history?warehouse_id='.$lain->id)->viewData('documents');

        $this->assertCount(1, $documents);
        $this->assertSame($lain->id, $documents->first()->warehouse_id);
    }

    /* --------------------------------------------------------------- Detail */

    public function test_detail_dibuka_lewat_nomor_dokumen(): void
    {
        $this->loginAs();
        $header = $this->makeDocument(['document_number' => 'IN-260828-001']);

        $response = $this->get('/wms/inbound/history/IN-260828-001')->assertOk();

        $this->assertSame($header->id, $response->viewData('header')->id);
        $this->assertCount(3, $response->viewData('details'));
    }

    /**
     * Total qty dijumlahkan dari pallet_qty, BUKAN total_qty.
     *
     * total_qty sengaja berulang di tiap palet yang berasal dari satu baris
     * produksi (235 tertulis pada palet 1 dan palet 2). Menjumlahkannya akan
     * menghasilkan 570, bukan 335 — angka yang salah dan sulit disadari.
     */
    public function test_total_qty_tidak_berlipat_ganda(): void
    {
        $this->loginAs();
        $this->makeDocument(['document_number' => 'IN-260828-001']);

        $totals = $this->get('/wms/inbound/history/IN-260828-001')->viewData('totals');

        $this->assertSame(3, $totals['palet']);
        $this->assertSame(335, $totals['qty']);   // 180 + 55 + 100
        $this->assertSame(2, $totals['produk']);
        $this->assertSame(2, $totals['batch']);
    }

    public function test_detail_dokumen_tidak_ada_menghasilkan_404(): void
    {
        $this->loginAs();

        $this->get('/wms/inbound/history/IN-999999-999')->assertNotFound();
    }

    public function test_detail_diurutkan_per_nomor_produksi_lalu_palet(): void
    {
        $this->loginAs();
        $this->makeDocument(['document_number' => 'IN-260828-001']);

        $details = $this->get('/wms/inbound/history/IN-260828-001')->viewData('details');

        $this->assertSame(
            ['RMO26080294', 'RMO26080294', 'RMO26080300'],
            $details->pluck('production_order_no')->all()
        );
        $this->assertSame([1, 2, 1], $details->pluck('pallet_no')->all());
    }

    /* --------------------------------------------- Terhubung ke input produksi */

    /** Dokumen hasil input produksi langsung tampil di riwayat. */
    public function test_dokumen_hasil_input_langsung_muncul_di_riwayat(): void
    {
        $this->loginAs();
        $header = $this->makeDocument(['document_number' => 'IN-260828-001']);

        $this->get('/wms/inbound/history')
            ->assertOk()
            ->assertSee('IN-260828-001')
            ->assertSee('Menunggu Put-away');

        $this->assertSame(InboundHeader::STATUS_PUTAWAY_PENDING, $header->status);
    }
}
