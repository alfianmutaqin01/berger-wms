<?php

namespace Tests\Feature\Wms;

use App\Models\InboundDetail;
use App\Models\InboundHeader;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Inbound\DuplikatProduksi;
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

        // WAKTU DIBEKUKAN, dan ini bukan kerapian.
        //
        // Nomor dokumen memuat tanggal (IN-260910-001), dan beberapa test di
        // berkas ini menagihnya dengan now()->format('ymd') yang dihitung
        // ULANG saat assert. Suite penuh berjalan sepuluh menit: dokumen yang
        // dibuat pukul 23:59 ditagih sebagai tanggal berikutnya, dan testnya
        // gagal satu kali lalu lolos di setiap percobaan berikutnya — bentuk
        // kegagalan yang paling mudah dianggap "ah, flaky" lalu diabaikan
        // sampai ia menyembunyikan bug sungguhan.
        //
        // Terjadi betulan pada 10 September 2026: satu test gagal karena
        // suite-nya melewati tengah malam.
        $this->freezeTime();

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
            // Kapasitasnya sengaja TIDAK disalin ke kolom produk: yang diuji
            // berkas produksi ini justru apakah aturan ukuran benar-benar
            // dipakai saat palet dibentuk.
            'max_qty_per_pallet' => null,
        ]);
    }

    private function preview(UploadedFile $file, array $overrides = [])
    {
        return $this->post('/wms/inbound/preview', array_merge([
            'file' => $file,
            'warehouse_id' => $this->warehouse->id,
            'production_date' => now()->toDateString(),
        ], $overrides));
    }

    private function submit($preview, array $overrides = [])
    {
        return $this->post('/wms/inbound/store', array_merge([
            'token' => $preview->viewData('token'),
            'extension' => $preview->viewData('extension'),
            'warehouse_id' => $this->warehouse->id,
            'production_date' => $preview->viewData('productionDate')->toDateString(),
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

        $this->assertSame(180, $product->kapasitasPalet());

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
        $this->assertSame('Menunggu PDN', $header->status_label);
        $this->assertSame($this->warehouse->id, $header->warehouse_id);
        $this->assertSame($actor->id, $header->created_by);
        $this->assertSame('Produksi pagi', $header->notes);
        $this->assertTrue($header->production_date->isToday());
    }

    /* ------------------------------------------------- Duplikat RMO + batch */

    /**
     * Kejadian sungguhan yang melahirkan aturan ini.
     *
     * IN-260910-001 dan -002 tersimpan berurutan dengan RMO dan batch yang
     * sama persis, karena berkasnya diunggah dua kali dan tidak ada satu pun
     * layar yang berkata apa-apa. Paletnya ikut naik rak dan stok bertambah
     * dua kali untuk barang yang hanya dibuat sekali.
     */
    public function test_rmo_dan_batch_yang_sama_tidak_tersimpan_dua_kali(): void
    {
        $this->loginAs();
        $this->makeProduct('ID11-1001', 'Apko 5 Liter', PalletCapacity::UNIT_LITER, 5);

        $baris = fn () => $this->sheet([
            $this->row('ID11_1001', 'ID11-1001', 'Apko 5 Liter', 100, 'I126090022'),
        ]);

        $this->submit($this->preview($baris()));

        $this->submit($this->preview($baris()))
            ->assertSessionHas('error');

        $this->assertSame(1, InboundHeader::count(), 'Berkas yang sama tidak boleh melahirkan dokumen kedua.');
        $this->assertSame(1, InboundDetail::where('batch_no', 'I126090022')->count());
    }

    /** Batch lain di bawah RMO yang sama tetap boleh masuk. */
    public function test_batch_berbeda_pada_rmo_yang_sama_tetap_diterima(): void
    {
        $this->loginAs();
        $this->makeProduct('ID11-1001', 'Apko 5 Liter', PalletCapacity::UNIT_LITER, 5);

        $this->submit($this->preview($this->sheet([
            $this->row('ID11_1001', 'ID11-1001', 'Apko 5 Liter', 100, 'I126090020'),
        ])));

        $this->submit($this->preview($this->sheet([
            $this->row('ID11_1001', 'ID11-1001', 'Apko 5 Liter', 100, 'I126090037'),
        ])))->assertSessionHas('success');

        $this->assertSame(2, InboundHeader::count());
    }

    /** Huruf besar-kecil dan spasi tidak boleh jadi celah lolos. */
    public function test_beda_spasi_dan_huruf_besar_tetap_terbaca_duplikat(): void
    {
        $this->loginAs();
        $this->makeProduct('ID11-1001', 'Apko 5 Liter', PalletCapacity::UNIT_LITER, 5);

        $this->submit($this->preview($this->sheet([
            $this->row('id11_1001', 'ID11-1001', 'Apko 5 Liter', 100, 'i126090022'),
        ])));

        $this->submit($this->preview($this->sheet([
            $this->row(' ID11_1001 ', 'ID11-1001', 'Apko 5 Liter', 100, ' I126090022 '),
        ])))->assertSessionHas('error');

        $this->assertSame(1, InboundHeader::count());
    }

    /** Pratinjau mengatakannya sebelum disimpan, bukan sesudah. */
    public function test_pratinjau_menandai_baris_duplikat(): void
    {
        $this->loginAs();
        $this->makeProduct('ID11-1001', 'Apko 5 Liter', PalletCapacity::UNIT_LITER, 5);

        $this->submit($this->preview($this->sheet([
            $this->row('ID11_1001', 'ID11-1001', 'Apko 5 Liter', 100, 'I126090022'),
        ])));

        $preview = $this->preview($this->sheet([
            $this->row('ID11_1001', 'ID11-1001', 'Apko 5 Liter', 100, 'I126090022'),
        ]));

        $ringkas = $preview->viewData('summary');
        $this->assertSame(1, $ringkas['bisa_ditimpa']);
        $this->assertSame(0, $ringkas['terkunci']);

        // Tanpa centang baris duplikat dilewati, dengan centang ia masuk.
        // Kartu di layar harus mengikuti centangnya, bukan berhenti di salah
        // satu keadaan saja.
        $this->assertSame(0, $ringkas['akan_disimpan']);
        $this->assertSame(1, $ringkas['akan_disimpan_timpa']);
        $this->assertSame(0, $ringkas['palet_disimpan']);
        $this->assertGreaterThan(0, $ringkas['palet_disimpan_timpa']);

        $baris = $preview->viewData('rows')[0];
        $this->assertSame(DuplikatProduksi::BISA_DITIMPA, $baris['duplikat']['keadaan']);
        $this->assertSame('IN-'.now()->format('ymd').'-001', $baris['duplikat']['dokumen']);
    }

    /** Menimpa harus DIMINTA. Mengunggah ulang bukan permintaan menimpa. */
    public function test_menimpa_hanya_terjadi_bila_dicentang(): void
    {
        $this->loginAs();
        $this->makeProduct('ID11-1001', 'Apko 5 Liter', PalletCapacity::UNIT_LITER, 5);

        $this->submit($this->preview($this->sheet([
            $this->row('ID11_1001', 'ID11-1001', 'Apko 5 Liter', 100, 'I126090022'),
        ])));

        $lama = InboundHeader::first();

        $this->submit($this->preview($this->sheet([
            $this->row('ID11_1001', 'ID11-1001', 'Apko 5 Liter', 250, 'I126090022'),
        ])), ['timpa' => 1])->assertSessionHas('success');

        // Dokumen lama kehilangan seluruh paletnya, jadi ia ditutup.
        $this->assertSoftDeleted('inbound_headers', ['id' => $lama->id]);
        $this->assertSame(0, InboundDetail::where('inbound_header_id', $lama->id)->count());

        $baru = InboundHeader::latest('id')->first();
        $this->assertSame(250, (int) $baru->details()->sum('pallet_qty'));
    }

    /** Baris yang belum pernah masuk tetap ditambahkan saat menimpa. */
    public function test_menimpa_juga_menambahkan_baris_yang_belum_ada(): void
    {
        $this->loginAs();
        $this->makeProduct('ID11-1001', 'Apko 5 Liter', PalletCapacity::UNIT_LITER, 5);

        $this->submit($this->preview($this->sheet([
            $this->row('ID11_1001', 'ID11-1001', 'Apko 5 Liter', 100, 'I126090022'),
        ])));

        $this->submit($this->preview($this->sheet([
            $this->row('ID11_1001', 'ID11-1001', 'Apko 5 Liter', 100, 'I126090022'),
            $this->row('ID11_1001', 'ID11-1001', 'Apko 5 Liter', 100, 'I126090037'),
        ])), ['timpa' => 1])->assertSessionHas('success');

        $baru = InboundHeader::latest('id')->first();

        $this->assertEqualsCanonicalizing(
            ['I126090022', 'I126090037'],
            $baru->details()->pluck('batch_no')->unique()->sort()->values()->all(),
        );
    }

    /**
     * Palet yang sudah naik rak TIDAK boleh ditimpa.
     *
     * Barangnya sudah berdiri di rak dan angkanya sudah dihitung. Menimpanya
     * membuat catatan sistem berbeda dari isi gudang, dan tidak ada yang akan
     * tahu — setiap langkahnya sah bila dilihat sendiri-sendiri.
     */
    public function test_baris_yang_paletnya_sudah_naik_rak_tidak_bisa_ditimpa(): void
    {
        $this->loginAs();
        $this->makeProduct('ID11-1001', 'Apko 5 Liter', PalletCapacity::UNIT_LITER, 5);

        $this->submit($this->preview($this->sheet([
            $this->row('ID11_1001', 'ID11-1001', 'Apko 5 Liter', 100, 'I126090022'),
        ])));

        $lama = InboundHeader::first();
        $lama->details()->update(['putaway_at' => now()]);

        $preview = $this->preview($this->sheet([
            $this->row('ID11_1001', 'ID11-1001', 'Apko 5 Liter', 250, 'I126090022'),
        ]));

        $this->assertSame(1, $preview->viewData('summary')['terkunci']);
        $this->assertSame(0, $preview->viewData('summary')['bisa_ditimpa']);

        // ANGKA LAYARNYA TIDAK BOLEH MEMBANTAH PERINGATANNYA SENDIRI.
        // 'siap' berarti "terbaca utuh" dan tetap 1 di sini; yang dibaca kartu
        // "Siap Disimpan" haruslah yang benar-benar akan tersimpan — nol,
        // dicentang maupun tidak, karena paletnya sudah naik rak.
        $ringkas = $preview->viewData('summary');
        $this->assertSame(1, $ringkas['siap']);
        $this->assertSame(0, $ringkas['akan_disimpan']);
        $this->assertSame(0, $ringkas['akan_disimpan_timpa']);
        $this->assertSame(0, $ringkas['palet_disimpan']);
        $this->assertSame(0, $ringkas['palet_disimpan_timpa']);

        // Dicentang pun tetap ditolak — pagarnya di server, bukan di layar.
        $this->submit($preview, ['timpa' => 1])->assertSessionHas('error');

        $this->assertSame(1, InboundHeader::count());
        $this->assertSame(100, (int) $lama->details()->sum('pallet_qty'));
    }

    /** Gudang berbeda boleh memakai penomoran RMO yang sama tanpa hubungan. */
    public function test_rmo_yang_sama_di_gudang_lain_bukan_duplikat(): void
    {
        $this->loginAs(Role::SUPER_ADMIN);
        $this->makeProduct('ID11-1001', 'Apko 5 Liter', PalletCapacity::UNIT_LITER, 5);

        $this->submit($this->preview($this->sheet([
            $this->row('ID11_1001', 'ID11-1001', 'Apko 5 Liter', 100, 'I126090022'),
        ])));

        $lain = Warehouse::factory()->withProduction()->create(['code' => 'WH-02']);

        $preview = $this->post('/wms/inbound/preview', [
            'file' => $this->sheet([
                $this->row('ID11_1001', 'ID11-1001', 'Apko 5 Liter', 100, 'I126090022'),
            ]),
            'warehouse_id' => $lain->id,
            'production_date' => now()->toDateString(),
        ]);

        $this->assertSame(0, $preview->viewData('summary')['bisa_ditimpa']);
        $this->assertSame(0, $preview->viewData('summary')['terkunci']);
    }

    /* ------------------------------------------------------ Tanggal produksi */

    /** Bawaannya hari ini — yang lazim, tetap yang paling mudah. */
    public function test_tanggal_produksi_bawaannya_hari_ini(): void
    {
        $this->loginAs();

        $this->assertTrue($this->get('/wms/inbound/create')->viewData('productionDate')->isToday());
    }

    /**
     * Produksi tadi malam yang baru sempat diinput pagi ini.
     *
     * Tanggalnya bukan sekadar keterangan: ia menjadi tanggal produksi tiap
     * batch di rak, dan kedaluwarsanya dihitung dari situ.
     */
    public function test_tanggal_produksi_boleh_dimundurkan(): void
    {
        $this->loginAs();
        $this->makeProduct('ID11-1001', 'Apko 5 Liter', PalletCapacity::UNIT_LITER, 5);

        $kemarin = now()->subDay()->toDateString();

        $preview = $this->preview(
            $this->sheet([$this->row('RMO01', 'ID11-1001', 'Apko 5 Liter', 100, 'I126090022')]),
            ['production_date' => $kemarin],
        )->assertOk();

        $this->submit($preview)->assertSessionHas('success');

        $this->assertSame($kemarin, InboundHeader::first()->production_date->toDateString());
    }

    /** Barang yang belum dibuat tidak bisa naik rak. */
    public function test_tanggal_produksi_di_masa_depan_ditolak(): void
    {
        $this->loginAs();
        $this->makeProduct('ID11-1001', 'Apko 5 Liter', PalletCapacity::UNIT_LITER, 5);

        $this->preview(
            $this->sheet([$this->row('RMO01', 'ID11-1001', 'Apko 5 Liter', 100, 'I126090022')]),
            ['production_date' => now()->addDay()->toDateString()],
        )->assertSessionHasErrors('production_date');

        $this->assertSame(0, InboundHeader::count());
    }

    /** Penangkap salah ketik tahun/bulan, bukan aturan bisnis. */
    public function test_tanggal_produksi_terlalu_jauh_ke_belakang_ditolak(): void
    {
        $this->loginAs();
        $this->makeProduct('ID11-1001', 'Apko 5 Liter', PalletCapacity::UNIT_LITER, 5);

        $this->preview(
            $this->sheet([$this->row('RMO01', 'ID11-1001', 'Apko 5 Liter', 100, 'I126090022')]),
            ['production_date' => now()->subDays(120)->toDateString()],
        )->assertSessionHasErrors('production_date');
    }

    /**
     * Pagarnya di server, bukan di layar.
     *
     * Layar pratinjau mengirim ulang tanggalnya sebagai input tersembunyi —
     * dan input tersembunyi tetap saja input.
     */
    public function test_tanggal_tidak_sah_tetap_ditolak_di_langkah_simpan(): void
    {
        $this->loginAs();
        $this->makeProduct('ID11-1001', 'Apko 5 Liter', PalletCapacity::UNIT_LITER, 5);

        $preview = $this->preview($this->sheet([
            $this->row('RMO01', 'ID11-1001', 'Apko 5 Liter', 100, 'I126090022'),
        ]));

        $this->submit($preview, ['production_date' => now()->addYear()->toDateString()])
            ->assertSessionHasErrors('production_date');

        $this->assertSame(0, InboundHeader::count());
    }

    /** Tanggal pilihan Produksi ikut ke layar pratinjau, bukan diganti hari ini. */
    public function test_pratinjau_menampilkan_tanggal_yang_dipilih(): void
    {
        $this->loginAs();
        $this->makeProduct('ID11-1001', 'Apko 5 Liter', PalletCapacity::UNIT_LITER, 5);

        $kemarin = now()->subDays(3)->toDateString();

        $preview = $this->preview(
            $this->sheet([$this->row('RMO01', 'ID11-1001', 'Apko 5 Liter', 100, 'I126090022')]),
            ['production_date' => $kemarin],
        );

        $this->assertSame($kemarin, $preview->viewData('productionDate')->toDateString());
    }

    /* ------------------------------------------------------------- Tombol */

    /**
     * Tombol langkah pertama TIDAK boleh menyebut submit.
     *
     * Ia tidak menyimpan apa pun. Menyebutnya "submit" membuat orang menutup
     * layar berikutnya dan mengira pekerjaannya sudah selesai — padahal
     * dokumennya tidak pernah ada.
     */
    public function test_tombol_langkah_pertama_bernama_check_bukan_submit(): void
    {
        $this->loginAs();

        $this->get('/wms/inbound/create')
            ->assertOk()
            ->assertSee('Check')
            ->assertDontSee('Baca &amp; Pratinjau', false);
    }

    public function test_tombol_penyimpan_bernama_submit(): void
    {
        $this->loginAs();
        $this->makeProduct('ID11-1001', 'Apko 5 Liter', PalletCapacity::UNIT_LITER, 5);

        $this->preview($this->sheet([
            $this->row('RMO01', 'ID11-1001', 'Apko 5 Liter', 100, 'I126090022'),
        ]))->assertOk()->assertSee('Submit');
    }
}
