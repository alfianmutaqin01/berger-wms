<?php

namespace Tests\Feature\Wms;

use App\Models\InboundDetail;
use App\Models\InboundHeader;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\PalletCapacity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Input Produksi — PRD §6.3 F-INB-01.
 *
 * Berkas .xlsx sungguhan dibuat lalu diunggah, bukan hasil pembacaan yang
 * dipalsukan, supaya jalur PhpSpreadsheet + pemetaan kolom A–E benar-benar
 * teruji ujung ke ujung.
 */
class ProductionInputTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        // Berkas unggahan diarahkan ke disk tiruan agar test tidak pernah
        // menyentuh storage/app/private. Selain menjaga test tetap terisolasi,
        // ini mencegah folder di sana terbuat oleh proses yang menjalankan
        // test — kepemilikannya bisa berbeda dari pengguna PHP-FPM, sehingga
        // permintaan HTTP sungguhan jadi gagal menulis ke folder yang sama.
        Storage::fake('local');

        $this->warehouse = Warehouse::factory()->withProduction()->create(['code' => 'WH-01']);
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

    /** Berkas produksi dengan kolom A–L; hanya A–E yang dibaca sistem. */
    private function sheet(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            'No.', 'Source No.', 'Description', 'Quantity', 'QC Number',
            'Starting Date-Time', 'Ending Date-Time', 'Due Date',
            'Assigned User ID', 'Status', 'Routing No.', 'Search Description',
        ], null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'prod').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'produksi.xlsx', null, null, true);
    }

    /** Baris berkas produksi lengkap sampai kolom L. */
    private function row(string $orderNo, string $sku, string $desc, int $qty, string $qc): array
    {
        return [
            $orderNo, $sku, $desc, $qty, $qc,
            '28/08/26 08:30:00', '28/08/26 17:30:00', '28/08/2026',
            '', 'Finished', '', strtoupper($desc),
        ];
    }

    private function makeProduct(string $sku, string $name, string $unit, float $size): Product
    {
        return Product::factory()->create([
            'sku' => $sku,
            'name' => $name,
            'pack_size' => $size,
            'pack_unit' => $unit,
            'max_qty_per_pallet' => PalletCapacity::resolve($unit, $size),
        ]);
    }

    private function preview(UploadedFile $file)
    {
        return $this->post('/wms/inbound/preview', [
            'file' => $file,
            'warehouse_id' => $this->warehouse->id,
        ]);
    }

    private function submit($preview, array $overrides = [])
    {
        return $this->post('/wms/inbound/store', array_merge([
            'token' => $preview->viewData('token'),
            'extension' => $preview->viewData('extension'),
            'warehouse_id' => $this->warehouse->id,
        ], $overrides));
    }

    /* ---------------------------------------------------------------- Akses */

    public function test_produksi_dan_super_admin_dapat_membuka_form(): void
    {
        foreach ([Role::PRODUCTION, Role::SUPER_ADMIN] as $slug) {
            $this->loginAs($slug);
            $this->get('/wms/inbound/create')->assertOk()->assertViewHas('documentNumber');
        }
    }

    public function test_role_lain_ditolak(): void
    {
        foreach ([Role::MANAGER, Role::LOGISTICS, Role::WAREHOUSE_OPERATOR] as $slug) {
            $this->loginAs($slug);
            $this->get('/wms/inbound/create')->assertForbidden();
        }
    }

    /* ---------------------------------------------- Nomor dokumen & tanggal */

    /** Nomor dokumen & tanggal dibangkitkan sistem, bukan diketik pengguna. */
    public function test_nomor_dokumen_dan_tanggal_dibangkitkan_sistem(): void
    {
        $this->loginAs();

        $response = $this->get('/wms/inbound/create')->assertOk();

        $this->assertSame('IN-'.now()->format('ymd').'-001', $response->viewData('documentNumber'));
        $this->assertTrue($response->viewData('productionDate')->isToday());
    }

    public function test_nomor_dokumen_berurutan_per_hari(): void
    {
        $this->loginAs();
        $this->makeProduct('ID1-F00573202805', 'Tractor Emulsion White 5Kg', PalletCapacity::UNIT_KILOGRAM, 5);

        foreach (['001', '002'] as $expected) {
            $preview = $this->preview($this->sheet([
                $this->row('RMO'.$expected, 'ID1-F00573202805', 'Tractor Emulsion White 5Kg', 100, 'I126080071'),
            ]));
            $this->submit($preview);

            $this->assertDatabaseHas('inbound_headers', [
                'document_number' => 'IN-'.now()->format('ymd').'-'.$expected,
            ]);
        }
    }

    /* ------------------------------------------------------ Pratinjau aman */

    public function test_pratinjau_tidak_menyimpan_apa_pun(): void
    {
        $this->loginAs();
        $this->makeProduct('ID1-F00573202805', 'Tractor Emulsion White 5Kg', PalletCapacity::UNIT_KILOGRAM, 5);

        $this->preview($this->sheet([
            $this->row('RMO26080294', 'ID1-F00573202805', 'Tractor Emulsion White 5Kg', 235, 'I126080071'),
        ]))->assertOk()->assertViewIs('wms.inbound.preview');

        $this->assertSame(0, InboundHeader::count());
        $this->assertSame(0, InboundDetail::count());
    }

    /* ------------------------------------------------------ Pemecahan palet */

    /** PRD §7.1: 235 pcs kemasan 5 Kg (maks 180) menjadi dua palet: 180 + 55. */
    public function test_qty_dipecah_menjadi_palet_sesuai_kemasan(): void
    {
        $this->loginAs();
        $product = $this->makeProduct('ID1-F00573202805', 'Tractor Emulsion White 5Kg', PalletCapacity::UNIT_KILOGRAM, 5);

        $preview = $this->preview($this->sheet([
            $this->row('RMO26080294', $product->sku, $product->name, 235, 'I126080071'),
        ]));

        $this->submit($preview)->assertSessionHas('success');

        $details = InboundDetail::orderBy('pallet_no')->get();

        $this->assertCount(2, $details);
        $this->assertSame([180, 55], $details->pluck('pallet_qty')->all());
        $this->assertSame([1, 2], $details->pluck('pallet_no')->all());
        // total_qty menyimpan jumlah asli sebelum dipecah.
        $this->assertSame([235, 235], $details->pluck('total_qty')->all());
    }

    /** Qty yang habis dibagi tidak menyisakan palet kosong. */
    public function test_qty_pas_tidak_membuat_palet_sisa(): void
    {
        $this->loginAs();
        $product = $this->makeProduct('ID1-F00573202805', 'Tractor Emulsion White 5Kg', PalletCapacity::UNIT_KILOGRAM, 5);

        $preview = $this->preview($this->sheet([
            $this->row('RMO1', $product->sku, $product->name, 360, 'I1'),
        ]));
        $this->submit($preview);

        $this->assertSame([180, 180], InboundDetail::orderBy('pallet_no')->pluck('pallet_qty')->all());
    }

    /** 5 Liter sempat tidak ada di aturan palet; kini setara 5 Kg (180). */
    public function test_kemasan_lima_liter_terhitung(): void
    {
        $this->loginAs();
        $product = $this->makeProduct('ID1-FHR161000705', 'LUXATHERM 1600 BINDER 5Ltr', PalletCapacity::UNIT_LITER, 5);

        $this->assertSame(180, $product->max_qty_per_pallet);

        $preview = $this->preview($this->sheet([
            $this->row('RMO26080300', $product->sku, $product->name, 95, 'I126080056'),
        ]));
        $this->submit($preview);

        $this->assertSame([95], InboundDetail::pluck('pallet_qty')->all());
    }

    /* ------------------------------------------------------- Baris bermasalah */

    /** SKU tak dikenal ditolak — master produk tidak diisi otomatis dari berkas produksi. */
    public function test_sku_tak_dikenal_ditolak_dan_tidak_membuat_produk(): void
    {
        $this->loginAs();

        $preview = $this->preview($this->sheet([
            $this->row('RMO1', 'ID1-TIDAK-ADA', 'Produk Entah', 100, 'I1'),
        ]))->assertOk();

        $summary = $preview->viewData('summary');

        $this->assertSame(1, $summary['gagal']);
        $this->assertSame(0, $summary['siap']);
        $this->assertSame(0, Product::where('sku', 'ID1-TIDAK-ADA')->count());
    }

    /** Baris bermasalah dilewati, baris lain tetap tersimpan. */
    public function test_baris_bermasalah_dilewati_baris_lain_tetap_disimpan(): void
    {
        $this->loginAs();
        $product = $this->makeProduct('ID1-F00573202805', 'Tractor Emulsion White 5Kg', PalletCapacity::UNIT_KILOGRAM, 5);

        $preview = $this->preview($this->sheet([
            $this->row('RMO1', $product->sku, $product->name, 235, 'I126080071'),
            $this->row('RMO2', 'ID1-TIDAK-ADA', 'Produk Entah', 100, 'I126080071'),
        ]));

        $this->submit($preview)->assertSessionHas('success');

        $this->assertSame(1, InboundHeader::count());
        $this->assertSame(2, InboundDetail::count());
    }

    public function test_qty_nol_ditolak(): void
    {
        $this->loginAs();
        $product = $this->makeProduct('ID1-F00573202805', 'Tractor Emulsion White 5Kg', PalletCapacity::UNIT_KILOGRAM, 5);

        $summary = $this->preview($this->sheet([
            $this->row('RMO1', $product->sku, $product->name, 0, 'I1'),
        ]))->viewData('summary');

        $this->assertSame(1, $summary['gagal']);
    }

    public function test_batch_kosong_ditolak(): void
    {
        $this->loginAs();
        $product = $this->makeProduct('ID1-F00573202805', 'Tractor Emulsion White 5Kg', PalletCapacity::UNIT_KILOGRAM, 5);

        $summary = $this->preview($this->sheet([
            $this->row('RMO1', $product->sku, $product->name, 100, ''),
        ]))->viewData('summary');

        $this->assertSame(1, $summary['gagal']);
    }

    /** Produk tanpa kapasitas palet tidak bisa dipecah — ditolak, bukan ditebak. */
    public function test_produk_tanpa_kapasitas_palet_ditolak(): void
    {
        $this->loginAs();
        $product = Product::factory()->withoutPalletCapacity()->create([
            'sku' => 'ID1-F00113202203',
            'name' => 'Royale Smart Clean White 0.25Ltr',
        ]);

        $summary = $this->preview($this->sheet([
            $this->row('RMO1', $product->sku, $product->name, 100, 'I1'),
        ]))->viewData('summary');

        $this->assertSame(1, $summary['gagal']);
    }

    public function test_berkas_tanpa_kolom_wajib_ditolak(): void
    {
        $this->loginAs();

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(['Kolom A', 'Kolom B'], null, 'A1');
        $spreadsheet->getActiveSheet()->fromArray([['x', 'y']], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'bad').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $this->preview(new UploadedFile($path, 'salah.xlsx', null, null, true))
            ->assertRedirect(route('wms.inbound.create'))
            ->assertSessionHas('error');
    }

    /* ------------------------------------------------- Kolom A & E, batch */

    /** Kolom A = nomor produksi, kolom E = batch. Satu batch bisa lintas order. */
    public function test_nomor_produksi_dan_batch_tersimpan_per_palet(): void
    {
        $this->loginAs();
        $satuKg = $this->makeProduct('ID1-F0017X002801', 'Bocor Guard 2 Base 1Kg', PalletCapacity::UNIT_KILOGRAM, 1);
        $empatKg = $this->makeProduct('ID1-F0017X002804', 'Bocor Guard 2 Base 4Kg', PalletCapacity::UNIT_KILOGRAM, 4);

        // Dua order produksi berbeda berbagi batch yang sama — sesuai data nyata.
        $preview = $this->preview($this->sheet([
            $this->row('RMO26080301', $satuKg->sku, $satuKg->name, 195, 'I126080037'),
            $this->row('RMO26080302', $empatKg->sku, $empatKg->name, 316, 'I126080037'),
        ]));
        $this->submit($preview);

        $this->assertSame(3, InboundDetail::count()); // 1 palet + 2 palet
        $this->assertSame(3, InboundDetail::where('batch_no', 'I126080037')->count());
        $this->assertSame(1, InboundDetail::where('production_order_no', 'RMO26080301')->count());
        $this->assertSame(2, InboundDetail::where('production_order_no', 'RMO26080302')->count());
    }

    /* ------------------------------------------------------- Berkas dibuang */

    /** Berkas Excel tidak disimpan sistem setelah dokumen tersimpan. */
    public function test_berkas_dihapus_setelah_disimpan(): void
    {
        $this->loginAs();
        $product = $this->makeProduct('ID1-F00573202805', 'Tractor Emulsion White 5Kg', PalletCapacity::UNIT_KILOGRAM, 5);

        $preview = $this->preview($this->sheet([
            $this->row('RMO1', $product->sku, $product->name, 100, 'I1'),
        ]));

        $stored = 'inbound/'.$preview->viewData('token').'.'.$preview->viewData('extension');
        $this->assertTrue(Storage::disk('local')->exists($stored));

        $this->submit($preview);

        $this->assertFalse(Storage::disk('local')->exists($stored));
    }

    public function test_membatalkan_pratinjau_membuang_berkas_tanpa_menyimpan(): void
    {
        $this->loginAs();
        $product = $this->makeProduct('ID1-F00573202805', 'Tractor Emulsion White 5Kg', PalletCapacity::UNIT_KILOGRAM, 5);

        $preview = $this->preview($this->sheet([
            $this->row('RMO1', $product->sku, $product->name, 100, 'I1'),
        ]));

        $stored = 'inbound/'.$preview->viewData('token').'.'.$preview->viewData('extension');

        $this->post('/wms/inbound/cancel', [
            'token' => $preview->viewData('token'),
            'extension' => $preview->viewData('extension'),
        ])->assertRedirect(route('wms.inbound.create'));

        $this->assertFalse(Storage::disk('local')->exists($stored));
        $this->assertSame(0, InboundHeader::count());
    }

    /* ----------------------------------------------------------- Dokumen */

    public function test_dokumen_tersimpan_dengan_status_menunggu_putaway(): void
    {
        $actor = $this->loginAs();
        $product = $this->makeProduct('ID1-F00573202805', 'Tractor Emulsion White 5Kg', PalletCapacity::UNIT_KILOGRAM, 5);

        $preview = $this->preview($this->sheet([
            $this->row('RMO1', $product->sku, $product->name, 235, 'I1'),
        ]));
        $this->submit($preview, ['notes' => 'Produksi pagi']);

        $header = InboundHeader::firstOrFail();

        $this->assertSame(InboundHeader::STATUS_PUTAWAY_PENDING, $header->status);
        $this->assertSame('Menunggu Put-away', $header->status_label);
        $this->assertSame($this->warehouse->id, $header->warehouse_id);
        $this->assertSame($actor->id, $header->created_by);
        $this->assertSame('Produksi pagi', $header->notes);
        $this->assertTrue($header->production_date->isToday());
    }
}
