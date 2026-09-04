<?php

namespace Tests\Feature\Wms;

use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DeliveryProof;
use App\Models\PaymentTerm;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Bukti Surat Jalan bertanda tangan — PRD §6.5 F-OUT-05 & F-OUT-06.
 *
 * LIMA HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * --------------------------------------------------
 * 1. KUOTA 3 FOTO DIHITUNG DARI YANG MASIH BERLAKU. Kalau foto yang ditolak
 *    ikut dihitung, Sales yang tiga kali salah potret terkunci selamanya dan
 *    pesanannya tidak akan pernah bisa diselesaikan.
 * 2. TERMIN TEMPO TIDAK BERHENTI DI 'COMPLETE'. Menyamakan keduanya membuat
 *    piutang lenyap dari layar Billing begitu barang sampai.
 * 3. PENOLAKAN TIDAK MENGHAPUS FOTO. Justru foto yang pernah ditolak yang
 *    menjelaskan kenapa prosesnya berputar.
 * 4. SATU STATUS, DUA ANTREAN. Pemilik produk memilih tidak menambah status
 *    baru; yang membedakan "belum ada foto" dari "foto menunggu diperiksa"
 *    adalah isi tabel bukti, bukan kolom status.
 * 5. FOTONYA PRIVAT. Di dalamnya ada tanda tangan, nama, dan alamat
 *    pelanggan — disk publik berarti siapa pun yang menebak namanya bisa
 *    mengunduhnya tanpa login.
 */
class ProofOfDeliveryTest extends TestCase
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

        $this->karawang = Warehouse::factory()->create(['code' => 'WH-01', 'name' => 'Karawang']);
        $this->pekanbaru = Warehouse::factory()->create(['code' => 'WH-02', 'name' => 'Pekanbaru']);
        $this->customer = Customer::factory()->create(['code' => 'IDR13302', 'is_active' => true]);

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

    private function login(?Warehouse $gudang, string $slug = Role::LOGISTICS): User
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

    private function pesananTerkirim(?User $sales = null, ?PaymentTerm $term = null, array $ubah = []): SalesOrder
    {
        $sales ??= User::factory()->withRole(Role::SALES)->create(['warehouse_id' => $this->karawang->id]);

        return SalesOrder::factory()->create(array_merge([
            'user_id' => $sales->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->karawang->id,
            'payment_term_id' => ($term ?? $this->cash)->id,
            'status' => SalesOrder::STATUS_PROOF_UPLOADED,
            'bc_so_number' => 'SO260903',
            'submitted_at' => now()->subDays(2),
            'approved_at' => now()->subDays(2),
            'shipped_at' => now()->subHours(6),
            'delivered_at' => now()->subHours(2),
        ], $ubah));
    }

    private function foto(string $nama = 'sj.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($nama, 800, 600);
    }

    private function unggah(SalesOrder $order, array $berkas)
    {
        return $this->post(route('sales.proofs.store', $order), ['photos' => $berkas]);
    }

    /* ------------------------------------------------------ Unggah (Sales) */

    public function test_sales_mengunggah_foto_surat_jalan(): void
    {
        $sales = $this->login($this->karawang, Role::SALES);
        $order = $this->pesananTerkirim($sales, null, ['status' => SalesOrder::STATUS_SHIPPING]);

        $this->unggah($order, [$this->foto(), $this->foto('sj-2.jpg')])
            ->assertSessionHas('success');

        $this->assertSame(2, $order->proofs()->count());
        $this->assertSame(
            SalesOrder::STATUS_PROOF_UPLOADED,
            $order->fresh()->status,
            'Unggahan pertama harus memindahkan pesanan ke antrean verifikasi.'
        );

        // Disk PRIVAT. Kalau ini pindah ke 'public', foto tanda tangan
        // pelanggan bisa diunduh siapa pun yang menebak nama berkasnya.
        Storage::disk('local')->assertExists($order->proofs()->first()->path);
    }

    public function test_pesanan_sales_lain_dijawab_404(): void
    {
        $pemilik = User::factory()->withRole(Role::SALES)->create(['warehouse_id' => $this->karawang->id]);
        $order = $this->pesananTerkirim($pemilik);

        $this->login($this->karawang, Role::SALES);

        // 404, BUKAN 403: 403 mengakui bahwa pesanan itu ada.
        $this->unggah($order, [$this->foto()])->assertNotFound();
    }

    public function test_berkas_yang_bukan_gambar_ditolak(): void
    {
        $sales = $this->login($this->karawang, Role::SALES);
        $order = $this->pesananTerkirim($sales);

        // Ekstensi .jpg tetapi isinya bukan gambar — persis yang lolos kalau
        // hanya `mimes` yang dipakai tanpa `mimetypes`.
        $this->unggah($order, [UploadedFile::fake()->create('virus.jpg', 20, 'application/x-msdownload')])
            ->assertSessionHasErrors('photos.0');

        $this->assertSame(0, $order->proofs()->count());
    }

    public function test_foto_terlalu_besar_ditolak(): void
    {
        $sales = $this->login($this->karawang, Role::SALES);
        $order = $this->pesananTerkirim($sales);

        $this->unggah($order, [UploadedFile::fake()->create('besar.jpg', 6000, 'image/jpeg')])
            ->assertSessionHasErrors('photos.0');
    }

    public function test_kuota_tiga_foto_ditegakkan(): void
    {
        $sales = $this->login($this->karawang, Role::SALES);
        $order = $this->pesananTerkirim($sales);

        DeliveryProof::factory()->count(3)->create([
            'sales_order_id' => $order->id,
            'uploaded_by' => $sales->id,
        ]);

        $this->unggah($order, [$this->foto()])->assertSessionHas('error');

        $this->assertSame(3, $order->proofs()->count());
    }

    public function test_foto_yang_ditolak_tidak_menghabiskan_kuota(): void
    {
        $sales = $this->login($this->karawang, Role::SALES);
        $order = $this->pesananTerkirim($sales);
        $logistik = User::factory()->withRole(Role::LOGISTICS)->create(['warehouse_id' => $this->karawang->id]);

        // Sudah tiga kali salah potret. Kalau yang ditolak ikut dihitung,
        // Sales terkunci selamanya dan pesanannya tidak akan pernah selesai.
        DeliveryProof::factory()->count(3)->rejected($logistik->id)->create([
            'sales_order_id' => $order->id,
            'uploaded_by' => $sales->id,
        ]);

        $this->unggah($order, [$this->foto()])->assertSessionHas('success');

        $this->assertSame(1, $order->proofs()->menunggu()->count());
    }

    public function test_bukti_belum_bisa_diunggah_sebelum_barang_berangkat(): void
    {
        $sales = $this->login($this->karawang, Role::SALES);
        $order = $this->pesananTerkirim($sales, null, [
            'status' => SalesOrder::STATUS_READY_TO_SHIP,
            'shipped_at' => null,
            'delivered_at' => null,
        ]);

        $this->unggah($order, [$this->foto()])->assertSessionHas('error');

        $this->assertSame(0, $order->proofs()->count());
    }

    public function test_foto_hanya_bisa_dilihat_pemiliknya(): void
    {
        $pemilik = User::factory()->withRole(Role::SALES)->create(['warehouse_id' => $this->karawang->id]);
        $order = $this->pesananTerkirim($pemilik);
        $foto = DeliveryProof::factory()->create([
            'sales_order_id' => $order->id,
            'uploaded_by' => $pemilik->id,
        ]);

        $this->login($this->karawang, Role::SALES);

        $this->get(route('sales.proofs.preview', $foto))->assertNotFound();
    }

    /* ------------------------------------------------ Verifikasi (Logistik) */

    public function test_logistik_menyelesaikan_pesanan_bayar_di_muka(): void
    {
        $this->login($this->karawang);
        $order = $this->pesananTerkirim(null, $this->cash);
        $foto = DeliveryProof::factory()->create([
            'sales_order_id' => $order->id,
            'uploaded_by' => User::factory()->withRole(Role::SALES)->create()->id,
        ]);

        $this->post(route('wms.verification.complete', $order))->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(SalesOrder::STATUS_COMPLETED, $order->status);
        $this->assertNotNull($order->completed_at);
        $this->assertSame(DeliveryProof::STATUS_VERIFIED, $foto->fresh()->status);
    }

    public function test_termin_tempo_selesai_tapi_tetap_ditagih(): void
    {
        $this->login($this->karawang);
        $order = $this->pesananTerkirim(null, $this->tempo);
        DeliveryProof::factory()->create([
            'sales_order_id' => $order->id,
            'uploaded_by' => User::factory()->withRole(Role::SALES)->create()->id,
        ]);

        $this->post(route('wms.verification.complete', $order));

        // Kalau ini jadi STATUS_COMPLETED, piutangnya lenyap dari Billing
        // begitu barang sampai.
        $this->assertSame(SalesOrder::STATUS_COMPLETED_BILLING, $order->fresh()->status);
    }

    public function test_sla_dihitung_dari_berangkat_sampai_tiba(): void
    {
        $this->login($this->karawang);
        $order = $this->pesananTerkirim(null, null, [
            'shipped_at' => now()->subHours(9),
            'delivered_at' => now()->subHours(4),
        ]);
        DeliveryProof::factory()->create([
            'sales_order_id' => $order->id,
            'uploaded_by' => User::factory()->withRole(Role::SALES)->create()->id,
        ]);

        $this->post(route('wms.verification.complete', $order));

        // 5 jam, BUKAN jarak sampai verifikasi: Sales bisa terlambat
        // berhari-hari ke toko, dan itu bukan pekerjaan gudang.
        $this->assertSame(5.0, (float) $order->fresh()->sla_hours);
    }

    public function test_pesanan_tanpa_foto_tidak_bisa_diselesaikan(): void
    {
        $this->login($this->karawang);
        $order = $this->pesananTerkirim();

        $this->post(route('wms.verification.complete', $order))->assertSessionHas('error');

        $this->assertSame(SalesOrder::STATUS_PROOF_UPLOADED, $order->fresh()->status);
    }

    public function test_pesanan_selesai_tidak_bisa_diselesaikan_dua_kali(): void
    {
        $this->login($this->karawang);
        $order = $this->pesananTerkirim();
        DeliveryProof::factory()->create([
            'sales_order_id' => $order->id,
            'uploaded_by' => User::factory()->withRole(Role::SALES)->create()->id,
        ]);

        $this->post(route('wms.verification.complete', $order));
        $waktuPertama = $order->fresh()->completed_at;

        $this->post(route('wms.verification.complete', $order->fresh()))->assertSessionHas('error');

        $this->assertEquals($waktuPertama, $order->fresh()->completed_at);
    }

    public function test_penolakan_mengembalikan_giliran_ke_sales_tanpa_menghapus_foto(): void
    {
        $this->login($this->karawang);
        $order = $this->pesananTerkirim();
        $foto = DeliveryProof::factory()->create([
            'sales_order_id' => $order->id,
            'uploaded_by' => User::factory()->withRole(Role::SALES)->create()->id,
        ]);

        $this->post(route('wms.verification.reject', $order), [
            'reason' => 'Tanda tangan pelanggan tidak terlihat pada foto.',
        ])->assertSessionHas('warning');

        $foto->refresh();
        $this->assertSame(DeliveryProof::STATUS_REJECTED, $foto->status);
        $this->assertNotNull($foto->rejection_reason);

        // Barisnya TETAP ADA: riwayat "pernah diunggah sesuatu yang salah"
        // justru itu yang dicari kalau nanti ada perselisihan.
        $this->assertSame(1, $order->proofs()->count());

        // Status pesanan sengaja tidak berubah — pemilik produk menolak
        // status tambahan supaya tampilan di HP Sales tetap satu label.
        $this->assertSame(SalesOrder::STATUS_PROOF_UPLOADED, $order->fresh()->status);
    }

    public function test_penolakan_tanpa_alasan_ditolak(): void
    {
        $this->login($this->karawang);
        $order = $this->pesananTerkirim();
        DeliveryProof::factory()->create([
            'sales_order_id' => $order->id,
            'uploaded_by' => User::factory()->withRole(Role::SALES)->create()->id,
        ]);

        $this->post(route('wms.verification.reject', $order), ['reason' => 'salah'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(DeliveryProof::STATUS_PENDING, $order->proofs()->first()->status);
    }

    public function test_pesanan_gudang_lain_ditolak_403(): void
    {
        $this->login($this->pekanbaru);
        $order = $this->pesananTerkirim();
        DeliveryProof::factory()->create([
            'sales_order_id' => $order->id,
            'uploaded_by' => User::factory()->withRole(Role::SALES)->create()->id,
        ]);

        $this->get(route('wms.verification.show', $order))->assertForbidden();
        $this->post(route('wms.verification.complete', $order))->assertForbidden();

        // Muatan sengaja dibuat cacat: kalau otorisasi diperiksa SESUDAH
        // validasi, jawabannya jadi 422 dan itu mengakui pesanannya ada.
        $this->post(route('wms.verification.reject', $order), ['reason' => 'x'])->assertForbidden();
    }

    /* --------------------------------------------------- Pembagian antrean */

    public function test_antrean_dibagi_menurut_ada_tidaknya_foto_bukan_status(): void
    {
        $logistik = $this->login($this->karawang);
        $sales = User::factory()->withRole(Role::SALES)->create();

        $perluDiperiksa = $this->pesananTerkirim(null, null, ['order_number' => 'SO-PERIKSA', 'bc_so_number' => 'SO111111']);
        DeliveryProof::factory()->create(['sales_order_id' => $perluDiperiksa->id, 'uploaded_by' => $sales->id]);

        $belumAdaFoto = $this->pesananTerkirim(null, null, ['order_number' => 'SO-BELUM', 'bc_so_number' => 'SO222222']);

        $pernahDitolak = $this->pesananTerkirim(null, null, ['order_number' => 'SO-DITOLAK', 'bc_so_number' => 'SO333333']);
        DeliveryProof::factory()->rejected($logistik->id)->create([
            'sales_order_id' => $pernahDitolak->id, 'uploaded_by' => $sales->id,
        ]);

        // Ketiganya BERSTATUS SAMA. Kalau tab disaring dengan status, ketiganya
        // akan muncul di tab yang sama dan antrean Logistik jadi tak terpakai.
        $this->get(route('wms.verification.index', ['tab' => 'perlu-diperiksa']))
            ->assertOk()
            ->assertSee('SO-PERIKSA')
            ->assertDontSee('SO-BELUM')
            ->assertDontSee('SO-DITOLAK');

        $this->get(route('wms.verification.index', ['tab' => 'menunggu-bukti']))
            ->assertOk()
            ->assertSee('SO-BELUM')
            ->assertSee('SO-DITOLAK')
            ->assertDontSee('SO-PERIKSA');
    }

    public function test_riwayat_hanya_berisi_yang_sudah_selesai(): void
    {
        $this->login($this->karawang);

        $this->pesananTerkirim(null, null, ['order_number' => 'SO-JALAN', 'bc_so_number' => 'SO444444']);
        $this->pesananTerkirim(null, null, [
            'order_number' => 'SO-BERES',
            'bc_so_number' => 'SO555555',
            'status' => SalesOrder::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $this->get(route('wms.verification.index', ['tab' => 'riwayat']))
            ->assertOk()
            ->assertSee('SO-BERES')
            ->assertDontSee('SO-JALAN');
    }

    public function test_halaman_verifikasi_menautkan_ke_rinciannya(): void
    {
        $this->login($this->karawang);
        $order = $this->pesananTerkirim();
        DeliveryProof::factory()->create([
            'sales_order_id' => $order->id,
            'uploaded_by' => User::factory()->withRole(Role::SALES)->create()->id,
        ]);

        $this->get(route('wms.verification.index'))
            ->assertOk()
            ->assertSee(route('wms.verification.show', $order), false);
    }

    public function test_pesanan_yang_belum_berpasangan_surat_jalan_tetap_bisa_dibuktikan(): void
    {
        // Nomor SO di BC berbeda, jadi tidak ada SJ yang terpasang. Buktinya
        // tetap harus bisa diunggah — kalau tidak, pesanan yang barangnya
        // sudah sampai tidak akan pernah bisa ditutup.
        $sales = $this->login($this->karawang, Role::SALES);
        $order = $this->pesananTerkirim($sales);

        $this->assertSame(0, DeliveryNote::query()->count());

        $this->unggah($order, [$this->foto()])->assertSessionHas('success');

        $this->assertNull($order->proofs()->first()->delivery_note_id);
    }
}
