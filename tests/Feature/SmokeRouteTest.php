<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\DeliveryProof;
use App\Models\InboundDetail;
use App\Models\InboundHeader;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\MaterialRequisition;
use App\Models\MaterialRequisitionAllocation;
use App\Models\MaterialRequisitionItem;
use App\Models\Notification;
use App\Models\PaymentTerm;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\Product;
use App\Models\ProductionMaterialConsumption;
use App\Models\ProductionMaterialHolding;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\SalesReturn;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\StockTransfer;
use App\Models\StockTransferDetail;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Reporting\ReportCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Membuka SETIAP halaman sebagai SETIAP role, memastikan tidak ada yang 5xx.
 *
 * MENGAPA TEST INI ADA
 * --------------------
 * Test per modul memeriksa aturan bisnis, dan itu bisa hijau seluruhnya
 * sementara sebuah halaman mati begitu dibuka di browser — variabel yang
 * tidak dikirim controller, view yang namanya salah ketik, kolom yang sudah
 * di-rename, relasi yang di-eager-load dengan nama kolom yang tidak ada.
 * Semua itu baru ketahuan saat pemilik produk membukanya sendiri, dan itu
 * sudah beberapa kali terjadi di proyek ini.
 *
 * Test ini menutup celah tersebut secara menyeluruh: rutenya diambil dari
 * router yang sebenarnya, bukan dari daftar yang ditulis tangan, sehingga
 * halaman BARU otomatis ikut terjaga tanpa ada yang perlu ingat.
 *
 * YANG DIPERIKSA hanya "tidak meledak" (bukan 5xx). 200/302/403/404 semuanya
 * sah — hak akses per role sudah diuji di test modulnya masing-masing.
 *
 * KALAU TEST INI GAGAL DENGAN "parameter tidak dikenal": rute baru dengan
 * parameter baru sudah ditambahkan. Daftarkan contoh nilainya di
 * contohParameter(). Sengaja digagalkan, bukan dilewati diam-diam —
 * rute yang dilewati adalah rute yang tidak terjaga.
 */
class SmokeRouteTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    /** Nilai contoh untuk tiap nama parameter rute. */
    private array $parameter = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->siapkanData();
    }

    /**
     * @return array<int, array{0:string}>
     */
    public static function daftarRole(): array
    {
        return [
            'super admin' => [Role::SUPER_ADMIN],
            'manager' => [Role::MANAGER],
            'logistik' => [Role::LOGISTICS],
            'produksi' => [Role::PRODUCTION],
            'operator gudang' => [Role::WAREHOUSE_OPERATOR],
            'sales' => [Role::SALES],
        ];
    }

    #[DataProvider('daftarRole')]
    public function test_tidak_ada_halaman_yang_meledak(string $slug): void
    {
        $this->loginAs($slug);

        $gagal = [];

        foreach ($this->ruteGet() as $uri) {
            $status = $this->get($uri)->getStatusCode();

            if ($status >= 500) {
                $gagal[] = "{$uri} -> {$status}";
            }
        }

        $this->assertSame([], $gagal, "Halaman berikut meledak untuk role {$slug}:\n".implode("\n", $gagal));
    }

    /** Tamu yang belum login pun tidak boleh membuat halaman meledak. */
    public function test_tamu_tidak_membuat_halaman_meledak(): void
    {
        $gagal = [];

        foreach ($this->ruteGet() as $uri) {
            $status = $this->get($uri)->getStatusCode();

            if ($status >= 500) {
                $gagal[] = "{$uri} -> {$status}";
            }
        }

        $this->assertSame([], $gagal, "Halaman berikut meledak untuk tamu:\n".implode("\n", $gagal));
    }

    /* ------------------------------------------------------------ Rute */

    /**
     * Seluruh rute GET aplikasi, parameternya sudah diisi contoh nyata.
     *
     * @return array<int, string>
     */
    private function ruteGet(): array
    {
        $hasil = [];

        foreach (Route::getRoutes() as $rute) {
            if (! in_array('GET', $rute->methods(), true)) {
                continue;
            }

            if ($this->dilewati($rute)) {
                continue;
            }

            $hasil[] = '/'.ltrim($this->isiParameter($rute), '/');
        }

        sort($hasil);

        return $hasil;
    }

    /**
     * Rute bawaan framework dan rute non-HTML yang tidak relevan diuji di sini.
     */
    private function dilewati(RoutingRoute $rute): bool
    {
        $uri = $rute->uri();

        return str_starts_with($uri, '_')
            || str_starts_with($uri, 'storage/')
            || $uri === 'up';
    }

    private function isiParameter(RoutingRoute $rute): string
    {
        $uri = $rute->uri();

        preg_match_all('/\{(\w+)\??\}/', $uri, $cocok);

        foreach ($cocok[1] as $nama) {
            $this->assertArrayHasKey(
                $nama,
                $this->parameter,
                "Rute {$uri} memakai parameter '{$nama}' yang belum punya contoh nilai. ".
                'Daftarkan di SmokeRouteTest::siapkanData() — rute yang dilewati adalah rute yang tidak terjaga.'
            );

            $uri = preg_replace('/\{'.$nama.'\??\}/', (string) $this->parameter[$nama], $uri);
        }

        return $uri;
    }

    /* ------------------------------------------------------------ Data */

    /**
     * Data seadanya tapi NYATA untuk tiap modul.
     *
     * Halaman yang kosong melompong jarang meledak; yang meledak justru
     * halaman yang sedang merender baris, relasi, dan accessor-nya. Karena
     * itu tiap modul diberi minimal satu baris data lengkap dengan relasinya.
     */
    private function siapkanData(): void
    {
        $this->warehouse = Warehouse::factory()->create(['code' => 'WH-01', 'name' => 'Karawang']);

        $lokasi = Location::factory()->create(['warehouse_id' => $this->warehouse->id, 'code' => 'B-01-01']);
        $produk = Product::factory()->create(['sku' => 'SMOKE-001', 'uom' => 'TIN', 'is_active' => true]);
        $customer = Customer::factory()->create(['is_active' => true]);
        $term = PaymentTerm::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Cash / Tunai', 'days' => 0, 'is_active' => true, 'sort_order' => 1]
        );

        // --- Inbound: satu dokumen dengan palet yang sudah ditempatkan ---
        $header = InboundHeader::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'document_number' => 'IN-260901-001',
            'status' => InboundHeader::STATUS_VERIFICATION_PENDING,
        ]);
        InboundDetail::factory()->create([
            'inbound_header_id' => $header->id,
            'product_id' => $produk->id,
            'location_id' => $lokasi->id,
        ]);

        // --- Stok aktif, supaya halaman Inventory merender baris sungguhan ---
        InventoryStock::factory()->create([
            'product_id' => $produk->id,
            'warehouse_id' => $this->warehouse->id,
            'location_id' => $lokasi->id,
            'status' => InventoryStock::STATUS_ACTIVE,
            'expiry_date' => now()->addYears(2)->toDateString(),
        ]);

        // --- Pesanan milik SALES, supaya halaman detail bisa dibuka ---
        $sales = User::factory()->withRole(Role::SALES)->create([
            'warehouse_id' => $this->warehouse->id,
        ]);
        $order = SalesOrder::factory()->submitted()->create([
            'user_id' => $sales->id,
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'payment_term_id' => $term->id,
        ]);
        SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $produk->id,
        ]);

        // --- Transfer antar gudang, masih di perjalanan ---
        // Gudang tujuan dibuat terpisah: CHECK constraint menolak transfer
        // yang asal dan tujuannya sama, dan smoke test harus memakai data
        // yang memang bisa hidup di database.
        $tujuan = Warehouse::factory()->create(['code' => 'WH-02', 'name' => 'Pekanbaru']);

        $transfer = StockTransfer::factory()->create([
            'from_warehouse_id' => $this->warehouse->id,
            'to_warehouse_id' => $tujuan->id,
            'transfer_number' => 'TF260901001',
        ]);
        StockTransferDetail::factory()->create([
            'stock_transfer_id' => $transfer->id,
            'product_id' => $produk->id,
        ]);

        // --- Daftar picking dengan satu baris pengambilan ---
        $daftarPicking = PickingList::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'list_number' => 'PL260901001',
        ]);
        PickingListItem::factory()->create([
            'picking_list_id' => $daftarPicking->id,
            'sales_order_id' => $order->id,
            'sales_order_detail_id' => $order->details()->first()->id,
            'product_id' => $produk->id,
            'location_id' => $lokasi->id,
        ]);

        // --- Surat Jalan dari BC, sudah berangkat ---
        // Statusnya SHIPPED, bukan imported: halaman e-POD sengaja menjawab
        // 404 untuk dokumen yang belum berangkat, dan smoke test harus
        // memakai data yang memang bisa dibuka.
        $suratJalan = DeliveryNote::factory()->create([
            'document_no' => '206215',
            'bc_so_number' => 'SO260901',
            'sales_order_id' => $order->id,
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => DeliveryNote::STATUS_SHIPPED,
            'driver_name' => 'Budi',
            'driver_phone' => '6281234567890',
            'vehicle_plate' => 'B 1234 XYZ',
            'shipped_at' => now(),
            'epod_token' => Str::random(48),
        ]);
        DeliveryNoteLine::factory()->create([
            'delivery_note_id' => $suratJalan->id,
            'sku' => $produk->sku,
            'product_id' => $produk->id,
        ]);

        // --- Bukti Surat Jalan bertanda tangan (Fase 6 tahap 5) ---
        $bukti = DeliveryProof::factory()->create([
            'sales_order_id' => $order->id,
            'delivery_note_id' => $suratJalan->id,
            'uploaded_by' => $order->user_id,
        ]);

        // --- Sesi stocktake yang sedang dihitung, dengan satu barisnya ---
        $stocktake = StockTake::create([
            'reference' => 'ST260901001',
            'warehouse_id' => $this->warehouse->id,
            'scope_type' => StockTake::SCOPE_WAREHOUSE,
            'scope_value' => null,
            'status' => StockTake::STATUS_COUNTING,
            'opened_at' => now(),
        ]);
        StockTakeItem::create([
            'stock_take_id' => $stocktake->id,
            'location_id' => $lokasi->id,
            'product_id' => $produk->id,
            'batch_no' => 'BT-SMOKE',
            'qty_system' => 10,
        ]);

        $retur = SalesReturn::create([
            'reference' => 'RJ260901001',
            'sales_order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'warehouse_id' => $this->warehouse->id,
            'status' => SalesReturn::STATUS_REPORTED,
            'reason' => 'Contoh penolakan untuk uji asap.',
            'reported_at' => now(),
        ]);

        /*
         * Notifikasi contoh TANPA user_id yang dipakai siapa pun di uji ini.
         * Rute membukanya menjawab 404 untuk orang lain — dan 404 memang
         * jawaban yang benar, bukan halaman yang meledak. Uji asap ini
         * memeriksa tidak ada 500, jadi itu sudah cukup.
         */
        $notifikasi = Notification::create([
            'user_id' => User::factory()->withRole(Role::LOGISTICS)->create([
                'warehouse_id' => $this->warehouse->id,
            ])->id,
            'type' => Notification::ORDER_PENDING,
            'title' => 'Contoh notifikasi uji asap',
            'body' => 'Baris ini hanya dipakai untuk mengisi parameter rute.',
            'url' => '/wms/dashboard/admin',
            'warehouse_id' => $this->warehouse->id,
            'created_at' => now(),
        ]);

        /*
         * MRF — DUA baris, dan keduanya memang perlu.
         *
         * Yang pertama berhenti di "menunggu Logistik": hanya dalam keadaan
         * itulah layar pemilihan batch mau merender dirinya, dan layar itulah
         * yang paling banyak mengulang baris stok berikut accessor-nya.
         *
         * Yang kedua sudah DITERIMA dan punya baris di buku Produksi. Ia tidak
         * pernah ditembak lewat parameter {mrf}, tetapi halaman "Material di
         * Tangan Produksi" merender barisnya — beserta riwayat pemakaian dan
         * hitungan umurnya, yang semuanya accessor.
         */
        $mrfMenunggu = MaterialRequisition::factory()->menungguLogistik()->create([
            'warehouse_id' => $this->warehouse->id,
            'requested_by' => $sales->id,
            'mrf_number' => 'MR260901001',
        ]);
        MaterialRequisitionItem::factory()->create([
            'material_requisition_id' => $mrfMenunggu->id,
            'product_id' => $produk->id,
            'qty_requested' => 50,
        ]);

        $mrfDiterima = MaterialRequisition::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'requested_by' => $sales->id,
            'mrf_number' => 'MR260901002',
            'status' => MaterialRequisition::STATUS_RECEIVED,
            'approved_at' => now(),
            'received_at' => now(),
        ]);
        $itemDiterima = MaterialRequisitionItem::factory()->create([
            'material_requisition_id' => $mrfDiterima->id,
            'product_id' => $produk->id,
            'qty_requested' => 300,
        ]);
        $alokasi = MaterialRequisitionAllocation::create([
            'material_requisition_id' => $mrfDiterima->id,
            'material_requisition_item_id' => $itemDiterima->id,
            'product_id' => $produk->id,
            'batch_no' => 'BT-SMOKE',
            'production_date' => now()->subMonths(2)->toDateString(),
            'expiry_date' => now()->addYears(2)->toDateString(),
            'status' => InventoryStock::STATUS_ACTIVE,
            'qty_allocated' => 300,
            'qty_picked' => 300,
            'qty_received' => 300,
        ]);
        $holding = ProductionMaterialHolding::create([
            'material_requisition_id' => $mrfDiterima->id,
            'material_requisition_allocation_id' => $alokasi->id,
            'product_id' => $produk->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_no' => 'BT-SMOKE',
            'production_date' => now()->subMonths(2)->toDateString(),
            'expiry_date' => now()->addYears(2)->toDateString(),
            'production_area' => 'I-01-01',
            'qty_received' => 300,
            'qty_consumed' => 150,
            'received_at' => now()->subDays(40),
        ]);
        ProductionMaterialConsumption::create([
            'production_material_holding_id' => $holding->id,
            'qty' => 150,
            'note' => 'Batch pertama reproses.',
            'consumed_at' => now()->subDays(10),
        ]);

        $this->parameter = [
            'mrf' => $mrfMenunggu->id,
            'proof' => $bukti->id,
            'retur' => $retur->id,
            'order' => $order->id,
            'stocktake' => $stocktake->id,
            'location' => $lokasi->id,
            'doc_no' => $header->document_number,
            'po_number' => $order->order_number,
            'transfer' => $transfer->id,
            'list' => $daftarPicking->id,
            'note' => $suratJalan->id,
            'token' => $suratJalan->epod_token,
            'notification' => $notifikasi->id,
            // Kunci laporan, bukan id. Diambil dari katalognya sendiri supaya
            // laporan yang suatu hari dihapus tidak meninggalkan contoh mati
            // yang membuat smoke test menembak URL yang sudah tidak ada.
            'key' => array_key_first(ReportCatalog::daftar()),
        ];
    }

    private function loginAs(string $slug): User
    {
        $user = User::factory()->withRole($slug)->create(['warehouse_id' => $this->warehouse->id]);
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
        $this->withCredentials();
        $this->actingAs($user);

        return $user;
    }
}
