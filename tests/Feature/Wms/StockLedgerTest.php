<?php

namespace Tests\Feature\Wms;

use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Kartu stok — buku besar mutasi barang.
 *
 * YANG DIUJI DI SINI ADALAH HAL-HAL YANG DIAM SAAT RUSAK
 * ------------------------------------------------------
 * 1. NOMOR DOKUMEN PENYEBABNYA IKUT TERBACA. Ledger hanya menyimpan
 *    (reference_type, reference_id); kalau penyambungannya putus, kolom nomor
 *    diam-diam menjadi "—" di setiap baris dan layarnya tetap terlihat wajar.
 * 2. SATU QUERY PER JENIS, bukan satu per baris. Tanpa penjagaan ini
 *    halamannya tetap benar, hanya pelan — dan pelan tidak pernah dilaporkan
 *    siapa pun sampai tabelnya membesar.
 * 3. GATE-NYA SENDIRI. Melihat sisa stok (INVENTORY_VIEW) dan menelusuri
 *    seluruh mutasinya adalah dua kewenangan yang berbeda.
 * 4. BATAS GUDANG. Mutasi gudang lain bukan urusan yang dijepit ke satu gudang.
 */
class StockLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $karawang;

    private Product $produk;

    private Location $rak;

    protected function setUp(): void
    {
        parent::setUp();

        $this->karawang = Warehouse::factory()->create(['code' => 'ID11_KARAWANG']);
        $this->produk = Product::factory()->create(['sku' => 'APKO-001', 'name' => 'Apko Putih', 'uom' => 'PAIL']);
        $this->rak = Location::factory()->create([
            'warehouse_id' => $this->karawang->id,
            'code' => 'A-01-01',
        ]);
    }

    private function login(string $slug, ?Warehouse $gudang = null): User
    {
        $user = User::factory()->withRole($slug)->create([
            'warehouse_id' => ($gudang ?? $this->karawang)->id,
        ]);

        $token = Str::random(64);

        UserSession::create([
            'user_id' => $user->id, 'session_id' => $token, 'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit', 'last_activity_at' => now(), 'created_at' => now(),
        ]);

        $this->withUnencryptedCookies(['device_token' => $token]);
        $this->actingAs($user);

        return $user;
    }

    private function mutasi(array $ganti = []): StockMovement
    {
        return StockMovement::create(array_merge([
            'product_id' => $this->produk->id,
            'location_id' => $this->rak->id,
            'warehouse_id' => $this->karawang->id,
            'movement_type' => StockMovement::TYPE_OUT,
            'qty_change' => -300,
            'qty_before' => 1000,
            'qty_after' => 700,
            'reference_type' => StockMovement::REF_ADJUSTMENT,
            'reference_id' => 1,
            'batch_no' => 'BT-2601',
            'created_at' => now(),
        ], $ganti));
    }

    /* ----------------------------------------------------------- Bacaannya */

    /** Angka sebelum dan sesudah ikut terbaca, bukan cuma selisihnya. */
    public function test_kartu_stok_menampilkan_perpindahan_angkanya(): void
    {
        $this->mutasi();
        $this->login(Role::LOGISTICS);

        $this->get(route('wms.inventory.kartu-stok'))
            ->assertOk()
            ->assertSee('APKO-001')
            ->assertSee('BT-2601')
            ->assertSee('1,000')
            ->assertSee('700')
            ->assertViewHas('stats', fn (array $s) => $s['baris'] === 1 && $s['keluar'] === -300);
    }

    /**
     * Nomor dokumen penyebabnya terbaca, berikut untuk siapa barangnya pergi.
     *
     * "Untuk siapa" adalah pertanyaan yang selalu menyusul begitu satu baris
     * mencurigakan ditemukan, dan nomor dokumen saja belum menjawabnya.
     */
    public function test_nomor_dan_lawan_transaksinya_terbaca(): void
    {
        $pelanggan = Customer::factory()->create(['name' => 'PT Aneka Warna']);
        $pesanan = SalesOrder::factory()->create([
            'warehouse_id' => $this->karawang->id,
            'customer_id' => $pelanggan->id,
            'order_number' => 'PO260901003',
        ]);

        $this->mutasi([
            'reference_type' => StockMovement::REF_SALES_ORDER,
            'reference_id' => $pesanan->id,
        ]);

        $this->login(Role::LOGISTICS);

        $this->get(route('wms.inventory.kartu-stok'))
            ->assertOk()
            ->assertSee('PO260901003')
            ->assertSee('PT Aneka Warna');
    }

    /** Transfer menyebut gudang tujuannya, bukan pelanggan yang tidak ada. */
    public function test_transfer_menyebut_gudang_tujuannya(): void
    {
        $tujuan = Warehouse::factory()->create(['code' => 'ID1B_SURABAYA']);
        $transfer = StockTransfer::factory()->create([
            'from_warehouse_id' => $this->karawang->id,
            'to_warehouse_id' => $tujuan->id,
            'transfer_number' => 'TF260901001',
        ]);

        $this->mutasi([
            'movement_type' => StockMovement::TYPE_TRANSFER_OUT,
            'reference_type' => StockMovement::REF_STOCK_TRANSFER,
            'reference_id' => $transfer->id,
        ]);

        $this->login(Role::LOGISTICS);

        $this->get(route('wms.inventory.kartu-stok'))
            ->assertOk()
            ->assertSee('TF260901001')
            ->assertSee('ID1B');
    }

    /** Berangkat dari nomor dokumen — jalan masuk yang paling sering dipakai. */
    public function test_pencarian_menemukan_lewat_nomor_dokumen(): void
    {
        $pesanan = SalesOrder::factory()->create([
            'warehouse_id' => $this->karawang->id,
            'order_number' => 'PO260901003',
        ]);

        $this->mutasi([
            'reference_type' => StockMovement::REF_SALES_ORDER,
            'reference_id' => $pesanan->id,
            'batch_no' => 'BT-CARI',
        ]);
        $this->mutasi(['batch_no' => 'BT-LAIN']);

        $this->login(Role::LOGISTICS);

        $this->get(route('wms.inventory.kartu-stok', ['search' => 'PO260901003']))
            ->assertOk()
            ->assertSee('BT-CARI')
            ->assertDontSee('BT-LAIN');
    }

    public function test_bisa_disaring_per_jenis_mutasi_dan_tanggal(): void
    {
        $this->mutasi(['batch_no' => 'BT-KELUAR']);
        $this->mutasi([
            'movement_type' => StockMovement::TYPE_IN,
            'qty_change' => 500,
            'qty_before' => 700,
            'qty_after' => 1200,
            'batch_no' => 'BT-MASUK',
        ]);

        $this->login(Role::LOGISTICS);

        $this->get(route('wms.inventory.kartu-stok', ['tipe' => StockMovement::TYPE_IN]))
            ->assertOk()
            ->assertSee('BT-MASUK')
            ->assertDontSee('BT-KELUAR');

        // Jenis karangan diabaikan, bukan menjatuhkan halaman.
        $this->get(route('wms.inventory.kartu-stok', ['tipe' => 'TIDAK-ADA']))
            ->assertOk()
            ->assertViewHas('filters', fn (array $f) => $f['tipe'] === null);

        // Tanggal mustahil juga diabaikan.
        foreach (['2026-13-45', 'abc', "' OR 1=1 --"] as $salah) {
            $this->get(route('wms.inventory.kartu-stok', ['dari' => $salah, 'sampai' => $salah]))
                ->assertOk()
                ->assertViewHas('filters', fn (array $f) => $f['dari'] === null && $f['sampai'] === null);
        }
    }

    /* -------------------------------------------------------------- Batas */

    /** Menelusuri seluruh mutasi bukan kewenangan yang sama dengan melihat sisa. */
    public function test_tertutup_untuk_yang_hanya_boleh_melihat_stok(): void
    {
        foreach ([Role::PRODUCTION, Role::WAREHOUSE_OPERATOR] as $peran) {
            $this->login($peran);

            $this->get(route('wms.inventory.kartu-stok'))->assertForbidden();
        }
    }

    public function test_mutasi_gudang_lain_tidak_terbaca(): void
    {
        $this->mutasi(['batch_no' => 'BT-KARAWANG']);

        $lain = Warehouse::factory()->create(['code' => 'ID1B_SURABAYA']);
        $this->login(Role::LOGISTICS, $lain);

        $this->get(route('wms.inventory.kartu-stok'))
            ->assertOk()
            ->assertDontSee('BT-KARAWANG')
            ->assertViewHas('stats', fn (array $s) => $s['baris'] === 0);
    }

    /**
     * Nomor dokumen dibaca sekali per JENIS, bukan sekali per baris.
     *
     * Kalau penyambungannya jatuh ke per baris, halamannya tetap benar dan
     * tidak ada yang melaporkannya — sampai tabelnya cukup besar untuk membuat
     * layar ini berhenti bisa dibuka.
     */
    public function test_nomor_dokumen_tidak_dibaca_sebaris_sekali(): void
    {
        $pesanan = SalesOrder::factory()->count(3)->create(['warehouse_id' => $this->karawang->id]);

        foreach ($pesanan as $satu) {
            foreach (range(1, 4) as $ke) {
                $this->mutasi([
                    'reference_type' => StockMovement::REF_SALES_ORDER,
                    'reference_id' => $satu->id,
                    'batch_no' => 'BT-'.$satu->id.'-'.$ke,
                ]);
            }
        }

        $this->login(Role::LOGISTICS);

        $jumlah = 0;
        \DB::listen(function () use (&$jumlah) {
            $jumlah++;
        });

        $this->get(route('wms.inventory.kartu-stok'))->assertOk();

        // 12 baris dari 3 pesanan. Batas longgar sengaja: yang dijaga bukan
        // angka pastinya melainkan bahwa jumlahnya tidak tumbuh mengikuti
        // jumlah baris.
        $this->assertLessThan(
            30,
            $jumlah,
            "Query terlalu banyak ({$jumlah}) — nomor dokumen sepertinya dibaca per baris.",
        );
    }
}
