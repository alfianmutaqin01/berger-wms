<?php

namespace Tests\Feature\Messaging;

use App\Jobs\SendSalesOrderEmail;
use App\Mail\Pesanan\BarangDikirim;
use App\Mail\Pesanan\BarangSampai;
use App\Mail\Pesanan\PesananDiterima;
use App\Mail\Pesanan\PesananDitolak;
use App\Mail\Pesanan\PesananSelesai;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\DeliveryProof;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SalesOrderEmail;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use App\Support\Messaging\EmailSales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Email kabar pesanan untuk Sales pemilik pesanan.
 *
 * Tiga hal yang paling dijaga, karena ketiganya tidak menghasilkan galat apa
 * pun kalau salah:
 *
 *   1. Penerimanya HANYA Sales pemilik pesanan — bukan Admin yang membuatkan
 *      pesanannya (keputusan pemilik produk).
 *   2. Email yang sama tidak terkirim dua kali saat antrean mencoba ulang.
 *   3. Kegagalan tercatat beserta alasannya, bukan diam.
 *
 * Kabar "barang dikirim" dan "barang sampai" diuji di ShipmentTest, yang
 * sudah punya alur picking sampai konfirmasi supir yang sungguhan.
 */
class EmailPesananTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $gudang;

    private Product $produk;

    private Customer $customer;

    private PaymentTerm $term;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->gudang = Warehouse::factory()->create(['code' => 'KRW', 'name' => 'Karawang']);
        $this->produk = Product::factory()->create(['sku' => 'APKO-001', 'name' => 'Cat Tembok 20Kg', 'uom' => 'PAIL', 'is_active' => true]);
        $this->customer = Customer::factory()->create(['name' => 'Toko Maju Jaya', 'is_active' => true]);
        $this->term = PaymentTerm::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Cash / Tunai', 'days' => 0, 'is_active' => true, 'sort_order' => 1]
        );

        InventoryStock::factory()->create([
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->gudang->id,
            'location_id' => Location::factory()->create(['warehouse_id' => $this->gudang->id])->id,
            'batch_no' => 'BT-01',
            'production_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYears(2)->toDateString(),
            'qty_available' => 100,
            'qty_allocated' => 0,
            'status' => InventoryStock::STATUS_ACTIVE,
        ]);
    }

    /* ------------------------------------------------------------ Perkakas */

    private function login(string $slug = Role::LOGISTICS): User
    {
        $user = User::factory()->withRole($slug)->create(['warehouse_id' => $this->gudang->id]);
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

    private function sales(string $email = 'budi.sales@contoh.co.id'): User
    {
        return User::factory()->withRole(Role::SALES)->create([
            'warehouse_id' => $this->gudang->id,
            'full_name' => 'Budi Santoso',
            'email' => $email,
        ]);
    }

    private function pesananMenunggu(User $sales, int $dipesan = 10, array $ubah = []): SalesOrder
    {
        $order = SalesOrder::factory()->submitted()->create(array_merge([
            'user_id' => $sales->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->gudang->id,
            'payment_term_id' => $this->term->id,
            'status' => SalesOrder::STATUS_PENDING,
        ], $ubah));

        $order->details()->create(['product_id' => $this->produk->id, 'qty_ordered' => $dipesan]);

        return $order;
    }

    private function pesananDiterima(User $sales): SalesOrder
    {
        $order = $this->pesananMenunggu($sales, 10, [
            'status' => SalesOrder::STATUS_APPROVED,
            'approved_at' => now(),
            'bc_so_number' => 'SO260903',
        ]);
        $order->details()->update(['qty_approved' => 8, 'outstanding_qty' => 2]);

        return $order->fresh();
    }

    private function terima(SalesOrder $order, int $qty)
    {
        return $this->post("/wms/outbound/approval/{$order->id}/accept", [
            'bc_so_number' => 'SO260903',
            'item' => [[
                'product_id' => $this->produk->id,
                'qty_approved' => $qty,
                'qty_ordered' => $order->details()->first()->qty_ordered,
            ]],
        ]);
    }

    /* ------------------------------------------------------------ Penerima */

    public function test_pesanan_diterima_dikirim_hanya_ke_sales_pemiliknya(): void
    {
        Mail::fake();

        $admin = $this->login(Role::LOGISTICS);
        $budi = $this->sales();
        $pembuat = User::factory()->withRole(Role::MANAGER)->create(['email' => 'manager@contoh.co.id']);

        // Dibuatkan Manager atas nama Budi: yang dikabari tetap Budi saja.
        $order = $this->pesananMenunggu($budi, 10, [
            'placed_by' => $pembuat->id,
            'placed_reason' => 'Sales sedang di luar kota',
        ]);

        $this->terima($order, 8)->assertRedirect();

        Mail::assertSentCount(1);
        Mail::assertSent(PesananDiterima::class, fn (PesananDiterima $m) => $m->hasTo('budi.sales@contoh.co.id')
            && ! $m->hasTo($pembuat->email)
            && ! $m->hasTo($admin->email)
            && ! $m->hasCc($pembuat->email));

        $this->assertDatabaseHas('sales_order_emails', [
            'sales_order_id' => $order->id,
            'type' => SalesOrderEmail::TYPE_APPROVED,
            'status' => SalesOrderEmail::STATUS_SENT,
            'recipient_user_id' => $budi->id,
            'recipient_email' => 'budi.sales@contoh.co.id',
        ]);
    }

    public function test_email_diterima_menyebut_qty_dipesan_dan_diterima(): void
    {
        $order = $this->pesananDiterima($this->sales());

        $email = new PesananDiterima($order, ['dicadangkan' => 5, 'menunggu_stok' => 3]);

        $email->assertHasSubject('[Berger WMS] Pesanan diterima — SO260903 (Toko Maju Jaya)');
        $email->assertSeeInHtml('Tidak semua qty diterima');
        $email->assertSeeInHtml('Dipesan 10, diterima 8');
        $email->assertSeeInHtml('kurang 2');
        // Angka saat penerimaan, bukan angka stok hari ini.
        $email->assertSeeInHtml('3 unit dari yang diterima');
        $email->assertSeeInHtml('/sales/orders/'.$order->id);
    }

    public function test_penolakan_mengirim_email_berisi_alasan(): void
    {
        Mail::fake();

        $this->login();
        $order = $this->pesananMenunggu($this->sales());

        $this->post("/wms/outbound/approval/{$order->id}/reject", [
            'rejection_reason' => 'Customer masih menunggak, diminta pelunasan lebih dulu.',
        ])->assertRedirect();

        Mail::assertSent(PesananDitolak::class, function (PesananDitolak $m) {
            $m->assertSeeInHtml('menunggak');

            return $m->hasTo('budi.sales@contoh.co.id');
        });
    }

    public function test_pesanan_selesai_mengirim_ringkasan(): void
    {
        Mail::fake();

        $this->login();
        $order = $this->pesananDiterima($this->sales());
        $order->forceFill([
            'status' => SalesOrder::STATUS_PROOF_UPLOADED,
            'shipped_at' => now()->subHours(6),
            'delivered_at' => now()->subHours(2),
        ])->save();

        DeliveryProof::factory()->create([
            'sales_order_id' => $order->id,
            'uploaded_by' => $order->user_id,
        ]);

        $this->post(route('wms.verification.complete', $order))->assertSessionHas('success');

        Mail::assertSent(PesananSelesai::class, function (PesananSelesai $m) {
            $m->assertSeeInHtml('Ringkasan pesanan selesai');
            $m->assertSeeInHtml('Perjalanan pesanan');

            return $m->hasTo('budi.sales@contoh.co.id');
        });
    }

    /* ------------------------------------------------------------ Kegagalan */

    public function test_sales_tanpa_email_valid_dicatat_gagal_dengan_nama_orangnya(): void
    {
        Queue::fake();
        Mail::fake();

        $order = $this->pesananDiterima($this->sales('bukan-alamat-email'));
        $surel = EmailSales::antrekan($order->id, SalesOrderEmail::TYPE_APPROVED);

        // TIDAK melempar: mengulang tiga kali tidak akan membuat alamatnya muncul.
        (new SendSalesOrderEmail($surel->id))->handle();

        Mail::assertNothingSent();

        $surel->refresh();
        $this->assertSame(SalesOrderEmail::STATUS_FAILED, $surel->status);
        $this->assertStringContainsString('Budi Santoso', $surel->error);
        $this->assertStringContainsString('Manajemen Pengguna', $surel->error);
    }

    public function test_gangguan_smtp_dicatat_gagal_lalu_dilempar_untuk_dicoba_ulang(): void
    {
        Queue::fake();

        $order = $this->pesananDiterima($this->sales());
        $surel = EmailSales::antrekan($order->id, SalesOrderEmail::TYPE_APPROVED);

        Mail::shouldReceive('to')->andThrow(new RuntimeException('535 Username and Password not accepted'));

        try {
            (new SendSalesOrderEmail($surel->id))->handle();
            $this->fail('Galat SMTP harus dilempar supaya antrean mencoba lagi.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('535', $e->getMessage());
        }

        // Statusnya SUDAH tersimpan sebelum dilempar, beserta pesan aslinya.
        $surel->refresh();
        $this->assertSame(SalesOrderEmail::STATUS_FAILED, $surel->status);
        $this->assertStringContainsString('Username and Password not accepted', $surel->error);
        $this->assertSame(1, $surel->attempts);
    }

    public function test_email_tidak_dikirim_dua_kali(): void
    {
        Queue::fake();
        Mail::fake();

        $order = $this->pesananDiterima($this->sales());
        $surel = EmailSales::antrekan($order->id, SalesOrderEmail::TYPE_APPROVED);

        (new SendSalesOrderEmail($surel->id))->handle();
        (new SendSalesOrderEmail($surel->id))->handle();

        Mail::assertSentCount(1);
        $this->assertSame(1, $surel->fresh()->attempts);
    }

    public function test_kabar_yang_sudah_tidak_benar_tidak_dikirim(): void
    {
        Queue::fake();
        Mail::fake();

        $order = $this->pesananDiterima($this->sales());
        $surel = EmailSales::antrekan($order->id, SalesOrderEmail::TYPE_APPROVED);

        // Dibatalkan sementara emailnya masih di antrean.
        $order->forceFill(['status' => SalesOrder::STATUS_PENDING, 'cancelled_at' => now()])->save();

        (new SendSalesOrderEmail($surel->id))->handle();

        Mail::assertNothingSent();

        // Dilewati, BUKAN gagal: tidak ada yang perlu diperbaiki.
        $this->assertSame(SalesOrderEmail::STATUS_SKIPPED, $surel->fresh()->status);
    }

    /* ------------------------------------------------------------ Tampilan */

    /**
     * Seluruh template dirender sungguhan.
     *
     * Mail::fake() tidak merender apa pun, jadi galat Blade di template email
     * baru ketahuan di server — di dalam antrean, jauh dari layar siapa pun.
     */
    public function test_seluruh_template_bisa_dirender(): void
    {
        $order = $this->pesananDiterima($this->sales());
        $order->forceFill([
            // Cabang "menunggu bayar" sengaja dipilih: pesanan tunai sudah
            // dirender lewat test_pesanan_selesai_mengirim_ringkasan.
            'status' => SalesOrder::STATUS_COMPLETED_BILLING,
            'rejection_reason' => 'Contoh alasan',
            'shipped_at' => now()->subHours(6),
            'delivered_at' => now()->subHours(2),
            'completed_at' => now(),
            'sla_hours' => 4.5,
        ])->save();
        $order->details()->update(['qty_shipped' => 6, 'outstanding_qty' => 4]);

        // Garis tegak di nama produk tidak boleh memecah kolom tabel.
        $this->produk->forceFill(['name' => 'Cat Tembok | Putih'])->save();

        $note = DeliveryNote::factory()->create([
            'document_no' => '206215',
            'sales_order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->gudang->id,
            'status' => DeliveryNote::STATUS_DELIVERED,
            'driver_name' => 'Pak Joko',
            'driver_phone' => '6281234567890',
            'vehicle_plate' => 'B 1234 XYZ',
            'shipped_at' => now()->subHours(6),
            'delivered_at' => now()->subHours(2),
            'received_by_name' => 'Ibu Sari',
            'arrival_photo_path' => 'epod/contoh.jpg',
            'arrival_photo_source' => 'camera',
            'arrival_photo_taken_at' => now()->subHours(2),
        ]);
        DeliveryNoteLine::factory()->create([
            'delivery_note_id' => $note->id,
            'sku' => $this->produk->sku,
            'product_id' => $this->produk->id,
            'qty' => 6,
        ]);

        $order = $order->fresh();

        (new PesananDitolak($order, ['alasan' => 'Contoh alasan']))->assertSeeInHtml('Contoh alasan');

        $dikirim = new BarangDikirim($order, $note->fresh());
        $dikirim->assertSeeInHtml('206215');
        $dikirim->assertSeeInHtml('Pak Joko (6281234567890)');
        $dikirim->assertSeeInHtml('Masih ada yang belum terkirim');
        $dikirim->assertSeeInHtml('Cat Tembok / Putih');

        $sampai = new BarangSampai($order, $note->fresh());
        $sampai->assertSeeInHtml('Ibu Sari');
        $sampai->assertSeeInHtml('Unggah bukti Surat Jalan');

        $selesai = new PesananSelesai($order);
        $selesai->assertSeeInHtml('206215');
        $selesai->assertSeeInHtml('4,5 jam');
        $selesai->assertSeeInHtml('pembayarannya masih berjalan (Cash / Tunai)');
    }

    public function test_rincian_penerimaan_menampilkan_status_email_ke_sales(): void
    {
        $order = $this->pesananDiterima($this->sales());

        SalesOrderEmail::create([
            'sales_order_id' => $order->id,
            'type' => SalesOrderEmail::TYPE_APPROVED,
            'status' => SalesOrderEmail::STATUS_FAILED,
            'error' => 'Akun Sales Budi Santoso belum punya alamat email yang valid.',
        ]);

        $this->login();

        $this->get(route('wms.approval.history.show', $order))
            ->assertOk()
            ->assertSee('Email ke Sales')
            ->assertSee('Pesanan diterima')
            ->assertSee('belum punya alamat email yang valid');
    }
}
