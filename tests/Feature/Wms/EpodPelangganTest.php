<?php

namespace Tests\Feature\Wms;

use App\Jobs\SendDeliveryNotification;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\PaymentTerm;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Messaging\PesanWhatsApp;
use App\Support\Outbound\FifoAllocator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Konfirmasi sampai oleh pelanggan, untuk kiriman yang supirnya berganti.
 *
 * EMPAT HAL YANG KALAU SALAH BARU KETAHUAN DUA MINGGU KEMUDIAN
 * ------------------------------------------------------------
 * 1. TAUTANNYA TIDAK BOLEH TERBIT SAAT BARANG BERANGKAT. Masa berlakunya 72
 *    jam; kalau terbit hari itu, ia sudah mati jauh sebelum kapal sandar dan
 *    tidak ada seorang pun yang menyadarinya sampai pelanggan mengeluh.
 * 2. TAUTANNYA TIDAK BOLEH KE SUPIR. Supir pertama menurunkan barang di
 *    pelabuhan; tautan yang mendarat padanya berarti konfirmasi "barang
 *    sampai" ditekan orang yang tidak pernah melihat tokonya.
 * 3. YANG TANGGALNYA SUDAH LEWAT HARUS TETAP TERJARING. Kalau perintahnya
 *    hanya melihat "hari ini", server yang mati sehari membuat kirimannya
 *    terlewat selamanya — dan tidak ada apa pun yang berteriak.
 * 4. KIRIMAN YANG SUDAH BERANGKAT TIDAK BOLEH KEHILANGAN TUJUAN TAUTANNYA.
 *    Tanpa nomor atau tanpa tanggal, penjadwal tidak punya yang dikerjakan
 *    dan kirimannya diam di "berangkat" tanpa batas.
 */
class EpodPelangganTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $karawang;

    private Location $rak;

    private Product $produk;

    private Customer $customer;

    private PaymentTerm $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->karawang = Warehouse::factory()->withProduction()->create(['code' => 'WH-01', 'name' => 'Karawang']);

        $this->rak = Location::factory()->create([
            'warehouse_id' => $this->karawang->id, 'code' => 'A-01-01', 'is_active' => true,
        ]);

        $this->produk = Product::factory()->create([
            'sku' => 'ID1-F0017X002820', 'name' => 'Bocor Guard 2 Base 20Kg', 'uom' => 'PAIL', 'is_active' => true,
        ]);

        // Nomor master SENGAJA berbeda dari nomor yang dikirim formulir:
        // kalau keduanya sama, test tidak bisa membedakan "terisi dari
        // master" dari "diketik Logistik" — dan justru kemampuan menimpanya
        // yang menjadi alasan kolomnya ada.
        $this->customer = Customer::factory()->create([
            'code' => 'IDR13302',
            'name' => 'Toko Medan Jaya',
            'phone' => '6281100000000',
            'territory_code' => 'SUMATERA 1',
            'is_active' => true,
        ]);

        $this->term = PaymentTerm::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Cash / Tunai', 'days' => 0, 'is_active' => true, 'sort_order' => 1]
        );
    }

    /* ------------------------------------------------------- Saat berangkat */

    public function test_kiriman_luar_pulau_berangkat_tanpa_menerbitkan_tautan(): void
    {
        Queue::fake();

        $note = $this->siapKirim();

        $this->kirim($note, [
            'epod_to_customer' => '1',
            'vehicle_plate' => null,
            'container_no' => 'ABCU1234567',
            'forwarder_name' => 'Meratus',
            'customer_phone' => '082199998888',
            'eta_date' => now()->addDays(14)->toDateString(),
        ])->assertRedirect();

        $note->refresh();

        $this->assertSame(DeliveryNote::STATUS_SHIPPED, $note->status);
        $this->assertTrue($note->epod_to_customer);

        // INI POKOKNYA. Token yang terbit hari ini sudah mati saat kapalnya
        // sandar.
        $this->assertNull($note->epod_token);
        $this->assertNull($note->epod_expires_at);

        $this->assertSame('6282199998888', $note->customer_phone);
        $this->assertSame('ABCU1234567', $note->container_no);
        $this->assertSame(now()->addDays(14)->toDateString(), $note->eta_date->toDateString());

        // Supir pertama tetap tercatat: barangnya nyata diangkut keluar dari
        // gudang ini, dan dua minggu lagi tidak ada sumber lain yang bisa
        // menjawab siapa yang mengambilnya.
        $this->assertSame('Budi Santoso', $note->driver_name);
        $this->assertSame('6281234567890', $note->driver_phone);

        // ...tetapi TIDAK dikirimi tautan apa pun.
        Queue::assertNotPushed(SendDeliveryNotification::class);
    }

    public function test_pengiriman_biasa_tetap_mengirim_tautan_ke_supir_seketika(): void
    {
        Queue::fake();

        $note = $this->siapKirim();

        $this->kirim($note)->assertRedirect();

        $note->refresh();

        $this->assertFalse($note->epod_to_customer);
        $this->assertNotNull($note->epod_token);
        $this->assertNotNull($note->epod_expires_at);
        $this->assertSame('6281234567890', $note->nomorEpod());

        Queue::assertPushed(SendDeliveryNotification::class);
    }

    public function test_nomor_pelanggan_dan_tanggal_wajib_diisi_saat_luar_pulau(): void
    {
        $note = $this->siapKirim();

        $this->kirim($note, [
            'epod_to_customer' => '1',
            'vehicle_plate' => null,
        ])->assertSessionHasErrors(['customer_phone', 'eta_date', 'container_no']);

        $this->assertSame(DeliveryNote::STATUS_IMPORTED, $note->refresh()->status);
    }

    public function test_tanggal_perkiraan_tidak_boleh_sudah_lewat(): void
    {
        $note = $this->siapKirim();

        $this->kirim($note, [
            'epod_to_customer' => '1',
            'vehicle_plate' => null,
            'container_no' => 'ABCU1234567',
            'customer_phone' => '082199998888',
            'eta_date' => now()->subDay()->toDateString(),
        ])->assertSessionHasErrors('eta_date');
    }

    public function test_nomor_pelanggan_diperiksa_seketat_nomor_supir(): void
    {
        $note = $this->siapKirim();

        $this->kirim($note, [
            'epod_to_customer' => '1',
            'vehicle_plate' => null,
            'container_no' => 'ABCU1234567',
            // Nomor telepon rumah: lolos "wajib diisi", tidak lolos bentuk.
            'customer_phone' => '021555444',
            'eta_date' => now()->addDays(10)->toDateString(),
        ])->assertSessionHasErrors('customer_phone');
    }

    public function test_plat_tetap_wajib_pada_pengiriman_biasa(): void
    {
        $note = $this->siapKirim();

        $this->kirim($note, ['vehicle_plate' => null])->assertSessionHasErrors('vehicle_plate');
    }

    /* ------------------------------------------------ Saat tanggalnya tiba */

    public function test_tautan_terbit_dan_terkirim_ke_pelanggan_pada_tanggal_perkiraan(): void
    {
        Queue::fake();

        $note = $this->sudahBerlayar(now()->toDateString());

        $this->artisan('epod:kirim-pelanggan')->assertSuccessful();

        $note->refresh();

        $this->assertNotNull($note->epod_token);
        $this->assertTrue($note->epod_expires_at->isFuture());
        $this->assertSame('6282199998888', $note->nomorEpod());

        Queue::assertPushed(
            SendDeliveryNotification::class,
            fn (SendDeliveryNotification $job) => $job->deliveryNoteId === $note->id,
        );
    }

    public function test_tautan_belum_terbit_sebelum_tanggal_perkiraan(): void
    {
        Queue::fake();

        $note = $this->sudahBerlayar(now()->addDays(9)->toDateString());

        $this->artisan('epod:kirim-pelanggan')->assertSuccessful();

        $this->assertNull($note->refresh()->epod_token);
        Queue::assertNotPushed(SendDeliveryNotification::class);
    }

    /**
     * Penjadwal yang tidak jalan sehari tidak boleh membuat kirimannya hilang.
     *
     * Ini yang membedakan perintah pembaca KEADAAN dari pemicu berbasis
     * kejadian: tanggalnya sudah lewat tiga hari, dan tautannya tetap terbit.
     */
    public function test_yang_tanggalnya_sudah_lewat_tetap_terjaring(): void
    {
        Queue::fake();

        $note = $this->sudahBerlayar(now()->subDays(3)->toDateString());

        $this->artisan('epod:kirim-pelanggan')->assertSuccessful();

        $this->assertNotNull($note->refresh()->epod_token);
        Queue::assertPushed(SendDeliveryNotification::class);
    }

    public function test_tautan_hanya_terbit_sekali(): void
    {
        Queue::fake();

        $note = $this->sudahBerlayar(now()->toDateString());

        $this->artisan('epod:kirim-pelanggan')->assertSuccessful();
        $token = $note->refresh()->epod_token;

        $this->artisan('epod:kirim-pelanggan')->assertSuccessful();

        // Token yang berganti berarti tautan yang sudah ada di HP pelanggan
        // mendadak menjadi 404 — tepat saat ia hendak menekannya.
        $this->assertSame($token, $note->refresh()->epod_token);
        Queue::assertPushed(SendDeliveryNotification::class, 1);
    }

    public function test_dry_run_tidak_menerbitkan_apa_pun(): void
    {
        Queue::fake();

        $note = $this->sudahBerlayar(now()->toDateString());

        $this->artisan('epod:kirim-pelanggan', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($note->refresh()->epod_token);
        Queue::assertNotPushed(SendDeliveryNotification::class);
    }

    public function test_yang_sudah_sampai_tidak_dikirimi_tautan_lagi(): void
    {
        Queue::fake();

        $note = $this->sudahBerlayar(now()->toDateString());
        $note->forceFill([
            'status' => DeliveryNote::STATUS_DELIVERED,
            'delivered_at' => now(),
            'arrival_manual_by' => $this->loginAt()->id,
            'arrival_manual_reason' => 'Ditandai Logistik setelah pelanggan menelepon.',
        ])->save();

        $this->artisan('epod:kirim-pelanggan')->assertSuccessful();

        $this->assertNull($note->refresh()->epod_token);
        Queue::assertNotPushed(SendDeliveryNotification::class);
    }

    /* ---------------------------------------------------------- Isi pesan */

    public function test_pesan_pelanggan_memakai_template_sendiri_dan_menyebut_namanya(): void
    {
        $note = $this->sudahBerlayar(now()->toDateString());
        $note->forceFill(['epod_token' => Str::random(48)])->save();

        $pesan = $note->refresh()->pesanWhatsAppEpod();

        // Template tersendiri: pembacanya orang di luar organisasi yang tidak
        // pernah meminta pesan ini, dan pesan berbunyi "konfirmasi
        // pengiriman" tanpa menyebut namanya akan terbaca sebagai penipuan.
        $this->assertSame(PesanWhatsApp::TEMPLATE_KONFIRMASI_PELANGGAN, $pesan->template);
        $this->assertStringContainsString('Toko Medan Jaya', $pesan->teks);
        $this->assertStringContainsString('ABCU1234567', $pesan->teks);
        $this->assertStringContainsString($note->epodUrl(), $pesan->teks);
    }

    /* ------------------------------------------------- Menggeser tanggalnya */

    public function test_logistik_bisa_menggeser_perkiraan_sampai(): void
    {
        Queue::fake();

        $this->loginAt();
        $note = $this->sudahBerlayar(now()->addDay()->toDateString());

        $this->post(route('wms.delivery.perkiraan-sampai', $note), [
            'eta_date' => now()->addDays(8)->toDateString(),
            'alasan' => 'Kapal tertahan di Tanjung Priok.',
        ])->assertRedirect();

        $this->assertSame(
            now()->addDays(8)->toDateString(),
            $note->refresh()->eta_date->toDateString(),
        );

        // Digeser ke depan berarti belum waktunya: tautannya tetap belum ada.
        $this->artisan('epod:kirim-pelanggan')->assertSuccessful();
        $this->assertNull($note->refresh()->epod_token);
    }

    public function test_perkiraan_tidak_bisa_digeser_setelah_tautannya_terbit(): void
    {
        Queue::fake();

        $this->loginAt();
        $note = $this->sudahBerlayar(now()->toDateString());

        $this->artisan('epod:kirim-pelanggan')->assertSuccessful();

        $this->post(route('wms.delivery.perkiraan-sampai', $note), [
            'eta_date' => now()->addDays(5)->toDateString(),
            'alasan' => 'Coba menggeser padahal pesannya sudah masuk HP pelanggan.',
        ])->assertRedirect();

        // Tanggalnya tidak berubah: menggesernya tidak menarik kembali pesan
        // yang sudah terkirim, dan angka yang berbeda dari kenyataan lebih
        // buruk daripada angka yang jelas-jelas sudah lewat.
        $this->assertSame(now()->toDateString(), $note->refresh()->eta_date->toDateString());
    }

    public function test_alasan_wajib_saat_menggeser(): void
    {
        $this->loginAt();
        $note = $this->sudahBerlayar(now()->addDay()->toDateString());

        $this->post(route('wms.delivery.perkiraan-sampai', $note), [
            'eta_date' => now()->addDays(8)->toDateString(),
        ])->assertSessionHasErrors('alasan');
    }

    /* ------------------------------------------------------------- Invarian */

    public function test_basis_data_menolak_kiriman_luar_pulau_tanpa_tujuan(): void
    {
        $note = $this->sudahBerlayar(now()->toDateString());

        $this->expectException(QueryException::class);

        // Menyimpan langsung, melewati formulir: aturan sepenting ini tidak
        // boleh hanya tinggal di lapisan yang paling mudah dilewati.
        DB::table('delivery_notes')
            ->where('id', $note->id)
            ->update(['customer_phone' => null]);
    }

    /* ------------------------------------------------------------ Perkakas */

    private function loginAt(string $slug = Role::LOGISTICS): User
    {
        $user = User::factory()->withRole($slug)->create(['warehouse_id' => $this->karawang->id]);
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

    /** Surat Jalan yang pesanannya sudah selesai dipicking dan siap berangkat. */
    private function siapKirim(int $qty = 10): DeliveryNote
    {
        $this->loginAt();

        $stok = InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->karawang->id,
            'location_id' => $this->rak->id,
            'batch_no' => 'BT-2601',
            'production_date' => '2026-01-15',
            'expiry_date' => '2028-01-15',
            'qty_available' => 100,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);

        $order = SalesOrder::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->karawang->id,
            'payment_term_id' => $this->term->id,
            'status' => SalesOrder::STATUS_APPROVED,
            'bc_so_number' => 'SO260903',
            'submitted_at' => now()->subDay(),
            'approved_at' => now()->subDay(),
        ]);

        $detail = SalesOrderDetail::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->produk->id,
            'qty_ordered' => $qty,
            'qty_approved' => $qty,
            'outstanding_qty' => 0,
        ]);

        DB::transaction(fn () => app(FifoAllocator::class)->allocate($detail, $qty, null));

        $daftar = PickingList::factory()->create([
            'warehouse_id' => $this->karawang->id,
            'status' => PickingList::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $order->forceFill(['picking_list_id' => $daftar->id])->save();

        $stok->refresh();
        $stok->forceFill(['qty_allocated' => max(0, $stok->qty_allocated - $qty)])->save();
        $detail->allocations()->delete();

        PickingListItem::factory()->create([
            'picking_list_id' => $daftar->id,
            'sales_order_id' => $order->id,
            'sales_order_detail_id' => $detail->id,
            'product_id' => $this->produk->id,
            'inventory_stock_id' => $stok->id,
            'location_id' => $this->rak->id,
            'batch_no' => 'BT-2601',
            'production_date' => '2026-01-15',
            'qty_to_pick' => $qty,
            'qty_picked' => $qty,
            'status' => PickingListItem::STATUS_PICKED,
        ]);

        $order->forceFill([
            'status' => SalesOrder::STATUS_READY_TO_SHIP,
            'picking_completed_at' => now(),
        ])->save();

        $note = DeliveryNote::factory()->create([
            'document_no' => '206215',
            'bc_so_number' => $order->bc_so_number,
            'sales_order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'customer_code' => 'IDR13302',
            'warehouse_id' => $this->karawang->id,
        ]);

        DeliveryNoteLine::factory()->create([
            'delivery_note_id' => $note->id,
            'sku' => $this->produk->sku,
            'product_id' => $this->produk->id,
            'qty' => $qty,
        ]);

        return $note->refresh();
    }

    /** Kiriman luar pulau yang sudah berangkat dan sedang di perjalanan. */
    private function sudahBerlayar(string $eta): DeliveryNote
    {
        $note = $this->siapKirim();

        $this->kirim($note, [
            'epod_to_customer' => '1',
            'vehicle_plate' => null,
            'container_no' => 'ABCU1234567',
            'forwarder_name' => 'Meratus',
            'customer_phone' => '082199998888',
            'eta_date' => now()->addDays(14)->toDateString(),
        ])->assertRedirect();

        // Tanggalnya disetel langsung, bukan lewat formulir: sebagian test
        // butuh tanggal yang sudah lewat, dan formulirnya memang menolak itu.
        $note->refresh()->forceFill(['eta_date' => $eta])->save();

        return $note->refresh();
    }

    private function kirim(DeliveryNote $note, array $ubah = [])
    {
        return $this->post(route('wms.delivery.ship', $note), array_merge([
            'driver_name' => 'Budi Santoso',
            'driver_phone' => '081234567890',
            'vehicle_plate' => 'B 1234 XYZ',
        ], $ubah));
    }
}
