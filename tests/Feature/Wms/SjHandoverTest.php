<?php

namespace Tests\Feature\Wms;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteHandover;
use App\Models\DeliveryNoteHandoverItem;
use App\Models\Notification;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Serah terima Surat Jalan fisik ke Kantor Pusat.
 *
 * Yang dijaga di sini bukan perhitungan — tidak ada angka stok yang bergerak —
 * melainkan KELENGKAPAN: satu lembar tidak boleh berada di dua amplop, dan
 * lembar yang dilaporkan tidak sampai harus kembali muncul sebagai pekerjaan.
 * Dua hal itulah yang, kalau gagal, membuat kertas menghilang tanpa ada yang
 * pernah tahu.
 */
class SjHandoverTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------ Perkakas */

    private function login(string $slug, ?Warehouse $gudang = null): User
    {
        $user = User::factory()->withRole($slug)->create([
            'warehouse_id' => $gudang?->id,
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

    /** Surat Jalan yang buktinya sudah lolos — satu-satunya yang boleh berangkat. */
    private function sjSiap(?Warehouse $gudang = null): DeliveryNote
    {
        $gudang = $gudang ?? Warehouse::factory()->create();

        $order = SalesOrder::factory()->create([
            'warehouse_id' => $gudang->id,
            'status' => SalesOrder::STATUS_COMPLETED,
            // Dijaga CHECK sales_orders_submitted_at_required: pesanan yang
            // bukan draft harus punya waktu pengajuan.
            'submitted_at' => now()->subDays(3),
        ]);

        return DeliveryNote::factory()->create([
            'sales_order_id' => $order->id,
            'warehouse_id' => $gudang->id,
            'customer_id' => $order->customer_id,
            'status' => DeliveryNote::STATUS_DELIVERED,
            'delivered_at' => now()->subDay(),
            // Dijaga trigger delivery_notes_sampai_wajib_berfoto: tidak ada
            // Surat Jalan yang boleh berstatus sampai tanpa bukti.
            'arrival_photo_path' => 'sampai/'.Str::random(8).'.jpg',
            'arrival_photo_mime' => 'image/jpeg',
            'arrival_photo_size' => 12345,
            'arrival_photo_source' => 'camera',
            'arrival_photo_taken_at' => now()->subDay(),
        ]);
    }

    /* ------------------------------------------------------- Daftar kerjanya */

    public function test_hanya_sj_yang_buktinya_terverifikasi_yang_muncul(): void
    {
        $gudang = Warehouse::factory()->create();
        $siap = $this->sjSiap($gudang);

        // Barangnya sampai, tetapi fotonya belum diperiksa. Lembar aslinya
        // masih harus bisa difoto ulang, jadi belum boleh berangkat ke HO.
        $belumVerifikasi = DeliveryNote::factory()->create([
            'sales_order_id' => SalesOrder::factory()->create([
                'warehouse_id' => $gudang->id,
                'status' => SalesOrder::STATUS_PROOF_UPLOADED,
                'submitted_at' => now()->subDays(3),
            ])->id,
            'warehouse_id' => $gudang->id,
            'status' => DeliveryNote::STATUS_DELIVERED,
            'delivered_at' => now(),
            'arrival_photo_path' => 'sampai/'.Str::random(8).'.jpg',
            'arrival_photo_mime' => 'image/jpeg',
            'arrival_photo_size' => 12345,
            'arrival_photo_source' => 'camera',
            'arrival_photo_taken_at' => now()->subDay(),
        ]);

        $this->login(Role::LOGISTICS);

        $this->get('/wms/outbound/sj-fisik')
            ->assertOk()
            ->assertSee($siap->document_no)
            ->assertDontSee($belumVerifikasi->document_no);
    }

    public function test_sj_yang_sudah_masuk_paket_hilang_dari_daftar(): void
    {
        $sj = $this->sjSiap();
        $this->login(Role::LOGISTICS);

        $this->post('/wms/outbound/sj-fisik', [
            'delivery_note_id' => [$sj->id],
            'carrier_type' => DeliveryNoteHandover::CARRIER_TITIPAN,
            'carrier_name' => 'Pak Budi',
        ])->assertRedirect();

        $this->get('/wms/outbound/sj-fisik')->assertOk()->assertDontSee($sj->document_no);
    }

    /* --------------------------------------------------------- Membuat paket */

    public function test_paket_bernomor_psj_dan_mencatat_isinya(): void
    {
        $satu = $this->sjSiap();
        $dua = $this->sjSiap();
        // Dua Surat Jalan dari gudang berbeda tidak boleh digabung, jadi
        // yang kedua dipindahkan ke gudang yang sama dulu.
        $dua->update(['warehouse_id' => $satu->warehouse_id]);

        $aktor = $this->login(Role::LOGISTICS);

        $this->post('/wms/outbound/sj-fisik', [
            'delivery_note_id' => [$satu->id, $dua->id],
            'carrier_type' => DeliveryNoteHandover::CARRIER_EKSPEDISI,
            'carrier_name' => 'JNE',
            'tracking_no' => 'JNE123456789',
        ])->assertRedirect();

        $paket = DeliveryNoteHandover::firstOrFail();

        $this->assertStringStartsWith('PSJ', $paket->code);
        $this->assertSame(DeliveryNoteHandover::STATUS_SENT, $paket->status);
        $this->assertSame($aktor->id, $paket->sent_by);
        $this->assertSame('JNE123456789', $paket->tracking_no);
        $this->assertSame(2, $paket->items()->count());
    }

    public function test_satu_sj_tidak_bisa_masuk_dua_paket(): void
    {
        $sj = $this->sjSiap();
        $this->login(Role::LOGISTICS);

        $kirim = fn () => $this->post('/wms/outbound/sj-fisik', [
            'delivery_note_id' => [$sj->id],
            'carrier_type' => DeliveryNoteHandover::CARRIER_TITIPAN,
            'carrier_name' => 'Pak Budi',
        ]);

        $kirim()->assertRedirect();
        $kirim()->assertSessionHas('error');

        $this->assertSame(1, DeliveryNoteHandover::count());
    }

    public function test_sj_dari_gudang_berbeda_tidak_bisa_digabung(): void
    {
        $karawang = $this->sjSiap();
        $pekanbaru = $this->sjSiap();

        $this->login(Role::LOGISTICS);

        $this->post('/wms/outbound/sj-fisik', [
            'delivery_note_id' => [$karawang->id, $pekanbaru->id],
            'carrier_type' => DeliveryNoteHandover::CARRIER_TITIPAN,
            'carrier_name' => 'Pak Budi',
        ])->assertSessionHas('error');

        $this->assertSame(0, DeliveryNoteHandover::count());
    }

    public function test_kiriman_ekspedisi_wajib_bernomor_resi(): void
    {
        $sj = $this->sjSiap();
        $this->login(Role::LOGISTICS);

        $this->post('/wms/outbound/sj-fisik', [
            'delivery_note_id' => [$sj->id],
            'carrier_type' => DeliveryNoteHandover::CARRIER_EKSPEDISI,
            'carrier_name' => 'JNE',
        ])->assertSessionHasErrors('tracking_no');
    }

    public function test_ca_dikabari_saat_paket_berangkat(): void
    {
        $ca = User::factory()->withRole(Role::CUSTOMER_ACCOUNT)->create(['warehouse_id' => null]);
        $sj = $this->sjSiap();

        $this->login(Role::LOGISTICS);

        $this->post('/wms/outbound/sj-fisik', [
            'delivery_note_id' => [$sj->id],
            'carrier_type' => DeliveryNoteHandover::CARRIER_TITIPAN,
            'carrier_name' => 'Pak Budi',
        ])->assertRedirect();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $ca->id,
            'type' => Notification::SJ_HANDOVER_SENT,
        ]);
    }

    /* ------------------------------------------------------------ Batas izin */

    public function test_logistik_tidak_boleh_membuka_halaman_kantor_pusat(): void
    {
        $this->login(Role::LOGISTICS);

        $this->get('/wms/outbound/sj-fisik/masuk')->assertForbidden();
    }

    public function test_ca_tidak_boleh_membuat_paket(): void
    {
        $sj = $this->sjSiap();
        $this->login(Role::CUSTOMER_ACCOUNT);

        $this->get('/wms/outbound/sj-fisik')->assertForbidden();

        $this->post('/wms/outbound/sj-fisik', [
            'delivery_note_id' => [$sj->id],
            'carrier_type' => DeliveryNoteHandover::CARRIER_TITIPAN,
            'carrier_name' => 'Pak Budi',
        ])->assertForbidden();
    }

    public function test_ca_melihat_paket_yang_menunggu(): void
    {
        $paket = $this->paketTerkirim();

        $this->login(Role::CUSTOMER_ACCOUNT);

        $this->get('/wms/outbound/sj-fisik/masuk')
            ->assertOk()
            ->assertSee($paket->code);
    }

    /* -------------------------------------------------------- Konfirmasi HO */

    public function test_semua_sesuai_menutup_paket(): void
    {
        $paket = $this->paketTerkirim();
        $item = $paket->items()->firstOrFail();

        $ca = $this->login(Role::CUSTOMER_ACCOUNT);

        $this->post('/wms/outbound/sj-fisik/masuk/'.$paket->id.'/konfirmasi', [
            'periksa' => [$item->id => ['status' => DeliveryNoteHandoverItem::CHECK_OK]],
        ])->assertRedirect();

        $paket->refresh();

        $this->assertSame(DeliveryNoteHandover::STATUS_RECEIVED, $paket->status);
        $this->assertSame($ca->id, $paket->received_by);
        $this->assertNotNull($paket->received_at);
        $this->assertNull($item->refresh()->released_at);
    }

    /**
     * Lembar yang tidak ada di amplop KEMBALI jadi pekerjaan.
     *
     * Inilah alasan pilihan ketiga ada. Kalau barisnya hanya diberi catatan
     * seperti "bermasalah", kertasnya tercatat sudah dikirim selamanya dan
     * tidak ada layar mana pun yang menampilkannya lagi.
     */
    public function test_lembar_yang_tidak_ada_kembali_ke_daftar_kirim(): void
    {
        $paket = $this->paketTerkirim();
        $item = $paket->items()->firstOrFail();
        $sj = $item->deliveryNote;

        $this->login(Role::CUSTOMER_ACCOUNT);

        $this->post('/wms/outbound/sj-fisik/masuk/'.$paket->id.'/konfirmasi', [
            'periksa' => [$item->id => [
                'status' => DeliveryNoteHandoverItem::CHECK_MISSING,
                'note' => 'tidak ketemu di dalam amplop',
            ]],
        ])->assertRedirect();

        $this->assertNotNull($item->refresh()->released_at);
        $this->assertSame(DeliveryNoteHandoverItem::RELEASED_MISSING, $item->released_reason);

        // Dan ia benar-benar muncul lagi sebagai pekerjaan gudang.
        $this->login(Role::LOGISTICS);
        $this->get('/wms/outbound/sj-fisik')->assertOk()->assertSee($sj->document_no);
    }

    public function test_lembar_bermasalah_tidak_dikembalikan(): void
    {
        $paket = $this->paketTerkirim();
        $item = $paket->items()->firstOrFail();

        $this->login(Role::CUSTOMER_ACCOUNT);

        $this->post('/wms/outbound/sj-fisik/masuk/'.$paket->id.'/konfirmasi', [
            'periksa' => [$item->id => [
                'status' => DeliveryNoteHandoverItem::CHECK_ISSUE,
                'note' => 'tanda tangan pelanggan tidak terbaca',
            ]],
        ])->assertRedirect();

        // Lembarnya ada di HO — hanya isinya yang kurang. Tidak ada yang bisa
        // dikirim ulang, jadi ia tidak boleh kembali jadi pekerjaan gudang.
        $this->assertNull($item->refresh()->released_at);
    }

    public function test_alasan_wajib_untuk_yang_tidak_sesuai(): void
    {
        $paket = $this->paketTerkirim();
        $item = $paket->items()->firstOrFail();

        $this->login(Role::CUSTOMER_ACCOUNT);

        $this->post('/wms/outbound/sj-fisik/masuk/'.$paket->id.'/konfirmasi', [
            'periksa' => [$item->id => ['status' => DeliveryNoteHandoverItem::CHECK_MISSING]],
        ])->assertSessionHasErrors('periksa.'.$item->id.'.note');

        $this->assertSame(DeliveryNoteHandover::STATUS_SENT, $paket->refresh()->status);
    }

    public function test_paket_tidak_bisa_ditutup_kalau_ada_yang_belum_diperiksa(): void
    {
        $paket = $this->paketTerkirim(2);
        $pertama = $paket->items()->firstOrFail();

        $this->login(Role::CUSTOMER_ACCOUNT);

        $this->post('/wms/outbound/sj-fisik/masuk/'.$paket->id.'/konfirmasi', [
            'periksa' => [$pertama->id => ['status' => DeliveryNoteHandoverItem::CHECK_OK]],
        ])->assertSessionHas('error');

        $this->assertSame(DeliveryNoteHandover::STATUS_SENT, $paket->refresh()->status);
    }

    /* ------------------------------------------------------------ Pembatalan */

    public function test_paket_dibatalkan_mengembalikan_isinya(): void
    {
        $paket = $this->paketTerkirim();
        $sj = $paket->items()->firstOrFail()->deliveryNote;

        $this->login(Role::LOGISTICS);

        $this->post('/wms/outbound/sj-fisik/'.$paket->id.'/batal', [
            'reason' => 'salah pilih, lembarnya belum ada di tangan',
        ])->assertRedirect();

        $this->assertSame(DeliveryNoteHandover::STATUS_CANCELLED, $paket->refresh()->status);
        $this->get('/wms/outbound/sj-fisik')->assertOk()->assertSee($sj->document_no);
    }

    public function test_paket_yang_sudah_diterima_tidak_bisa_dibatalkan(): void
    {
        $paket = $this->paketTerkirim();
        $item = $paket->items()->firstOrFail();

        $this->login(Role::CUSTOMER_ACCOUNT);
        $this->post('/wms/outbound/sj-fisik/masuk/'.$paket->id.'/konfirmasi', [
            'periksa' => [$item->id => ['status' => DeliveryNoteHandoverItem::CHECK_OK]],
        ])->assertRedirect();

        $this->login(Role::LOGISTICS);
        $this->post('/wms/outbound/sj-fisik/'.$paket->id.'/batal', [
            'reason' => 'mau dibatalkan padahal sudah sampai',
        ])->assertSessionHas('error');

        $this->assertSame(DeliveryNoteHandover::STATUS_RECEIVED, $paket->refresh()->status);
    }

    /* --------------------------------------------------------------- Cetak */

    public function test_lembar_serah_terima_memuat_nomor_dan_isinya(): void
    {
        $paket = $this->paketTerkirim();
        $sj = $paket->items()->firstOrFail()->deliveryNote;

        $this->login(Role::LOGISTICS);

        $this->get('/wms/outbound/sj-fisik/'.$paket->id.'/cetak')
            ->assertOk()
            ->assertSee($paket->code)
            ->assertSee($sj->document_no)
            ->assertSee('LEMBAR SERAH TERIMA SURAT JALAN');
    }

    /* ------------------------------------------------------------ Perkakas */

    /** Satu paket yang sudah berangkat, berisi $jumlah Surat Jalan. */
    private function paketTerkirim(int $jumlah = 1): DeliveryNoteHandover
    {
        $gudang = Warehouse::factory()->create();
        $ids = [];

        for ($i = 0; $i < $jumlah; $i++) {
            $ids[] = $this->sjSiap($gudang)->id;
        }

        $this->login(Role::LOGISTICS);

        $this->post('/wms/outbound/sj-fisik', [
            'delivery_note_id' => $ids,
            'carrier_type' => DeliveryNoteHandover::CARRIER_TITIPAN,
            'carrier_name' => 'Pak Budi',
        ])->assertRedirect();

        return DeliveryNoteHandover::latest('id')->firstOrFail();
    }
}
