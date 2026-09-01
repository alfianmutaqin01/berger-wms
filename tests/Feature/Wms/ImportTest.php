<?php

namespace Tests\Feature\Wms;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Impor master data dari Excel.
 *
 * Membuat berkas .xlsx sungguhan lalu mengunggahnya, bukan memalsukan hasil
 * pembacaan — supaya jalur PhpSpreadsheet, unggahan, dan pemetaan kolom
 * benar-benar teruji ujung ke ujung.
 */
class ImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Berkas unggahan diarahkan ke disk tiruan agar test tidak pernah
        // menyentuh storage/app/private. Selain menjaga test tetap terisolasi,
        // ini mencegah folder di sana terbuat oleh proses yang menjalankan
        // test — kepemilikannya bisa berbeda dari pengguna PHP-FPM, sehingga
        // permintaan HTTP sungguhan jadi gagal menulis ke folder yang sama.
        Storage::fake('local');
    }

    private function loginAs(string $roleSlug = Role::SUPER_ADMIN): User
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

    /** Membuat berkas .xlsx sungguhan berisi baris yang diberikan. */
    private function makeXlsx(array $headers, array $rows, string $name = 'data.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function productFile(array $rows): UploadedFile
    {
        return $this->makeXlsx([
            'No.', 'Description', 'Product Code', 'Shade Code', 'Pack Code', 'Inventory',
            'Base Unit of Measure', 'Net Weight', 'Gross Weight', 'Unit Volume', 'Product Type',
        ], $rows);
    }

    private function customerFile(array $rows): UploadedFile
    {
        return $this->makeXlsx([
            'No./id', 'Ship-to Code', 'Name', 'Phone No.', 'Contact', 'Email',
            'Address', 'Address 2', 'Territory Code',
        ], $rows);
    }

    /* -------------------------------------------------------------- Produk */

    public function test_pratinjau_produk_tidak_menyimpan_apa_pun(): void
    {
        $this->loginAs();

        $file = $this->productFile([
            ['ID1-F00113202225', 'Royale Smart Clean White 2.5Ltr', '0011', '3202', '225', 126, 'TIN', 0, 4.05, 2.5, 'Royale Smart Clean'],
        ]);

        $this->post('/wms/master/products/import/preview', ['file' => $file])
            ->assertOk()
            ->assertViewIs('wms.master.import-preview')
            ->assertViewHas('summary', fn ($s) => $s['total'] === 1 && $s['baru'] === 1 && $s['perbarui'] === 0);

        // Inti pratinjau: database belum tersentuh sama sekali.
        $this->assertSame(0, Product::count());
    }

    public function test_impor_produk_menyimpan_data(): void
    {
        $this->loginAs();

        $file = $this->productFile([
            ['ID1-F00113202225', 'Royale Smart Clean White 2.5Ltr', '0011', '3202', '225', 126, 'TIN', 0, 4.05, 2.5, 'Royale Smart Clean'],
            ['ID1-F0011B128320', 'Royale Smart Clean Blue Smoke 20Ltr', '0011', 'B128', '320', 0, 'PAIL', 0, 26.32, 19.4, 'Royale Smart Clean'],
        ]);

        $preview = $this->post('/wms/master/products/import/preview', ['file' => $file]);
        $this->submitImport($preview, 'products')->assertSessionHas('success');

        $this->assertSame(2, Product::count());

        $tin = Product::where('sku', 'ID1-F00113202225')->firstOrFail();
        $this->assertSame('Royale Smart Clean White 2.5Ltr', $tin->name);
        $this->assertSame('TIN', $tin->uom);
        $this->assertSame(180, $tin->max_qty_per_pallet);
    }

    /**
     * Kapasitas palet tetap memakai ukuran WADAH, bukan volume isi.
     *
     * Regresi: pail "20Ltr" berisi 19.4 L. Bila importer memakai Unit Volume,
     * produk ini salah dianggap tidak punya aturan palet.
     */
    public function test_impor_menghitung_palet_dari_ukuran_wadah(): void
    {
        $this->loginAs();

        $file = $this->productFile([
            ['ID1-F0011B128320', 'Royale Smart Clean Blue Smoke 20Ltr', '0011', 'B128', '320', 0, 'PAIL', 0, 26.32, 19.4, 'Royale Smart Clean'],
        ]);

        $preview = $this->post('/wms/master/products/import/preview', ['file' => $file]);
        $this->submitImport($preview, 'products');

        $product = Product::where('sku', 'ID1-F0011B128320')->firstOrFail();

        $this->assertSame(27, $product->max_qty_per_pallet);
        $this->assertSame('20.000', $product->pack_size);
        $this->assertSame('19.400', $product->unit_volume);
    }

    /** Kolom Inventory pada ekspor ERP tidak boleh ikut tersimpan. */
    public function test_kolom_inventory_diabaikan(): void
    {
        $this->loginAs();

        $file = $this->productFile([
            ['ID1-F00113202225', 'Royale Smart Clean White 2.5Ltr', '0011', '3202', '225', 126, 'TIN', 0, 4.05, 2.5, 'Royale Smart Clean'],
        ]);

        $preview = $this->post('/wms/master/products/import/preview', ['file' => $file]);
        $this->submitImport($preview, 'products');

        $this->assertNotContains('inventory', array_keys(Product::first()->getAttributes()));
    }

    /** SKU yang sudah ada diperbarui, bukan diduplikasi. */
    public function test_produk_yang_sudah_ada_diperbarui(): void
    {
        $this->loginAs();
        Product::factory()->create(['sku' => 'ID1-F00113202225', 'name' => 'Nama Lama']);

        $file = $this->productFile([
            ['ID1-F00113202225', 'Royale Smart Clean White 2.5Ltr', '0011', '3202', '225', 126, 'TIN', 0, 4.05, 2.5, 'Royale Smart Clean'],
        ]);

        $this->post('/wms/master/products/import/preview', ['file' => $file])
            ->assertViewHas('summary', fn ($s) => $s['baru'] === 0 && $s['perbarui'] === 1);

        $preview = $this->post('/wms/master/products/import/preview', ['file' => $this->productFile([
            ['ID1-F00113202225', 'Royale Smart Clean White 2.5Ltr', '0011', '3202', '225', 126, 'TIN', 0, 4.05, 2.5, 'Royale Smart Clean'],
        ])]);
        $this->submitImport($preview, 'products');

        $this->assertSame(1, Product::count());
        $this->assertSame('Royale Smart Clean White 2.5Ltr', Product::first()->name);
    }

    /** Impor ulang tidak boleh menghidupkan produk yang sengaja dinonaktifkan. */
    public function test_impor_tidak_mengaktifkan_kembali_produk_nonaktif(): void
    {
        $this->loginAs();
        Product::factory()->inactive()->create(['sku' => 'ID1-F00113202225']);

        $preview = $this->post('/wms/master/products/import/preview', ['file' => $this->productFile([
            ['ID1-F00113202225', 'Royale Smart Clean White 2.5Ltr', '0011', '3202', '225', 126, 'TIN', 0, 4.05, 2.5, 'Royale Smart Clean'],
        ])]);
        $this->submitImport($preview, 'products');

        $this->assertFalse(Product::where('sku', 'ID1-F00113202225')->value('is_active'));
    }

    /** "Tidak ditemukan" adalah penanda kegagalan ERP, bukan nama kategori. */
    public function test_product_type_tidak_ditemukan_tidak_membuat_kategori(): void
    {
        $this->loginAs();

        $preview = $this->post('/wms/master/products/import/preview', ['file' => $this->productFile([
            ['ID1-F0011X000225', 'Royale Smart Clean 0 Base 2.5Ltr', '0011', 'X000', '225', 9, 'TIN', 0, 3.99, 2.425, 'Tidak ditemukan'],
        ])]);
        $this->submitImport($preview, 'products');

        $this->assertNull(Product::first()->category_id);
        $this->assertSame(0, ProductCategory::where('name', 'Tidak ditemukan')->count());
    }

    public function test_baris_tanpa_description_dilewati(): void
    {
        $this->loginAs();

        $this->post('/wms/master/products/import/preview', ['file' => $this->productFile([
            ['ID1-F00113202225', '', '0011', '3202', '225', 126, 'TIN', 0, 4.05, 2.5, 'Royale Smart Clean'],
        ])])->assertViewHas('summary', fn ($s) => $s['gagal'] === 1 && $s['baru'] === 0);
    }

    public function test_berkas_tanpa_kolom_wajib_ditolak(): void
    {
        $this->loginAs();

        $file = $this->makeXlsx(['Kolom A', 'Kolom B'], [['x', 'y']]);

        $this->post('/wms/master/products/import/preview', ['file' => $file])
            ->assertRedirect(route('wms.products.index'))
            ->assertSessionHas('error');
    }

    public function test_berkas_selain_excel_ditolak(): void
    {
        $this->loginAs();

        $this->post('/wms/master/products/import/preview', [
            'file' => UploadedFile::fake()->create('data.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('file');
    }

    /* ----------------------------------------------------------- Pelanggan */

    public function test_impor_pelanggan_menyimpan_data(): void
    {
        $this->loginAs();

        $file = $this->makeXlsx(
            ['No./id', 'Ship-to Code', 'Name', 'Phone No.', 'Contact', 'Email', 'Address', 'Address 2', 'Territory Code'],
            [
                ['IDI10101', '1061600017', 'PT PANDU BIO POLIMER', '6289531435435', '', 'MARKETING@PANDUBIOPOLIMER.COM',
                    'JL RAYA PONDOK GEDE NO. 17 A', 'JAKARTA TIMUR, DKI JAKARTA', 'PROJECT'],
                ['IDI10102', '', 'PT VICTORINDO INTI CEMERLANG', '6282110878778', '', 'PURCHASING.PTVIC@YAHOO.COM',
                    'JL KAMAL MUARA VII', 'BLOK A1 NO 5 KAMAR MUARA', 'PROJECT'],
            ]
        );

        $preview = $this->post('/wms/master/customers/import/preview', ['file' => $file]);
        $this->submitImport($preview, 'customers')->assertSessionHas('success');

        $this->assertSame(2, Customer::count());

        $first = Customer::where('code', 'IDI10101')->firstOrFail();
        $this->assertSame('1061600017', $first->ship_to_code);
        $this->assertSame('6289531435435', $first->phone);
        $this->assertSame('JL RAYA PONDOK GEDE NO. 17 A, JAKARTA TIMUR, DKI JAKARTA', $first->full_address);

        // Ship-to Code kosong tetap NULL, bukan string kosong.
        $this->assertNull(Customer::where('code', 'IDI10102')->value('ship_to_code'));
    }

    /**
     * Sel ekspor ERP yang memuat dua nomor sekaligus.
     *
     * Dulu semua karakter bukan angka dibuang, sehingga
     * "6285775005758/6282233024171" menyatu menjadi 26 digit — melampaui
     * kolom dan menghentikan seluruh impor dengan galat SQLSTATE 22001.
     */
    public function test_pelanggan_dengan_dua_nomor_telepon_tersimpan_keduanya(): void
    {
        $this->loginAs();

        $file = $this->customerFile([
            ['IDR13294', '', 'CV MAKMUR CITRA ABADI', '6285775005758/6282233024171', '', '',
                'JL. RAYA MENGANTI WIYUNG, 18, WIYUNG', 'WIYUNG', 'JAWA TIMUR'],
        ]);

        $preview = $this->post('/wms/master/customers/import/preview', ['file' => $file]);
        $this->submitImport($preview, 'customers')->assertSessionHas('success');

        $pelanggan = Customer::where('code', 'IDR13294')->firstOrFail();

        $this->assertSame('6285775005758 / 6282233024171', $pelanggan->phone);
        $this->assertSame('+6285775005758 / +6282233024171', $pelanggan->phone_label);
    }

    /**
     * Isi yang melampaui panjang kolom dilaporkan sebagai kegagalan BARIS,
     * lengkap dengan nama kolomnya seperti tertulis di berkas — bukan galat
     * basis data mentah, dan tanpa menyentuh database.
     */
    public function test_isi_melebihi_panjang_kolom_ditandai_di_pratinjau(): void
    {
        $this->loginAs();

        $file = $this->customerFile([
            ['IDI10101', '', 'PT AMAN', '6289531435435', '', '', 'JL SEHAT 1', '', 'PROJECT'],
            ['IDI10102', '', 'PT KEPANJANGAN', str_repeat('9', 60), '', '', 'JL SEHAT 2', '', 'PROJECT'],
        ]);

        $this->post('/wms/master/customers/import/preview', ['file' => $file])
            ->assertOk()
            ->assertViewHas('summary', fn ($s) => $s['baru'] === 1 && $s['gagal'] === 1)
            ->assertViewHas('rows', function ($rows) {
                $gagal = collect($rows)->firstWhere('status', 'gagal');

                return $gagal !== null
                    && str_contains($gagal['message'], 'Phone No.')
                    && str_contains($gagal['message'], 'maksimum 50');
            });

        $this->assertSame(0, Customer::count());
    }

    /**
     * Yang paling penting: satu baris bermasalah TIDAK boleh menghentikan
     * sisa berkas. Sebelumnya impor 1.863 pelanggan berhenti di baris 1.731
     * dan meninggalkan data separuh jalan tanpa penjelasan.
     */
    public function test_satu_baris_gagal_tidak_menghentikan_baris_sesudahnya(): void
    {
        $this->loginAs();

        $file = $this->customerFile([
            ['IDI10101', '', 'PT SEBELUM', '6281111111111', '', '', 'JL A', '', 'PROJECT'],
            ['IDI10102', '', 'PT RUSAK', str_repeat('9', 60), '', '', 'JL B', '', 'PROJECT'],
            ['IDI10103', '', 'PT SESUDAH', '6283333333333', '', '', 'JL C', '', 'PROJECT'],
        ]);

        $preview = $this->post('/wms/master/customers/import/preview', ['file' => $file]);
        $this->submitImport($preview, 'customers')->assertSessionHas('warning');

        // Baris sesudah yang gagal tetap masuk.
        $this->assertTrue(Customer::where('code', 'IDI10101')->exists());
        $this->assertFalse(Customer::where('code', 'IDI10102')->exists());
        $this->assertTrue(Customer::where('code', 'IDI10103')->exists());
    }

    /** Alasan kegagalan ikut ditampilkan, bukan sekadar jumlahnya. */
    public function test_pesan_impor_menyebut_baris_dan_alasan_kegagalan(): void
    {
        $this->loginAs();

        $file = $this->customerFile([
            ['IDI10102', '', 'PT RUSAK', str_repeat('9', 60), '', '', 'JL B', '', 'PROJECT'],
        ]);

        $preview = $this->post('/wms/master/customers/import/preview', ['file' => $file]);
        $this->submitImport($preview, 'customers')
            ->assertSessionHas('warning', fn ($pesan) => str_contains($pesan, 'Baris 2')
                && str_contains($pesan, 'Phone No.'));
    }

    /* ---------------------------------------------------------- Otorisasi */

    public function test_role_operasional_tidak_boleh_mengimpor(): void
    {
        foreach ([Role::LOGISTICS, Role::PRODUCTION, Role::WAREHOUSE_OPERATOR] as $slug) {
            $this->loginAs($slug);

            $this->post('/wms/master/products/import/preview', [
                'file' => $this->productFile([['ID1-F1', 'X', '0011', '3202', '225', 1, 'TIN', 0, 1, 1, '']]),
            ])->assertForbidden();
        }
    }

    /* ------------------------------------------------------------ Batalkan */

    public function test_membatalkan_pratinjau_tidak_menyimpan_data(): void
    {
        $this->loginAs();

        $preview = $this->post('/wms/master/products/import/preview', ['file' => $this->productFile([
            ['ID1-F00113202225', 'Royale Smart Clean White 2.5Ltr', '0011', '3202', '225', 126, 'TIN', 0, 4.05, 2.5, ''],
        ])]);

        $this->post('/wms/master/products/import/cancel', [
            'token' => $preview->viewData('token'),
            'extension' => $preview->viewData('extension'),
        ])->assertRedirect(route('wms.products.index'));

        $this->assertSame(0, Product::count());
    }

    /** Meneruskan hasil pratinjau ke tahap simpan. */
    private function submitImport($preview, string $type)
    {
        return $this->post("/wms/master/{$type}/import", [
            'token' => $preview->viewData('token'),
            'extension' => $preview->viewData('extension'),
        ]);
    }
}
