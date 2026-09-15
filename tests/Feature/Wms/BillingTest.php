<?php

namespace Tests\Feature\Wms;

use App\Models\ActivityLog;
use App\Models\BillingPayment;
use App\Models\Customer;
use App\Models\CustomerBilling;
use App\Models\DeliveryProof;
use App\Models\Notification;
use App\Models\PaymentTerm;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Billing — buku pantau piutang (Fase 8).
 *
 * Yang paling dijaga, karena semuanya tidak menghasilkan galat kalau salah:
 *
 *   1. Jatuh tempo dihitung dari tanggal BARANG SAMPAI, bukan tanggal complete.
 *   2. Satu invoice gabungan = satu tagihan; melunasinya menutup semua pesanannya.
 *   3. Status pesanan dan status tagihan selalu sepakat, termasuk saat dibatalkan.
 *   4. Pengingat hanya untuk Manager, sekali per invoice per tahap.
 *   5. Penanda menunggak hanya informasi — tidak pernah memblokir.
 */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $karawang;

    private Warehouse $pekanbaru;

    private Customer $customer;

    private PaymentTerm $cash;

    private PaymentTerm $tempo;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();

        // 1 Oktober 2026 pukul 10:00 WIB.
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 10, 1)->setTime(10, 0));

        $this->karawang = Warehouse::factory()->create(['code' => 'WH-01', 'name' => 'Karawang']);
        $this->pekanbaru = Warehouse::factory()->create(['code' => 'WH-02', 'name' => 'Pekanbaru']);
        $this->customer = Customer::factory()->create(['code' => 'IDR13302', 'name' => 'Toko Maju Jaya', 'is_active' => true]);

        $this->cash = PaymentTerm::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Cash / Tunai', 'days' => 0, 'is_active' => true, 'sort_order' => 1]
        );
        $this->tempo = PaymentTerm::firstOrCreate(
            ['code' => 'net30'],
            ['name' => 'Tempo 30 Hari', 'days' => 30, 'is_active' => true, 'sort_order' => 2]
        );
    }

    /* ------------------------------------------------------------ Perkakas */

    private function login(string $slug = Role::LOGISTICS, ?Warehouse $gudang = null): User
    {
        $user = User::factory()->withRole($slug)->create(['warehouse_id' => ($gudang ?? $this->karawang)->id]);
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
        // getJson() tidak mengirim cookie tanpa withCredentials() — lihat OrderApprovalTest.
        $this->withCredentials();
        $this->actingAs($user);

        return $user;
    }

    /** Pesanan yang buktinya sudah diunggah dan tinggal dinyatakan selesai. */
    private function pesananSiapSelesai(array $ubah = []): SalesOrder
    {
        $order = SalesOrder::factory()->create(array_merge([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->karawang->id,
            'payment_term_id' => $this->tempo->id,
            'status' => SalesOrder::STATUS_PROOF_UPLOADED,
            'bc_so_number' => 'SO-'.Str::upper(Str::random(6)),
            'submitted_at' => now()->subDays(10),
            'approved_at' => now()->subDays(10),
            'shipped_at' => now()->subDays(6),
            // Sampai 25 September, dinyatakan selesai 1 Oktober.
            'delivered_at' => now()->subDays(6)->addHours(5),
        ], $ubah));

        DeliveryProof::factory()->create([
            'sales_order_id' => $order->id,
            'uploaded_by' => $order->user_id,
        ]);

        return $order;
    }

    /** Tagihan langsung di tabel, untuk keadaan yang tidak sedang diuji jalannya. */
    private function tagihan(string $jatuhTempo, array $ubah = [], array $pesanan = []): CustomerBilling
    {
        $order = SalesOrder::factory()->create(array_merge([
            'customer_id' => $ubah['customer_id'] ?? $this->customer->id,
            'warehouse_id' => $ubah['warehouse_id'] ?? $this->karawang->id,
            'payment_term_id' => $this->tempo->id,
            'status' => SalesOrder::STATUS_COMPLETED_BILLING,
            'bc_so_number' => 'SO-'.Str::upper(Str::random(6)),
            'submitted_at' => now()->subDays(45),
            'completed_at' => now()->subDays(20),
        ], $pesanan));

        return CustomerBilling::create(array_merge([
            'sales_order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->karawang->id,
            'payment_term_id' => $this->tempo->id,
            'term_days' => 30,
            'delivered_on' => now()->subDays(30)->toDateString(),
            'due_date' => $jatuhTempo,
        ], $ubah));
    }

    private function lunasi(array $tagihan, array $ubah = [])
    {
        return $this->post(route('wms.billing.pay'), array_merge([
            'billing_ids' => array_map(fn (CustomerBilling $t) => $t->id, $tagihan),
            'paid_on' => '2026-09-30',
            'method' => BillingPayment::METHOD_TRANSFER,
            'reference' => 'TRX-889123',
        ], $ubah));
    }

    /* ------------------------------------------------ Tagihan terbentuk */

    public function test_pesanan_tempo_yang_selesai_membuat_tagihan_dari_tanggal_barang_sampai(): void
    {
        $this->login();
        $order = $this->pesananSiapSelesai();

        $this->post(route('wms.verification.complete', $order))->assertSessionHas('success');

        $tagihan = CustomerBilling::sole();

        $this->assertSame($order->id, $tagihan->sales_order_id);
        // Sampai 25 Sep, BUKAN selesai 1 Okt: foto yang terlambat diunggah
        // tidak boleh menggeser jatuh tempo customer.
        $this->assertSame('2026-09-25', $tagihan->delivered_on->toDateString());
        $this->assertSame('2026-10-25', $tagihan->due_date->toDateString());
        $this->assertSame(30, $tagihan->term_days);
        $this->assertSame(SalesOrder::STATUS_COMPLETED_BILLING, $order->fresh()->status);
    }

    public function test_pesanan_tunai_tidak_masuk_billing(): void
    {
        $this->login();
        $order = $this->pesananSiapSelesai(['payment_term_id' => $this->cash->id]);

        $this->post(route('wms.verification.complete', $order));

        $this->assertSame(SalesOrder::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertSame(0, CustomerBilling::count());
    }

    public function test_pesanan_gabungan_menumpang_di_tagihan_induknya(): void
    {
        $this->login();
        $induk = $this->pesananSiapSelesai(['bc_so_number' => 'SO260903']);
        $anak = $this->pesananSiapSelesai(['bc_so_number' => 'SO260903', 'so_merged_into_id' => $induk->id]);

        $this->post(route('wms.verification.complete', $anak));
        $this->post(route('wms.verification.complete', $induk));

        // Satu invoice, satu tagihan — bukan dua yang harus dilunasi terpisah.
        $this->assertSame(1, CustomerBilling::count());
        $this->assertSame($induk->id, CustomerBilling::sole()->sales_order_id);

        $this->lunasi([CustomerBilling::sole()])->assertSessionHas('success');

        $this->assertSame(SalesOrder::STATUS_COMPLETED, $induk->fresh()->status);
        $this->assertSame(SalesOrder::STATUS_COMPLETED, $anak->fresh()->status, 'Anak gabungan ikut lunas.');
    }

    public function test_pesanan_yang_invoicenya_sudah_lunas_langsung_selesai(): void
    {
        $this->login();
        $induk = $this->pesananSiapSelesai(['bc_so_number' => 'SO260903']);
        $this->post(route('wms.verification.complete', $induk));
        $this->lunasi([CustomerBilling::sole()]);

        $anak = $this->pesananSiapSelesai(['bc_so_number' => 'SO260903', 'so_merged_into_id' => $induk->id]);
        $this->post(route('wms.verification.complete', $anak));

        // Tidak dibiarkan "menunggu bayar" atas invoice yang sudah dibayar.
        $this->assertSame(SalesOrder::STATUS_COMPLETED, $anak->fresh()->status);
        $this->assertSame(1, CustomerBilling::count());
    }

    /* ------------------------------------------------------------ Layar */

    public function test_tab_memisahkan_segera_lewat_dan_lunas(): void
    {
        $this->login();

        $segera = $this->tagihan('2026-10-05', [], ['bc_so_number' => 'SO-SEGERA']);
        $bulanDepan = $this->tagihan('2026-11-20', [], ['bc_so_number' => 'SO-NANTI']);
        $lewat = $this->tagihan('2026-09-20', [], ['bc_so_number' => 'SO-LEWAT']);

        $this->get(route('wms.billing.index'))
            ->assertOk()
            ->assertSee('SO-SEGERA')
            ->assertDontSee('SO-NANTI')
            ->assertDontSee('SO-LEWAT');

        $this->get(route('wms.billing.index', ['tab' => 'lewat']))
            ->assertSee('SO-LEWAT')
            ->assertSee('Lewat 11 hari')
            ->assertDontSee('SO-SEGERA');

        $this->get(route('wms.billing.index', ['tab' => 'berjalan']))
            ->assertSee('SO-SEGERA')->assertSee('SO-NANTI')->assertSee('SO-LEWAT');

        $this->lunasi([$segera]);

        $this->get(route('wms.billing.index', ['tab' => 'lunas']))
            ->assertSee('SO-SEGERA')
            ->assertSee('TRX-889123')
            ->assertDontSee('SO-NANTI');

        $this->assertNotNull($bulanDepan->fresh());
        $this->assertNotNull($lewat->fresh());
    }

    public function test_tagihan_gudang_lain_tidak_terlihat(): void
    {
        $this->login(Role::LOGISTICS, $this->pekanbaru);
        $this->tagihan('2026-10-03', [], ['bc_so_number' => 'SO-KARAWANG']);

        $this->get(route('wms.billing.index'))->assertOk()->assertDontSee('SO-KARAWANG');
    }

    public function test_halaman_tidak_menampilkan_data_karangan(): void
    {
        $this->login();

        $this->get(route('wms.billing.index'))
            ->assertOk()
            ->assertDontSee('Toko Merah')
            ->assertDontSee('INV-2606-088');
    }

    /* ------------------------------------------------------------ Lunas */

    public function test_satu_konfirmasi_melunasi_beberapa_invoice_satu_customer(): void
    {
        $this->login();
        $a = $this->tagihan('2026-10-05');
        $b = $this->tagihan('2026-09-20');

        $this->lunasi([$a, $b], [
            'method' => BillingPayment::METHOD_GIRO,
            'reference' => 'GR-7781',
        ])->assertRedirect(route('wms.billing.index', ['tab' => 'lunas']));

        $bayar = BillingPayment::sole();

        $this->assertSame('GR-7781', $bayar->reference);
        $this->assertSame($bayar->id, $a->fresh()->billing_payment_id);
        $this->assertSame($bayar->id, $b->fresh()->billing_payment_id);
        $this->assertSame(SalesOrder::STATUS_COMPLETED, $a->salesOrder->fresh()->status);
        $this->assertSame(SalesOrder::STATUS_COMPLETED, $b->salesOrder->fresh()->status);

        $this->assertDatabaseHas('activity_logs', ['action' => ActivityLog::BILLING_PAY]);
    }

    public function test_campuran_dua_customer_ditolak(): void
    {
        $this->login();
        $lain = Customer::factory()->create();

        $a = $this->tagihan('2026-10-05');
        $b = $this->tagihan('2026-10-05', ['customer_id' => $lain->id], ['customer_id' => $lain->id]);

        $this->lunasi([$a, $b])->assertSessionHas('error');

        $this->assertSame(0, BillingPayment::count());
        $this->assertNull($a->fresh()->billing_payment_id);
    }

    public function test_invoice_yang_sudah_lunas_tidak_bisa_dilunasi_lagi(): void
    {
        $this->login();
        $a = $this->tagihan('2026-10-05');

        $this->lunasi([$a]);
        $this->lunasi([$a])->assertSessionHas('error');

        $this->assertSame(1, BillingPayment::count());
    }

    public function test_giro_wajib_bernomor_dan_tanggal_tidak_boleh_di_masa_depan(): void
    {
        $this->login();
        $a = $this->tagihan('2026-10-05');

        $this->lunasi([$a], ['method' => BillingPayment::METHOD_GIRO, 'reference' => ''])
            ->assertSessionHasErrors('reference');

        $this->lunasi([$a], ['paid_on' => '2026-10-02'])
            ->assertSessionHasErrors('paid_on');

        // Transfer dan tunai boleh tanpa nomor.
        $this->lunasi([$a], ['method' => BillingPayment::METHOD_TUNAI, 'reference' => ''])
            ->assertSessionHasNoErrors();
    }

    public function test_tagihan_gudang_lain_tidak_bisa_dilunasi(): void
    {
        $this->login(Role::LOGISTICS, $this->pekanbaru);
        $a = $this->tagihan('2026-10-05');

        $this->lunasi([$a])->assertSessionHas('error');

        $this->assertNull($a->fresh()->billing_payment_id);
    }

    /* ------------------------------------------------------------ Hak akses */

    public function test_manager_melihat_tetapi_tidak_mengonfirmasi_lunas(): void
    {
        $this->login(Role::MANAGER);
        $a = $this->tagihan('2026-10-05');

        $this->get(route('wms.billing.index'))->assertOk()->assertDontSee('Konfirmasi Lunas');
        $this->lunasi([$a])->assertForbidden();
    }

    public function test_peran_lain_tidak_bisa_membuka_billing(): void
    {
        foreach ([Role::WAREHOUSE_OPERATOR, Role::PRODUCTION] as $slug) {
            $this->login($slug);

            $this->get(route('wms.billing.index'))->assertForbidden();
        }
    }

    /* ------------------------------------------------------------ Batal */

    public function test_manager_membatalkan_konfirmasi_yang_keliru(): void
    {
        $this->login();
        $a = $this->tagihan('2026-10-05');
        $this->lunasi([$a]);
        $bayar = BillingPayment::sole();

        $manager = $this->login(Role::MANAGER);

        $this->post(route('wms.billing.void', $bayar), ['reason' => 'Giro ditolak bank, dana tidak cukup.'])
            ->assertSessionHas('warning');

        $this->assertNull($a->fresh()->billing_payment_id);
        $this->assertSame(SalesOrder::STATUS_COMPLETED_BILLING, $a->salesOrder->fresh()->status);

        // Dibatalkan, BUKAN dihapus: jejaknya tetap terbaca.
        $bayar->refresh();
        $this->assertNotNull($bayar->voided_at);
        $this->assertSame($manager->id, $bayar->voided_by);
        $this->assertDatabaseHas('activity_logs', ['action' => ActivityLog::BILLING_VOID]);
    }

    public function test_logistik_tidak_bisa_membatalkan_dan_alasan_wajib(): void
    {
        $this->login();
        $a = $this->tagihan('2026-10-05');
        $this->lunasi([$a]);
        $bayar = BillingPayment::sole();

        $this->post(route('wms.billing.void', $bayar), ['reason' => 'Salah centang invoice.'])->assertForbidden();

        $this->login(Role::MANAGER);
        $this->post(route('wms.billing.void', $bayar), ['reason' => 'salah'])->assertSessionHasErrors('reason');

        $this->assertNotNull($a->fresh()->billing_payment_id);
    }

    /* ------------------------------------------------------------ Pengingat */

    public function test_pengingat_hanya_ke_manager_dan_sekali_saja(): void
    {
        $manager = User::factory()->withRole(Role::MANAGER)->create(['warehouse_id' => $this->karawang->id]);
        $managerLain = User::factory()->withRole(Role::MANAGER)->create(['warehouse_id' => $this->pekanbaru->id]);
        $logistik = User::factory()->withRole(Role::LOGISTICS)->create(['warehouse_id' => $this->karawang->id]);
        $admin = User::factory()->withRole(Role::SUPER_ADMIN)->create(['warehouse_id' => null]);

        $this->tagihan('2026-10-03', [], ['bc_so_number' => 'SO-SEGERA']);
        $this->tagihan('2026-10-02', [], ['bc_so_number' => 'SO-SEGERA2']);
        $this->tagihan('2026-10-20', [], ['bc_so_number' => 'SO-NANTI']);
        $lewat = $this->tagihan('2026-09-25', [], ['bc_so_number' => 'SO-LEWAT']);

        $this->artisan('billing:ingatkan')->assertSuccessful();

        // SATU lonceng per jenis, menyebut kedua invoice — bukan dua lonceng.
        $segera = Notification::where('user_id', $manager->id)->where('type', Notification::BILLING_DUE_SOON)->sole();
        $this->assertStringContainsString('2 invoice', $segera->title);
        $this->assertStringContainsString('SO-SEGERA', $segera->body);
        $this->assertStringContainsString('SO-SEGERA2', $segera->body);
        $this->assertStringNotContainsString('SO-NANTI', $segera->body);

        $telat = Notification::where('user_id', $manager->id)->where('type', Notification::BILLING_OVERDUE)->sole();
        $this->assertStringContainsString('SO-LEWAT', $telat->body);

        foreach ([$logistik, $admin, $managerLain, $lewat->salesOrder->user] as $bukan) {
            $this->assertSame(0, Notification::where('user_id', $bukan->id)->whereIn('type', [
                Notification::BILLING_DUE_SOON, Notification::BILLING_OVERDUE,
            ])->count());
        }

        // Pagi berikutnya: tidak berbunyi lagi untuk invoice yang sama.
        $this->artisan('billing:ingatkan')->assertSuccessful();
        $this->assertSame(2, Notification::where('user_id', $manager->id)->count());
    }

    public function test_pengingat_menambal_tagihan_pesanan_lama(): void
    {
        // Selesai sebelum modul Billing ada: statusnya sudah menunggu bayar,
        // tetapi tagihannya tidak pernah dibuat.
        $lama = SalesOrder::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->karawang->id,
            'payment_term_id' => $this->tempo->id,
            'status' => SalesOrder::STATUS_COMPLETED_BILLING,
            'submitted_at' => now()->subDays(45),
            'delivered_at' => now()->subDays(40),
            'completed_at' => now()->subDays(38),
        ]);

        $this->artisan('billing:ingatkan')->assertSuccessful();

        $tagihan = CustomerBilling::sole();
        $this->assertSame($lama->id, $tagihan->sales_order_id);
        $this->assertSame('2026-08-22', $tagihan->delivered_on->toDateString());
        $this->assertSame('2026-09-21', $tagihan->due_date->toDateString());

        // Dijalankan lagi tidak membuat tagihan kedua.
        $this->artisan('billing:ingatkan')->assertSuccessful();
        $this->assertSame(1, CustomerBilling::count());
    }

    /* ------------------------------------------------------------ Penanda */

    public function test_menunggak_hanya_untuk_invoice_yang_lewat_jatuh_tempo(): void
    {
        $berjalan = Customer::factory()->create();
        $menunggak = Customer::factory()->create();

        $this->tagihan('2026-10-20', ['customer_id' => $berjalan->id], ['customer_id' => $berjalan->id]);
        $this->tagihan('2026-09-21', ['customer_id' => $menunggak->id], ['customer_id' => $menunggak->id]);
        $this->tagihan('2026-10-30', ['customer_id' => $menunggak->id], ['customer_id' => $menunggak->id]);

        $penanda = CustomerBilling::penandaCustomer([$berjalan->id, $menunggak->id, $this->customer->id]);

        $this->assertSame(0, $penanda[$berjalan->id]['menunggak']);
        $this->assertSame(1, $penanda[$berjalan->id]['berjalan']);

        $this->assertSame(1, $penanda[$menunggak->id]['menunggak']);
        $this->assertSame(2, $penanda[$menunggak->id]['berjalan']);
        $this->assertSame(10, $penanda[$menunggak->id]['lewat_terlama']);

        $this->assertArrayNotHasKey($this->customer->id, $penanda, 'Tanpa tagihan, tanpa penanda.');
    }

    public function test_penanda_menunggak_tampil_di_approval_tanpa_memblokir(): void
    {
        $this->login();
        $this->tagihan('2026-09-21');

        $order = SalesOrder::factory()->submitted()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->karawang->id,
            'payment_term_id' => $this->tempo->id,
        ]);

        $this->get(route('wms.approval.show', $order))
            ->assertOk()
            ->assertSee('Menunggak 10 hari');
    }

    public function test_penanda_tampil_di_master_customer_dan_pencarian_pesanan_internal(): void
    {
        $this->login(Role::MANAGER);
        $this->tagihan('2026-09-21');

        $this->get('/wms/master/customers')->assertOk()->assertSee('Menunggak 10 hari');

        $this->getJson(route('wms.internal-order.lookup.customers', ['q' => 'Maju']))
            ->assertOk()
            ->assertJsonFragment(['code' => 'IDR13302', 'menunggak' => 10]);
    }
}
