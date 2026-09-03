<?php

namespace Tests\Feature\Wms;

use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Impor Surat Jalan dari sistem BC — PRD §6.5 F-OUT-04, Fase 6 tahap 4.
 *
 * SURAT JALAN DITERBITKAN BC, BUKAN SISTEM INI. Yang diuji di sini adalah
 * penyalinannya, dan tiga hal yang kalau salah tidak langsung terlihat:
 *
 * 1. QTY BERKOMA. Ekspor BC menulis "1," dan "10," untuk 1 dan 10. Kalau
 *    dibaca mentah-mentah, seluruh angkanya salah — dan salahnya masuk akal
 *    sehingga tidak menimbulkan curiga.
 * 2. SATU DOKUMEN, BANYAK BARIS. Satu Document No. lazim memuat beberapa
 *    SKU; memperlakukan tiap baris sebagai satu dokumen akan melahirkan
 *    dokumen kembar.
 * 3. BERKAS ADALAH KEBENARAN. Impor ulang dokumen yang sama harus
 *    menghasilkan keadaan yang sama, bukan qty berlipat.
 *
 * Berkas .xlsx dibuat sungguhan lalu diunggah, bukan hasil pembacaan yang
 * dipalsukan — supaya jalur PhpSpreadsheet dan pemetaan judul kolom ikut
 * teruji.
 */
class DeliveryNoteImportTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $karawang;

    private Warehouse $pekanbaru;

    private Product $produk;

    private Customer $customer;

    private PaymentTerm $term;

    /** Judul kolom apa adanya dari ekspor BC. */
    private const JUDUL = [
        'Document No.', 'SO Number', 'Sell-to Customer No.', 'No.', 'Description',
        'Location Code', 'Quantity', 'Unit of Measure Code', 'Shipment Date', 'Quantity Invoiced',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->karawang = Warehouse::factory()->withProduction()->create(['code' => 'WH-01', 'name' => 'Karawang']);
        $this->pekanbaru = Warehouse::factory()->create(['code' => 'WH-02', 'name' => 'Pekanbaru']);

        $this->produk = Product::factory()->create([
            'sku' => 'ID1-F0017X002820', 'name' => 'Bocor Guard 2 Base 20Kg', 'uom' => 'PAIL', 'is_active' => true,
        ]);
        $this->customer = Customer::factory()->create(['code' => 'IDR13302', 'is_active' => true]);
        $this->term = PaymentTerm::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Cash / Tunai', 'days' => 0, 'is_active' => true, 'sort_order' => 1]
        );
    }

    /* ------------------------------------------------------------ Perkakas */

    private function loginAt(?Warehouse $gudang, string $slug = Role::LOGISTICS): User
    {
        $user = User::factory()->withRole($slug)->create(['warehouse_id' => $gudang?->id]);
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

    private function makeXlsx(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(self::JUDUL, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'sj').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'surat-jalan.xlsx', null, null, true);
    }

    /**
     * Satu baris ekspor BC. Qty ditulis "10," persis seperti berkas aslinya.
     */
    private function baris(string $dokumen, string $so, string $sku, string $qty = '10,'): array
    {
        return [
            $dokumen, $so, 'IDR13302', $sku, 'Bocor Guard 2 Base 20Kg',
            'ID1B_1001', $qty, 'PAI', '31/08/2026', $qty,
        ];
    }

    /** Menjalankan pratinjau lalu menyimpan, seperti yang dilakukan pengguna. */
    private function impor(array $rows)
    {
        $preview = $this->post(route('wms.delivery.import.preview'), ['file' => $this->makeXlsx($rows)]);

        $preview->assertOk();

        return $this->post(route('wms.delivery.import'), [
            'token' => $preview->viewData('token'),
            'extension' => 'xlsx',
        ]);
    }

    private function pesanan(string $nomorSo, ?Warehouse $gudang = null): SalesOrder
    {
        return SalesOrder::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => ($gudang ?? $this->karawang)->id,
            'payment_term_id' => $this->term->id,
            'status' => SalesOrder::STATUS_READY_TO_SHIP,
            'bc_so_number' => $nomorSo,
            'submitted_at' => now()->subDay(),
            'approved_at' => now()->subDay(),
        ]);
    }

    /* ------------------------------------------------------------- Membaca */

    public function test_qty_berkoma_dari_ekspor_bc_terbaca_sebagai_bilangan_bulat(): void
    {
        $this->pesanan('SO260903');
        $this->loginAt($this->karawang);

        // "1," dan "10," pada ekspor BC berarti 1 dan 10 — koma di situ
        // pemisah desimal, bukan pemisah ribuan.
        $this->impor([
            $this->baris('206215', 'SO260903', 'ID1-F0017X002820', '1,'),
        ]);

        $this->assertSame(1, DeliveryNoteLine::first()->qty);
    }

    public function test_satu_dokumen_memuat_banyak_baris_bukan_dokumen_kembar(): void
    {
        Product::factory()->create(['sku' => 'ID1-F00133202820', 'is_active' => true]);
        $this->pesanan('SO260903');
        $this->loginAt($this->karawang);

        $this->impor([
            $this->baris('206215', 'SO260903', 'ID1-F0017X002820', '1,'),
            $this->baris('206215', 'SO260903', 'ID1-F00133202820', '2,'),
        ]);

        $this->assertSame(1, DeliveryNote::count(), 'Satu Document No. harus jadi satu dokumen.');
        $this->assertSame(2, DeliveryNoteLine::count());
        $this->assertSame(3, DeliveryNote::first()->total_qty);
    }

    public function test_dokumen_dicocokkan_ke_pesanan_lewat_nomor_so(): void
    {
        $order = $this->pesanan('SO260903');
        $this->loginAt($this->karawang);

        $this->impor([$this->baris('206215', 'SO260903', 'ID1-F0017X002820')]);

        $note = DeliveryNote::first();

        $this->assertSame($order->id, $note->sales_order_id);
        $this->assertSame($this->karawang->id, $note->warehouse_id);
        $this->assertSame($this->customer->id, $note->customer_id);
        $this->assertSame($this->produk->id, $note->lines()->first()->product_id);
    }

    public function test_sku_dan_deskripsi_bc_disimpan_apa_adanya(): void
    {
        $this->pesanan('SO260903');
        $this->loginAt($this->karawang);

        $this->impor([$this->baris('206215', 'SO260903', 'ID1-F0017X002820')]);

        $baris = DeliveryNoteLine::first();

        // Deskripsi versi BC disimpan sebagai catatan, TETAPI nama produk
        // kami tidak ikut berubah — aturan yang sama dengan penerimaan
        // pesanan di tahap 1.
        $this->assertSame('ID1-F0017X002820', $baris->sku);
        $this->assertSame('Bocor Guard 2 Base 20Kg', $baris->description);
        $this->assertSame('Bocor Guard 2 Base 20Kg', $this->produk->fresh()->name);
    }

    public function test_tanggal_kirim_format_indonesia_terbaca(): void
    {
        $this->pesanan('SO260903');
        $this->loginAt($this->karawang);

        $this->impor([$this->baris('206215', 'SO260903', 'ID1-F0017X002820')]);

        $this->assertSame('2026-08-31', DeliveryNote::first()->shipment_date->toDateString());
    }

    /* --------------------------------------------------------- Idempotensi */

    public function test_impor_ulang_menyamakan_bukan_melipatgandakan(): void
    {
        $this->pesanan('SO260903');
        $this->loginAt($this->karawang);

        $this->impor([$this->baris('206215', 'SO260903', 'ID1-F0017X002820', '10,')]);
        $this->impor([$this->baris('206215', 'SO260903', 'ID1-F0017X002820', '10,')]);

        $this->assertSame(1, DeliveryNote::count());
        $this->assertSame(1, DeliveryNoteLine::count());
        $this->assertSame(10, DeliveryNoteLine::first()->qty, 'Qty disamakan dengan berkas, bukan ditambahkan.');
    }

    public function test_baris_yang_dicabut_di_bc_ikut_hilang_saat_impor_ulang(): void
    {
        Product::factory()->create(['sku' => 'ID1-F00133202820', 'is_active' => true]);
        $this->pesanan('SO260903');
        $this->loginAt($this->karawang);

        $this->impor([
            $this->baris('206215', 'SO260903', 'ID1-F0017X002820'),
            $this->baris('206215', 'SO260903', 'ID1-F00133202820'),
        ]);

        // Dokumen yang sama diekspor ulang, kali ini tanpa SKU kedua.
        // Baris lamanya TIDAK boleh tertinggal: qty-nya ikut terhitung saat
        // pencocokan, dan barang yang sudah dicabut dari dokumen resmi akan
        // tampak masih harus dikirim.
        $this->impor([$this->baris('206215', 'SO260903', 'ID1-F0017X002820')]);

        $this->assertSame(1, DeliveryNoteLine::count());
        $this->assertSame('ID1-F0017X002820', DeliveryNoteLine::first()->sku);
    }

    /* ------------------------------------------------------------ Penolakan */

    public function test_baris_tanpa_nomor_so_ditolak(): void
    {
        $this->loginAt($this->karawang);

        $this->impor([$this->baris('206215', '', 'ID1-F0017X002820')])
            ->assertSessionHas('warning');

        $this->assertSame(0, DeliveryNote::count());
    }

    public function test_qty_pecahan_ditolak_bukan_dibulatkan(): void
    {
        $this->pesanan('SO260903');
        $this->loginAt($this->karawang);

        // Cat dijual per kaleng. "2,5 pail" pada dokumen resmi adalah tanda
        // berkasnya salah, bukan angka yang perlu ditebak maksudnya.
        $this->impor([$this->baris('206215', 'SO260903', 'ID1-F0017X002820', '2,5')])
            ->assertSessionHas('warning');

        $this->assertSame(0, DeliveryNoteLine::count());
    }

    public function test_surat_jalan_gudang_lain_ditolak(): void
    {
        $this->pesanan('SO260904', $this->pekanbaru);
        $this->loginAt($this->karawang);

        // Ekspor harian BC memuat SJ seluruh perusahaan. Baris milik gudang
        // lain ditolak dengan menyebut gudangnya, bukan disimpan diam-diam
        // ke data yang tidak bisa dilihat pengimpornya.
        $this->impor([$this->baris('206222', 'SO260904', 'ID1-F0017X002820')])
            ->assertSessionHas('warning');

        $this->assertSame(0, DeliveryNote::count());
    }

    public function test_akun_lintas_gudang_boleh_mengimpor_semuanya(): void
    {
        $this->pesanan('SO260903', $this->karawang);
        $this->pesanan('SO260904', $this->pekanbaru);
        $this->loginAt(null, Role::SUPER_ADMIN);

        $this->impor([
            $this->baris('206215', 'SO260903', 'ID1-F0017X002820'),
            $this->baris('206222', 'SO260904', 'ID1-F0017X002820'),
        ]);

        $this->assertSame(2, DeliveryNote::count());
    }

    /* ------------------------------------------------------ Tanpa pasangan */

    public function test_surat_jalan_tanpa_pesanan_tetap_disimpan_dan_dilaporkan(): void
    {
        $this->loginAt($this->karawang);

        // Bukan kegagalan: ekspor BC memuat pesanan yang tidak pernah lewat
        // portal ini. Tetapi harus TERLIHAT — kalau seharusnya berpasangan
        // dan ternyata tidak, berarti nomor SO-nya berbeda.
        $this->impor([$this->baris('206215', 'SO-TIDAK-ADA', 'ID1-F0017X002820')])
            ->assertSessionHas('warning');

        $note = DeliveryNote::first();

        $this->assertNotNull($note);
        $this->assertNull($note->sales_order_id);
        $this->assertSame(1, DeliveryNote::belumBerpasangan()->count());
    }

    public function test_nomor_so_dipegang_pesanan_induk_bukan_pesanan_gabungan(): void
    {
        $induk = $this->pesanan('SO260903');

        // Pesanan tambahan yang menumpang nomor SO yang sama (gabung invoice,
        // susulan tahap 1). Tanpa penyaring so_merged_into_id, pencocokan
        // bisa mengembalikan pesanan tambahan ini dan hasilnya tergantung
        // urutan baris di database.
        SalesOrder::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->karawang->id,
            'payment_term_id' => $this->term->id,
            'status' => SalesOrder::STATUS_READY_TO_SHIP,
            'bc_so_number' => 'SO260903',
            'so_merged_into_id' => $induk->id,
            'submitted_at' => now()->subDay(),
            'approved_at' => now()->subDay(),
        ]);

        $this->loginAt($this->karawang);
        $this->impor([$this->baris('206215', 'SO260903', 'ID1-F0017X002820')]);

        $this->assertSame($induk->id, DeliveryNote::first()->sales_order_id);
    }

    /* ------------------------------------------------------------- Halaman */

    public function test_halaman_surat_jalan_hanya_memuat_gudang_sendiri(): void
    {
        DeliveryNote::factory()->create([
            'document_no' => '206215', 'warehouse_id' => $this->karawang->id,
        ]);
        DeliveryNote::factory()->create([
            'document_no' => '206222', 'warehouse_id' => $this->pekanbaru->id,
        ]);

        $this->loginAt($this->karawang);

        $this->get(route('wms.delivery.index'))
            ->assertOk()
            ->assertSee('206215')
            ->assertDontSee('206222');
    }

    public function test_operator_gudang_tidak_boleh_membuka_surat_jalan(): void
    {
        $this->loginAt($this->karawang, Role::WAREHOUSE_OPERATOR);

        $this->get(route('wms.delivery.index'))->assertForbidden();
    }
}
