<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerBilling;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\DeliveryProof;
use App\Models\InboundDetail;
use App\Models\InboundHeader;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\MaterialRequisition;
use App\Models\MaterialRequisitionItem;
use App\Models\PaymentTerm;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\SalesReturn;
use App\Models\StockTransfer;
use App\Models\StockTransferDetail;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * N+1 DI HALAMAN DAFTAR — pengerasan sebelum go-live (Fase 13).
 *
 * CARA MENGUKURNYA
 * ----------------
 * Setiap halaman daftar dibuka dua kali: sekali dengan data sedikit, sekali
 * lagi setelah datanya dilipatgandakan. Halaman yang sehat menjalankan
 * jumlah query yang SAMA — daftarnya dimuat dengan beberapa query berapa pun
 * barisnya. Halaman yang jumlah query-nya ikut naik memuat sesuatu satu per
 * satu di dalam perulangan: dengan 1.863 customer dan ratusan baris stok,
 * itulah halaman yang butuh puluhan detik di server.
 *
 * Model::preventLazyLoading() (AppServiceProvider) sudah menangkap RELASI
 * yang dimuat satu per satu. Test ini menangkap sisanya: accessor, method
 * model, atau pemanggilan query di Blade yang berulang per baris — dan
 * relasi yang lolos karena data uji lain hanya berisi satu baris.
 */
class PerformaHalamanTest extends TestCase
{
    use RefreshDatabase;

    /** Selisih query yang masih dianggap wajar (hitungan tambahan, cache yang baru terisi). */
    private const TOLERANSI = 3;

    private Warehouse $gudang;

    private Warehouse $tujuan;

    private PaymentTerm $tunai;

    private PaymentTerm $tempo;

    private User $sales;

    private int $urut = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->withProduction()->create(['code' => 'WH-01', 'name' => 'Karawang']);
        $this->tujuan = Warehouse::factory()->create(['code' => 'WH-02', 'name' => 'Pekanbaru']);
        $this->tunai = PaymentTerm::firstOrCreate(['code' => 'cash'], ['name' => 'Cash', 'days' => 0, 'is_active' => true, 'sort_order' => 1]);
        $this->tempo = PaymentTerm::firstOrCreate(['code' => 'net30'], ['name' => 'Tempo 30', 'days' => 30, 'is_active' => true, 'sort_order' => 2]);
        $this->sales = User::factory()->withRole(Role::SALES)->create(['warehouse_id' => $this->gudang->id]);
    }

    private function loginAs(string $slug): User
    {
        $user = User::factory()->withRole($slug)->create(['warehouse_id' => $this->gudang->id]);
        $token = Str::random(64);

        UserSession::create([
            'user_id' => $user->id, 'session_id' => $token, 'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit', 'last_activity_at' => now(), 'created_at' => now(),
        ]);

        $this->withUnencryptedCookies(['device_token' => $token]);
        $this->withCredentials();
        $this->actingAs($user);

        return $user;
    }

    public function test_jumlah_query_halaman_daftar_tidak_ikut_naik_bersama_jumlah_baris(): void
    {
        $this->tambahData(3);

        $peran = [Role::SUPER_ADMIN, Role::LOGISTICS, Role::WAREHOUSE_OPERATOR, Role::PRODUCTION, Role::SALES];
        $pengguna = [];
        $sebelum = [];

        foreach ($peran as $slug) {
            $pengguna[$slug] = $this->loginAs($slug);
            $sebelum[$slug] = $this->ukurSemuaHalaman();
        }

        $this->tambahData(6);

        $membengkak = [];

        foreach ($peran as $slug) {
            $this->actingAs($pengguna[$slug]);
            $token = UserSession::where('user_id', $pengguna[$slug]->id)->value('session_id');
            $this->withUnencryptedCookies(['device_token' => $token]);

            foreach ($this->ukurSemuaHalaman() as $uri => $jumlah) {
                $awal = $sebelum[$slug][$uri] ?? null;

                if ($awal !== null && $jumlah > $awal + self::TOLERANSI) {
                    $membengkak[] = "{$slug} {$uri}: {$awal} -> {$jumlah} query";
                }
            }
        }

        $this->assertSame([], $membengkak, "Jumlah query ikut naik bersama jumlah baris (N+1):\n".implode("\n", $membengkak));
    }

    /**
     * Jumlah query tiap halaman daftar (rute GET tanpa parameter) yang bisa
     * dibuka role yang sedang login.
     *
     * @return array<string, int>
     */
    private function ukurSemuaHalaman(): array
    {
        $hasil = [];

        foreach (Route::getRoutes()->getRoutes() as $rute) {
            if (! $this->layakDiukur($rute)) {
                continue;
            }

            $uri = '/'.ltrim($rute->uri(), '/');
            $jumlah = 0;

            DB::flushQueryLog();
            DB::enableQueryLog();
            $respons = $this->get($uri);
            $jumlah = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertLessThan(500, $respons->getStatusCode(), "{$uri} meledak saat datanya bertambah.");

            if ($respons->getStatusCode() === 200) {
                $hasil[$uri] = $jumlah;
            }
        }

        return $hasil;
    }

    private function layakDiukur(RoutingRoute $rute): bool
    {
        $uri = $rute->uri();

        return in_array('GET', $rute->methods(), true)
            && ! str_contains($uri, '{')
            && ! str_starts_with($uri, '_')
            && ! str_contains($uri, 'lookup')
            && ! in_array($uri, ['/', 'up', 'health', 'login', 'logout'], true);
    }

    /* ------------------------------------------------------------ Data */

    /** Menambah $n baris untuk setiap daftar utama. */
    private function tambahData(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $k = ++$this->urut;

            $rak = Location::factory()->create([
                'warehouse_id' => $this->gudang->id,
                'code' => sprintf('C-%02d-01', $k),
                'rack' => 'C', 'level' => $k, 'cell' => 1,
                'is_active' => true,
            ]);
            $produk = Product::factory()->create(['sku' => sprintf('PERF-%03d', $k), 'uom' => 'TIN', 'is_active' => true]);
            $customer = Customer::factory()->create(['is_active' => true]);

            InventoryStock::factory()->create([
                'product_id' => $produk->id, 'warehouse_id' => $this->gudang->id, 'location_id' => $rak->id,
                'batch_no' => 'BT-'.$k, 'qty_available' => 100, 'status' => InventoryStock::STATUS_ACTIVE,
                'expiry_date' => now()->addYears(2)->toDateString(),
            ]);

            $pesanan = fn (array $atribut) => tap(SalesOrder::factory()->create(array_merge([
                'user_id' => $this->sales->id,
                'customer_id' => $customer->id,
                'warehouse_id' => $this->gudang->id,
                'payment_term_id' => $this->tunai->id,
                'status' => SalesOrder::STATUS_PENDING,
                'submitted_at' => now()->subDays(2),
            ], $atribut)), fn (SalesOrder $o) => SalesOrderDetail::factory()->create([
                'sales_order_id' => $o->id, 'product_id' => $produk->id, 'qty_ordered' => 10, 'qty_approved' => 10,
            ]));

            $pesanan([]);

            $diterima = $pesanan([
                'status' => SalesOrder::STATUS_APPROVED, 'bc_so_number' => 'SO-P'.$k, 'approved_at' => now()->subDay(),
            ]);

            $dikirim = $pesanan([
                'status' => SalesOrder::STATUS_PROOF_UPLOADED, 'bc_so_number' => 'SO-K'.$k,
                'approved_at' => now()->subDays(2), 'shipped_at' => now()->subDay(), 'delivered_at' => now()->subHours(3),
            ]);

            $note = DeliveryNote::factory()->create([
                'bc_so_number' => $dikirim->bc_so_number, 'sales_order_id' => $dikirim->id,
                'customer_id' => $customer->id, 'warehouse_id' => $this->gudang->id,
                'status' => DeliveryNote::STATUS_SHIPPED, 'driver_name' => 'Supir '.$k,
                'driver_phone' => '6281234567890', 'vehicle_plate' => 'B '.$k.' XYZ',
                'shipped_at' => now()->subDay(), 'epod_token' => Str::random(48),
            ]);
            DeliveryNoteLine::factory()->create(['delivery_note_id' => $note->id, 'sku' => $produk->sku, 'product_id' => $produk->id]);
            DeliveryProof::factory()->create(['sales_order_id' => $dikirim->id, 'delivery_note_id' => $note->id, 'uploaded_by' => $this->sales->id]);

            $tempo = $pesanan([
                'status' => SalesOrder::STATUS_COMPLETED_BILLING, 'payment_term_id' => $this->tempo->id,
                'bc_so_number' => 'SO-T'.$k, 'approved_at' => now()->subDays(40), 'completed_at' => now()->subDays(30),
            ]);
            CustomerBilling::create([
                'sales_order_id' => $tempo->id, 'customer_id' => $customer->id, 'warehouse_id' => $this->gudang->id,
                'payment_term_id' => $this->tempo->id, 'term_days' => 30,
                'delivered_on' => now()->subDays(35)->toDateString(), 'due_date' => now()->addDays($k - 3)->toDateString(),
            ]);

            $header = InboundHeader::factory()->create([
                'warehouse_id' => $this->gudang->id,
                'document_number' => sprintf('IN-260901-%03d', $k),
                'status' => InboundHeader::STATUS_VERIFICATION_PENDING,
                'created_by' => $this->sales->id,
            ]);
            foreach ([1, 2] as $palet) {
                InboundDetail::factory()->create([
                    'inbound_header_id' => $header->id, 'product_id' => $produk->id,
                    'location_id' => $rak->id, 'pallet_no' => $palet,
                ]);
            }

            $daftar = PickingList::factory()->create(['warehouse_id' => $this->gudang->id, 'list_number' => sprintf('PL2609%05d', $k)]);
            PickingListItem::factory()->create([
                'picking_list_id' => $daftar->id, 'sales_order_id' => $diterima->id,
                'sales_order_detail_id' => $diterima->details()->first()->id,
                'product_id' => $produk->id, 'location_id' => $rak->id,
            ]);

            $transfer = StockTransfer::factory()->create([
                'from_warehouse_id' => $this->gudang->id, 'to_warehouse_id' => $this->tujuan->id,
                'transfer_number' => sprintf('TF2609%05d', $k),
            ]);
            StockTransferDetail::factory()->create(['stock_transfer_id' => $transfer->id, 'product_id' => $produk->id]);

            $mrf = MaterialRequisition::factory()->menungguLogistik()->create([
                'warehouse_id' => $this->gudang->id, 'requested_by' => $this->sales->id,
                'mrf_number' => sprintf('MR2609%05d', $k),
            ]);
            MaterialRequisitionItem::factory()->create(['material_requisition_id' => $mrf->id, 'product_id' => $produk->id, 'qty_requested' => 5]);

            SalesReturn::create([
                'reference' => sprintf('RJ2609%05d', $k), 'sales_order_id' => $dikirim->id,
                'customer_id' => $customer->id, 'warehouse_id' => $this->gudang->id,
                'status' => SalesReturn::STATUS_REPORTED, 'reason' => 'Uji volume halaman.', 'reported_at' => now(),
            ]);

            ActivityLog::create([
                'user_id' => $this->sales->id, 'user_name' => $this->sales->full_name,
                'action' => ActivityLog::STOCK_ADJUST, 'description' => 'Uji volume '.$k,
                'warehouse_id' => $this->gudang->id, 'created_at' => now(),
            ]);
        }
    }
}
