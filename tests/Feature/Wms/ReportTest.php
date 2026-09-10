<?php

namespace Tests\Feature\Wms;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Reporting\ReportCatalog;
use App\Support\Reporting\ReportRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * Laporan & Ekspor — Fase 11 tahap 4.
 *
 * LIMA HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * -------------------------------------------------
 * 1. TOMBOLNYA HARUS BENAR-BENAR MENGUNDUH. Halaman lamanya punya delapan
 *    tombol unduh yang isinya alert() belaka. Berkasnya kini dibuka lagi oleh
 *    test ini dan isinya diperiksa sel per sel — bukan sekadar memastikan
 *    responsnya 200.
 * 2. BERKASNYA HARUS BERISI SELURUH BARIS, bukan 25 baris pratinjau. Kalau
 *    keduanya tertukar, tidak akan ada yang mengeluh: berkasnya tetap terbuka
 *    dan tetap masuk akal, cuma kurang datanya.
 * 3. BATAS GUDANG BERLAKU DI UNDUHAN JUGA. Satu berkas Excel yang keluar
 *    sekali bisa beredar selamanya — kebocoran di sini tidak bisa ditarik.
 * 4. RENTANG TANGGAL HARUS MENCAKUP HARI TERAKHIRNYA. Batas '<= 30 September'
 *    sebagai timestamp berarti pukul 00:00, dan seluruh isi tanggal 30 hilang
 *    tanpa suara.
 * 5. UNDUHAN MENINGGALKAN JEJAK. Data pelanggan beserta volume pembeliannya
 *    keluar dari sistem di sini; siapa yang mengeluarkannya harus tercatat.
 */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $karawang;

    private Warehouse $pekanbaru;

    private Customer $pelanggan;

    private Product $produk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->karawang = Warehouse::factory()->create(['code' => 'WH-01', 'name' => 'Karawang']);
        $this->pekanbaru = Warehouse::factory()->create(['code' => 'WH-02', 'name' => 'Pekanbaru']);
        $this->pelanggan = Customer::factory()->create(['code' => 'C-001', 'name' => 'Toko Melati']);
        $this->produk = Product::factory()->create(['sku' => 'APKO-5L', 'name' => 'Apko 5 Liter', 'uom' => 'PAIL']);
    }

    private function login(string $slug = Role::SUPER_ADMIN, ?Warehouse $gudang = null): User
    {
        $user = User::factory()->withRole($slug)->create([
            'warehouse_id' => $slug === Role::SUPER_ADMIN ? $gudang?->id : ($gudang ?? $this->karawang)->id,
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
        $this->actingAs($user);

        return $user;
    }

    /** Satu pesanan selesai lengkap dengan barisnya. */
    private function pesananSelesai(
        Warehouse $gudang,
        User $sales,
        int $dipesan = 10,
        int $terkirim = 10,
        ?string $selesai = null,
        ?Product $produk = null,
    ): SalesOrder {
        $order = SalesOrder::factory()->create([
            'customer_id' => $this->pelanggan->id,
            'user_id' => $sales->id,
            'warehouse_id' => $gudang->id,
            'status' => SalesOrder::STATUS_COMPLETED,
            'submitted_at' => now()->subDays(3),
            'approved_at' => now()->subDays(3),
            'shipped_at' => $selesai ? Carbon::parse($selesai) : now()->subDay(),
            'completed_at' => $selesai ? Carbon::parse($selesai) : now()->subDay(),
        ]);

        SalesOrderDetail::create([
            'sales_order_id' => $order->id,
            'product_id' => ($produk ?? $this->produk)->id,
            'qty_ordered' => $dipesan,
            'qty_approved' => $dipesan,
            'qty_shipped' => $terkirim,
            'outstanding_qty' => max($dipesan - $terkirim, 0),
        ]);

        return $order;
    }

    /* ---------------------------------------------------------------- Akses */

    public function test_hanya_peran_berlaporan_yang_bisa_membuka(): void
    {
        foreach ([Role::WAREHOUSE_OPERATOR, Role::PRODUCTION] as $slug) {
            $this->login($slug);
            $this->get(route('wms.reports.index'))->assertForbidden();
            $this->get(route('wms.reports.show', 'penjualan-selesai'))->assertForbidden();
            $this->get(route('wms.reports.download', 'penjualan-selesai'))->assertForbidden();
        }

        foreach ([Role::SUPER_ADMIN, Role::MANAGER, Role::LOGISTICS] as $slug) {
            $this->login($slug);
            $this->get(route('wms.reports.index'))->assertOk();
        }
    }

    public function test_kunci_laporan_yang_tidak_dikenal_menghasilkan_404(): void
    {
        $this->login();

        $this->get('/wms/reports/laporan-karangan')->assertNotFound();
        $this->get('/wms/reports/laporan-karangan/unduh')->assertNotFound();
    }

    /* ------------------------------------------- Tidak ada lagi tombol palsu */

    /**
     * Halaman lamanya memanggil alert('Mempersiapkan File Excel...') di
     * delapan tombol sekaligus, dan tanggalnya diketik tangan di Blade.
     * Dikunci di sini supaya tidak ada yang kembali.
     */
    public function test_tidak_ada_tombol_palsu_maupun_tanggal_karangan(): void
    {
        $this->login();

        $index = $this->get(route('wms.reports.index'))->assertOk();
        $index->assertDontSee('Mempersiapkan File');
        $index->assertDontSee('onclick', false);
        $index->assertDontSee('2026-08-01');

        // Setiap kartu menunjuk halaman yang benar-benar ada.
        foreach (array_keys(ReportCatalog::daftar()) as $key) {
            $index->assertSee(route('wms.reports.show', $key), false);
            $this->get(route('wms.reports.show', $key))->assertOk();
        }
    }

    /* ------------------------------------------------ Isinya memang berbeda */

    public function test_penjualan_selesai_hanya_memuat_pesanan_yang_tuntas(): void
    {
        $sales = $this->login();

        $this->pesananSelesai($this->karawang, $sales);

        // Pesanan yang belum selesai TIDAK boleh ikut.
        $menggantung = SalesOrder::factory()->create([
            'customer_id' => $this->pelanggan->id,
            'user_id' => $sales->id,
            'warehouse_id' => $this->karawang->id,
            'status' => SalesOrder::STATUS_PENDING,
            'submitted_at' => now()->subDay(),
        ]);

        SalesOrderDetail::create([
            'sales_order_id' => $menggantung->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => 7,
            'qty_approved' => 7,
            'qty_shipped' => 0,
            'outstanding_qty' => 7,
        ]);

        $tabel = (new ReportRunner)->jalankan('penjualan-selesai', $this->user(), $this->periode(), 100);

        $this->assertSame(1, $tabel['total'], 'Hanya pesanan selesai yang boleh masuk.');
    }

    public function test_outstanding_menyusut_saat_barangnya_menyusul_dikirim(): void
    {
        $sales = $this->login();

        $order = $this->pesananSelesai($this->karawang, $sales, dipesan: 10, terkirim: 4);

        $runner = new ReportRunner;

        $tabel = $runner->jalankan('pesanan-outstanding', $this->user(), $this->periode(), 100);
        $this->assertSame(1, $tabel['total']);
        $this->assertSame(6, $tabel['baris'][0][12], 'Outstanding = 10 dipesan - 4 terkirim.');

        // Dibaca dari kolom yang HIDUP, bukan dari riwayat: begitu sisanya
        // dikirim, barisnya harus hilang dengan sendirinya.
        $order->details()->update(['qty_shipped' => 10, 'outstanding_qty' => 0]);

        $this->assertSame(
            0,
            $runner->jalankan('pesanan-outstanding', $this->user(), $this->periode(), 100)['total'],
            'Baris yang sudah lunas tidak boleh tersisa di laporan outstanding.',
        );
    }

    public function test_produk_terlaris_menghitung_yang_terkirim_bukan_yang_dipesan(): void
    {
        $sales = $this->login();

        $laku = Product::factory()->create(['sku' => 'LAKU-1', 'name' => 'Sering Keluar']);
        $kosong = Product::factory()->create(['sku' => 'KOSONG-1', 'name' => 'Sering Kosong']);

        // Dipesan banyak, terkirim sedikit — TIDAK boleh jadi nomor satu.
        $this->pesananSelesai($this->karawang, $sales, dipesan: 500, terkirim: 5, produk: $kosong);
        $this->pesananSelesai($this->karawang, $sales, dipesan: 50, terkirim: 50, produk: $laku);

        $tabel = (new ReportRunner)->jalankan('produk-terlaris', $this->user(), $this->periode(), 10);

        $this->assertSame('LAKU-1', $tabel['baris'][0][1], 'Peringkat diurut dari qty TERKIRIM.');
        $this->assertSame(50, $tabel['baris'][0][5]);
        $this->assertSame('KOSONG-1', $tabel['baris'][1][1]);
    }

    /* ------------------------------------------------------- Rentang tanggal */

    /**
     * Batas '<= tanggal' sebagai timestamp berarti pukul 00:00. Tanpa
     * dinaikkan ke akhir hari, seluruh isi tanggal terakhir hilang — dan
     * laporannya tetap terlihat wajar, cuma kurang sehari.
     */
    public function test_hari_terakhir_rentang_ikut_terhitung(): void
    {
        $sales = $this->login();

        $this->pesananSelesai($this->karawang, $sales, selesai: '2026-09-30 16:45:00');

        $runner = new ReportRunner;

        $tabel = $runner->jalankan('penjualan-selesai', $this->user(), [
            'dari' => '2026-09-01', 'sampai' => '2026-09-30', 'warehouse_id' => null,
        ], 100);

        $this->assertSame(1, $tabel['total'], 'Kejadian pukul 16:45 di hari terakhir wajib ikut.');

        $this->assertSame(0, $runner->jalankan('penjualan-selesai', $this->user(), [
            'dari' => '2026-09-01', 'sampai' => '2026-09-29', 'warehouse_id' => null,
        ], 100)['total']);
    }

    /** Rentang terbalik diluruskan, bukan dijawab dengan daftar kosong. */
    public function test_rentang_terbalik_diluruskan(): void
    {
        $sales = $this->login();

        $this->pesananSelesai($this->karawang, $sales, selesai: '2026-09-15 09:00:00');

        $this->get(route('wms.reports.show', ['key' => 'penjualan-selesai', 'dari' => '2026-09-30', 'sampai' => '2026-09-01']))
            ->assertOk()
            ->assertSee('PO', false)
            ->assertSee('Apko 5 Liter');
    }

    /* ----------------------------------------------------------- Batas gudang */

    public function test_manager_gudang_lain_tidak_melihat_data_karawang(): void
    {
        $salesKarawang = $this->login(Role::SALES, $this->karawang);
        $this->pesananSelesai($this->karawang, $salesKarawang);

        $manager = $this->login(Role::MANAGER, $this->pekanbaru);

        $tabel = (new ReportRunner)->jalankan('penjualan-selesai', $manager, $this->periode(), 100);

        $this->assertSame(0, $tabel['total'], 'Manager Pekanbaru tidak boleh melihat penjualan Karawang.');
    }

    /** Batasnya berlaku di BERKAS juga, bukan hanya di pratinjau layar. */
    public function test_batas_gudang_ikut_ke_dalam_berkas_unduhan(): void
    {
        $salesKarawang = $this->login(Role::SALES, $this->karawang);
        $this->pesananSelesai($this->karawang, $salesKarawang);

        $this->login(Role::MANAGER, $this->pekanbaru);

        $isi = $this->unduh('penjualan-selesai');

        $this->assertStringNotContainsString('Apko 5 Liter', $isi['teks']);
        $this->assertStringNotContainsString('Toko Melati', $isi['teks']);
    }

    /* -------------------------------------------------------- Berkas sungguhan */

    public function test_unduhan_menghasilkan_berkas_xlsx_yang_benar_benar_terbaca(): void
    {
        $sales = $this->login();

        $this->pesananSelesai($this->karawang, $sales, dipesan: 12, terkirim: 9);

        $isi = $this->unduh('penjualan-selesai');

        $this->assertStringContainsString('.xlsx', $isi['nama']);

        $ws = $isi['sheet'];

        // Judul, keterangan periode, lalu kepala tabel di baris 4.
        $this->assertSame('Finish Order', $ws->getCell('A1')->getValue());
        $this->assertStringContainsString('Periode:', (string) $ws->getCell('A2')->getValue());
        $this->assertSame('No Pesanan', $ws->getCell('A4')->getValue());

        // Angkanya harus mendarat sebagai BILANGAN. Kalau ia jadi teks, SUM()
        // di Excel mengembalikan nol tanpa keluhan apa pun.
        $this->assertSame(12, $ws->getCell('M5')->getValue());
        $this->assertSame(9, $ws->getCell('N5')->getValue());
        $this->assertSame('n', $ws->getCell('M5')->getDataType());

        $this->assertStringContainsString('Apko 5 Liter', $isi['teks']);
    }

    /**
     * Berkasnya berisi SELURUH baris, bukan 25 baris pratinjau.
     *
     * Kalau keduanya tertukar tidak akan ada yang mengeluh — berkasnya tetap
     * terbuka dan tetap masuk akal, cuma kurang datanya.
     */
    public function test_berkas_memuat_seluruh_baris_bukan_hanya_pratinjau(): void
    {
        $sales = $this->login();

        $jumlah = ReportCatalog::PRATINJAU + 7;

        for ($i = 0; $i < $jumlah; $i++) {
            $this->pesananSelesai($this->karawang, $sales);
        }

        $this->get(route('wms.reports.show', 'penjualan-selesai'))
            ->assertOk()
            ->assertSee(number_format($jumlah).' baris ditemukan');

        $ws = $this->unduh('penjualan-selesai')['sheet'];

        // Baris 1-3 keterangan, baris 4 kepala, data mulai baris 5.
        $this->assertSame($jumlah + 4, $ws->getHighestDataRow());
    }

    /* ------------------------------------------------------------- Jejak */

    public function test_unduhan_tercatat_di_log_aktivitas(): void
    {
        $super = $this->login();

        $this->pesananSelesai($this->karawang, $super);

        $this->unduh('penjualan-selesai');

        $log = ActivityLog::query()->where('action', ActivityLog::REPORT_EXPORT)->first();

        $this->assertNotNull($log, 'Data yang keluar dari sistem wajib meninggalkan jejak.');
        $this->assertSame($super->id, $log->user_id);
        $this->assertStringContainsString('Finish Order', $log->description);
        $this->assertSame('penjualan-selesai', $log->properties['laporan']);
        $this->assertFalse($log->properties['terpotong']);
    }

    /* ------------------------------------------------------- Laporan potret */

    /**
     * Laporan POTRET tidak menggambar kolom tanggal sama sekali. Menggambarnya
     * dalam keadaan mati masih mengundang orang mengisinya lalu heran kenapa
     * angkanya tidak berubah.
     */
    public function test_laporan_potret_tidak_menawarkan_rentang_tanggal(): void
    {
        $this->login();

        Location::factory()->create(['warehouse_id' => $this->karawang->id, 'code' => 'A-01-01']);

        $this->get(route('wms.reports.show', 'posisi-stok'))
            ->assertOk()
            ->assertSee('keadaan saat ini')
            ->assertDontSee('name="dari"', false)
            ->assertDontSee('name="sampai"', false);

        // Dan tanggal yang tetap dipaksakan lewat URL tidak boleh menyusup ke
        // keterangan berkasnya — "Periode 1–30 September" pada berkas yang
        // isinya keadaan hari ini adalah salah paham yang paling sulit
        // dibantah, karena keterangannya tertulis di berkasnya sendiri.
        $isi = $this->unduh('posisi-stok', ['dari' => '2026-09-01', 'sampai' => '2026-09-30']);

        $this->assertStringContainsString('Keadaan per', (string) $isi['sheet']->getCell('A2')->getValue());
        $this->assertStringNotContainsString('01/09/2026', (string) $isi['sheet']->getCell('A2')->getValue());
    }

    public function test_posisi_stok_menampilkan_sisa_yang_ada_di_rak(): void
    {
        $this->login();

        $lokasi = Location::factory()->create(['warehouse_id' => $this->karawang->id, 'code' => 'A-01-01']);

        InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'location_id' => $lokasi->id,
            'warehouse_id' => $this->karawang->id,
            'batch_no' => 'B-2026-01',
            'qty_available' => 40,
            'qty_allocated' => 10,
        ]);

        $tabel = (new ReportRunner)->jalankan('posisi-stok', $this->user(), $this->periode(), 100);

        $this->assertSame(1, $tabel['total']);
        $this->assertSame('B-2026-01', $tabel['baris'][0][5]);
        $this->assertSame(40, $tabel['baris'][0][10]);
        $this->assertSame(10, $tabel['baris'][0][11]);
        $this->assertSame(50, $tabel['baris'][0][12], 'Total = tersedia + dialokasi.');
    }

    /* ----------------------------------------------------------- Alat bantu */

    private function user(): User
    {
        return auth()->user();
    }

    /** @return array{dari: string, sampai: string, warehouse_id: null} */
    private function periode(): array
    {
        return [
            'dari' => now()->subYear()->toDateString(),
            'sampai' => now()->addYear()->toDateString(),
            'warehouse_id' => null,
        ];
    }

    /**
     * Mengunduh berkasnya lalu MEMBUKANYA KEMBALI dengan PhpSpreadsheet.
     *
     * Memeriksa status 200 saja tidak membuktikan apa pun: berkas rusak juga
     * dikirim dengan status 200, dan barulah gagal di Excel milik orang lain.
     *
     * @return array{nama: string, teks: string, sheet: Worksheet}
     */
    private function unduh(string $key, array $filter = []): array
    {
        $respons = $this->get(route('wms.reports.download', array_merge(['key' => $key], $filter)));

        $respons->assertOk();

        $mentah = $respons->streamedContent();

        $berkas = tempnam(sys_get_temp_dir(), 'lap').'.xlsx';
        file_put_contents($berkas, $mentah);

        $sheet = IOFactory::load($berkas)->getActiveSheet();

        $teks = '';

        foreach ($sheet->toArray() as $baris) {
            $teks .= implode('|', array_map(fn ($n) => (string) $n, $baris))."\n";
        }

        @unlink($berkas);

        return [
            'nama' => $respons->headers->get('content-disposition') ?? '',
            'teks' => $teks,
            'sheet' => $sheet,
        ];
    }
}
