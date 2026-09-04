<?php

namespace Tests\Feature\Wms;

use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\SoNumberChange;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Koreksi nomor SO yang salah ketik — Fase 6 tahap 5.
 *
 * MASALAHNYA (dilaporkan pemilik produk): nomor SO diketik manusia saat
 * menerima pesanan. Salah satu digit membuat Surat Jalan dari BC tidak
 * pernah menemukan pesanannya.
 *
 * EMPAT HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * ---------------------------------------------------
 * 1. NOMORNYA DISALIN, BUKAN DIKETIK ULANG. Jari yang tadi salah ketik bisa
 *    salah lagi; dokumen BC tidak.
 * 2. SATU NOMOR SO TIDAK BOLEH DIPEGANG DUA PESANAN. Kalau lolos, Surat
 *    Jalan berikutnya akan memilih salah satu menurut urutan baris di
 *    database — dan itu bisa berubah sendiri.
 * 3. PESANAN YANG SUDAH BERANGKAT TIDAK BOLEH GANTI NOMOR. Nomor itu sudah
 *    dipakai menagih; mengubahnya berarti menulis ulang sejarah.
 * 4. PELANGGAN BERBEDA = SALAH PILIH. Memasangkannya memindahkan bukti
 *    pengiriman ke pelanggan yang keliru.
 */
class SoNumberFixTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $karawang;

    private Customer $toko;

    private Customer $tokoLain;

    private Product $produk;

    private PaymentTerm $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->karawang = Warehouse::factory()->create(['code' => 'WH-01', 'name' => 'Karawang']);
        $this->toko = Customer::factory()->create(['code' => 'IDR13302', 'is_active' => true]);
        $this->tokoLain = Customer::factory()->create(['code' => 'IDR99999', 'is_active' => true]);
        $this->produk = Product::factory()->create(['sku' => 'ID1-F0017X002820', 'is_active' => true]);
        $this->term = PaymentTerm::firstOrCreate(
            ['code' => 'cash'],
            ['name' => 'Cash / Tunai', 'days' => 0, 'is_active' => true, 'sort_order' => 1]
        );
    }

    /* ------------------------------------------------------------ Perkakas */

    private function login(): User
    {
        $user = User::factory()->withRole(Role::LOGISTICS)->create(['warehouse_id' => $this->karawang->id]);
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

    private function pesanan(string $nomorSo, array $ubah = []): SalesOrder
    {
        return SalesOrder::factory()->create(array_merge([
            'customer_id' => $this->toko->id,
            'warehouse_id' => $this->karawang->id,
            'payment_term_id' => $this->term->id,
            'status' => SalesOrder::STATUS_READY_TO_SHIP,
            'bc_so_number' => $nomorSo,
            'submitted_at' => now()->subDay(),
            'approved_at' => now()->subDay(),
            'picking_completed_at' => now(),
        ], $ubah));
    }

    /** Surat Jalan yang belum menemukan pesanannya — persis hasil impor BC. */
    private function sjYatim(string $nomorSo, ?Customer $customer = null): DeliveryNote
    {
        $customer ??= $this->toko;

        $note = DeliveryNote::factory()->create([
            'document_no' => '206215',
            'bc_so_number' => $nomorSo,
            'sales_order_id' => null,
            'warehouse_id' => null,
            'customer_id' => $customer->id,
            'customer_code' => $customer->code,
        ]);

        DeliveryNoteLine::factory()->create([
            'delivery_note_id' => $note->id,
            'sku' => $this->produk->sku,
            'product_id' => $this->produk->id,
            'qty' => 5,
        ]);

        return $note->refresh();
    }

    /* ------------------------------------------------- Pasangkan dari SJ */

    public function test_memasangkan_sj_menyalin_nomor_so_dari_dokumen_bc(): void
    {
        // Diketik SO260930, yang benar SO260903 — dua digit tertukar.
        $order = $this->pesanan('SO260930');
        $sj = $this->sjYatim('SO260903');

        $this->login();

        $this->post(route('wms.delivery.pair', $sj), ['sales_order_id' => $order->id])
            ->assertSessionHas('success');

        $order->refresh();
        $sj->refresh();

        $this->assertSame('SO260903', $order->bc_so_number, 'Nomor SO harus mengikuti dokumen BC.');
        $this->assertSame($order->id, $sj->sales_order_id);

        // Gudang SJ yatim kosong saat impor; setelah berpasangan ia harus
        // ikut terlihat di daftar gudangnya.
        $this->assertSame($this->karawang->id, $sj->warehouse_id);
    }

    public function test_perubahan_nomor_so_meninggalkan_jejak(): void
    {
        $order = $this->pesanan('SO260930');
        $sj = $this->sjYatim('SO260903');
        $user = $this->login();

        $this->post(route('wms.delivery.pair', $sj), ['sales_order_id' => $order->id]);

        $jejak = SoNumberChange::query()->where('sales_order_id', $order->id)->firstOrFail();

        $this->assertSame('SO260930', $jejak->old_number);
        $this->assertSame('SO260903', $jejak->new_number);
        $this->assertSame(SoNumberChange::SOURCE_PAIRING, $jejak->source);
        $this->assertSame($sj->id, $jejak->delivery_note_id);
        $this->assertSame($user->id, $jejak->changed_by);
    }

    public function test_pemasangan_ke_pelanggan_berbeda_ditolak(): void
    {
        $order = $this->pesanan('SO260930');
        $sj = $this->sjYatim('SO260903', $this->tokoLain);

        $this->login();

        $this->post(route('wms.delivery.pair', $sj), ['sales_order_id' => $order->id])
            ->assertSessionHas('error');

        $this->assertSame('SO260930', $order->fresh()->bc_so_number);
        $this->assertNull($sj->fresh()->sales_order_id);
    }

    public function test_nomor_yang_sudah_dipakai_pesanan_lain_ditolak(): void
    {
        $order = $this->pesanan('SO260930');
        $this->pesanan('SO260903', ['status' => SalesOrder::STATUS_PICKING]);
        $sj = $this->sjYatim('SO260903');

        $this->login();

        $this->post(route('wms.delivery.pair', $sj), ['sales_order_id' => $order->id])
            ->assertSessionHas('error');

        $this->assertSame('SO260930', $order->fresh()->bc_so_number);
    }

    public function test_sj_yang_sudah_berpasangan_tidak_bisa_dipasangkan_lagi(): void
    {
        $order = $this->pesanan('SO260930');
        $sj = $this->sjYatim('SO260903');
        $sj->forceFill(['sales_order_id' => $this->pesanan('SO888888')->id])->save();

        $this->login();

        $this->post(route('wms.delivery.pair', $sj), ['sales_order_id' => $order->id])
            ->assertSessionHas('error');
    }

    public function test_pesanan_yang_sudah_berangkat_tidak_menerima_sj_kedua(): void
    {
        $order = $this->pesanan('SO260930', ['status' => SalesOrder::STATUS_SHIPPING]);
        $sj = $this->sjYatim('SO260903');

        $this->login();

        $this->post(route('wms.delivery.pair', $sj), ['sales_order_id' => $order->id])
            ->assertSessionHas('error');
    }

    public function test_kandidat_hanya_pesanan_pelanggan_yang_sama(): void
    {
        $cocok = $this->pesanan('SO260930', ['order_number' => 'PO-COCOK']);
        $this->pesanan('SO260931', [
            'order_number' => 'PO-LAIN',
            'customer_id' => $this->tokoLain->id,
        ]);
        $sj = $this->sjYatim('SO260903');

        $this->login();

        $this->get(route('wms.delivery.show', $sj))
            ->assertOk()
            ->assertSee($cocok->order_number)
            ->assertDontSee('PO-LAIN');
    }

    /* ------------------------------------------------------ Koreksi manual */

    public function test_logistik_membetulkan_nomor_so_sebelum_sj_terbit(): void
    {
        $order = $this->pesanan('SO260930');
        $user = $this->login();

        $this->post(route('wms.approval.so-number', $order), [
            'bc_so_number' => 'SO260903',
            'reason' => 'Salah ketik satu digit saat menerima pesanan.',
        ])->assertSessionHas('success');

        $this->assertSame('SO260903', $order->fresh()->bc_so_number);

        $jejak = SoNumberChange::query()->where('sales_order_id', $order->id)->firstOrFail();
        $this->assertSame(SoNumberChange::SOURCE_MANUAL, $jejak->source);
        $this->assertSame($user->id, $jejak->changed_by);
    }

    public function test_koreksi_manual_menyambungkan_sj_yatim_yang_menunggu(): void
    {
        // SJ-nya sudah terlanjur diimpor dan berkas Excel-nya sudah dibuang.
        // Tanpa penyambungan ulang, Logistik harus mencari berkas itu lagi.
        $order = $this->pesanan('SO260930');
        $sj = $this->sjYatim('SO260903');

        $this->login();

        $this->post(route('wms.approval.so-number', $order), ['bc_so_number' => 'SO260903']);

        $this->assertSame($order->id, $sj->fresh()->sales_order_id);
    }

    public function test_koreksi_manual_ditolak_setelah_barang_berangkat(): void
    {
        $order = $this->pesanan('SO260930', ['status' => SalesOrder::STATUS_SHIPPING]);

        $this->login();

        $this->post(route('wms.approval.so-number', $order), ['bc_so_number' => 'SO260903'])
            ->assertSessionHas('error');

        $this->assertSame('SO260930', $order->fresh()->bc_so_number);
        $this->assertSame(0, SoNumberChange::query()->count());
    }

    public function test_koreksi_manual_menolak_nomor_yang_sudah_dipakai(): void
    {
        $order = $this->pesanan('SO260930');
        $this->pesanan('SO260903', ['status' => SalesOrder::STATUS_PICKING]);

        $this->login();

        $this->post(route('wms.approval.so-number', $order), ['bc_so_number' => 'SO260903'])
            ->assertSessionHas('error');

        $this->assertSame('SO260930', $order->fresh()->bc_so_number);
    }

    public function test_nomor_yang_sama_bukan_perubahan(): void
    {
        $order = $this->pesanan('SO260930');

        $this->login();

        // Kalau ini lolos, riwayatnya terisi baris yang tidak mengubah apa pun
        // dan CHECK so_number_changes_benar_benar_berubah akan meledak.
        $this->post(route('wms.approval.so-number', $order), ['bc_so_number' => 'so260930'])
            ->assertSessionHas('error');

        $this->assertSame(0, SoNumberChange::query()->count());
    }
}
