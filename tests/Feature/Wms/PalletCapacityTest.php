<?php

namespace Tests\Feature\Wms;

use App\Models\ActivityLog;
use App\Models\PalletCapacityRule;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Support\PalletCapacity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Kapasitas palet sebagai SETELAN, bukan angka yang tertanam di kode.
 *
 * EMPAT HAL YANG KALAU SALAH TIDAK LANGSUNG TERLIHAT
 * --------------------------------------------------
 * 1. MENGUBAH ATURAN HARUS BENAR-BENAR SAMPAI KE PRODUKNYA. Dulu tiap produk
 *    memegang SALINAN hasil aturan yang diambil saat ia disimpan, jadi
 *    mengubah aturan tidak mengubah apa pun — dan setelan ini jadi layar yang
 *    terlihat bekerja padahal tidak.
 * 2. ANGKA KHUSUS PER PRODUK TETAP MENANG. Ia keputusan seseorang, bukan
 *    salinan, dan tidak boleh tersapu perubahan aturan.
 * 3. ATURAN YANG MENYEBUT WADAH MENGALAHKAN YANG TIDAK. "20 L PAIL" dan
 *    "20 L TIN" dua wadah yang tidak menumpuk sama di atas palet.
 * 4. UKURAN TANPA ATURAN TETAP MENGEMBALIKAN NULL, bukan angka terdekat.
 *    Salah menghitung kapasitas berarti salah membentuk palet di lantai
 *    gudang — lebih baik ditolak daripada ditebak.
 */
class PalletCapacityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Aturannya di-cache selamanya; tanpa ini satu test membaca aturan
        // yang ditulis test sebelumnya.
        PalletCapacity::lupakan();
    }

    private function login(string $slug = Role::SUPER_ADMIN): User
    {
        $user = User::factory()->withRole($slug)->create([
            'warehouse_id' => $slug === Role::SUPER_ADMIN ? null : null,
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
        $this->withCredentials();
        $this->actingAs($user);

        return $user;
    }

    private function produk(array $atribut = []): Product
    {
        return Product::factory()->create(array_merge([
            'pack_unit' => 'KG',
            'pack_size' => 20,
            'uom' => 'PAIL',
            'max_qty_per_pallet' => null,
        ], $atribut));
    }

    private function aturan(array $atribut = []): PalletCapacityRule
    {
        PalletCapacity::lupakan();

        return PalletCapacityRule::create(array_merge([
            'pack_unit' => 'KG',
            'pack_size' => '20.000',
            'uom' => null,
            'max_qty_per_pallet' => 36,
        ], $atribut));
    }

    /* ------------------------------------------------------------- Akses */

    public function test_hanya_super_admin_yang_bisa_membuka_kapasitas_palet(): void
    {
        foreach ([Role::MANAGER, Role::LOGISTICS, Role::WAREHOUSE_OPERATOR] as $slug) {
            $this->login($slug);
            $this->get(route('wms.admin.pallet-capacity'))->assertForbidden();
        }

        $this->login();
        $this->get(route('wms.admin.pallet-capacity'))->assertOk();
    }

    /* ------------------------------------------------ Aturannya berlaku */

    /** Aturan bawaan dari migrasi tetap menjawab seperti sebelum ada layar ini. */
    public function test_aturan_bawaan_gudang_tetap_berlaku(): void
    {
        // 20 Liter memuat 27 pcs sementara 20 Kg memuat 36 — satuannya yang
        // menentukan, bukan cuma angkanya.
        $this->assertSame(27, PalletCapacity::resolve('L', 20));
        $this->assertSame(36, PalletCapacity::resolve('KG', 20));
    }

    /**
     * INTI GUNANYA SETELAN INI: satu angka menutup seluruh produk seukuran.
     *
     * Dulu tiap produk memegang salinan hasil aturan yang diambil saat ia
     * disimpan, jadi mengubah aturan tidak mengubah apa pun.
     */
    public function test_mengubah_aturan_langsung_sampai_ke_seluruh_produk_seukuran(): void
    {
        $this->login();

        $a = $this->produk();
        $b = $this->produk(['pack_size' => 20, 'pack_unit' => 'KG']);

        $this->assertSame(36, $a->kapasitasPalet());

        $aturan = PalletCapacityRule::where('pack_unit', 'KG')->where('pack_size', '20.000')->firstOrFail();

        $this->put(route('wms.admin.pallet-capacity.update', $aturan), [
            'max_qty_per_pallet' => 40,
        ])->assertSessionHasNoErrors();

        $this->assertSame(40, $a->fresh()->kapasitasPalet());
        $this->assertSame(40, $b->fresh()->kapasitasPalet());
    }

    /** Ukuran baru cukup ditambahkan sekali; produknya tidak perlu disentuh. */
    public function test_ukuran_baru_cukup_ditambahkan_sekali(): void
    {
        $this->login();

        $produk = $this->produk(['pack_unit' => 'KG', 'pack_size' => 16, 'uom' => 'PAIL']);

        $this->assertNull($produk->kapasitasPalet(), 'Belum ada aturannya.');
        $this->assertTrue($produk->needsPalletCapacity());

        $this->post(route('wms.admin.pallet-capacity.store'), [
            'pack_unit' => 'KG',
            'pack_size' => '16',
            'max_qty_per_pallet' => 48,
        ])->assertSessionHasNoErrors();

        $this->assertSame(48, $produk->fresh()->kapasitasPalet());
        $this->assertFalse($produk->fresh()->needsPalletCapacity());
    }

    /**
     * ANGKA KHUSUS PER PRODUK MENANG, dan tidak tersapu perubahan aturan.
     *
     * Ia keputusan seseorang — bukan salinan hasil hitungan.
     */
    public function test_angka_khusus_produk_mengalahkan_aturannya(): void
    {
        $this->login();

        $khusus = $this->produk(['max_qty_per_pallet' => 30]);
        $ikutAturan = $this->produk();

        $this->assertSame(30, $khusus->kapasitasPalet());
        $this->assertSame(36, $ikutAturan->kapasitasPalet());

        $aturan = PalletCapacityRule::where('pack_unit', 'KG')->where('pack_size', '20.000')->firstOrFail();

        $this->put(route('wms.admin.pallet-capacity.update', $aturan), [
            'max_qty_per_pallet' => 40,
        ])->assertSessionHasNoErrors();

        $this->assertSame(30, $khusus->fresh()->kapasitasPalet(), 'Pengecualian tidak ikut berubah.');
        $this->assertSame(40, $ikutAturan->fresh()->kapasitasPalet());
    }

    /* ------------------------------------------------- Wadah jadi kunci */

    /**
     * "20 L PAIL" boleh berbeda dari "20 L TIN".
     *
     * Di data nyata keduanya memang ada, dan dua wadah itu tidak menumpuk sama
     * di atas palet.
     */
    public function test_aturan_yang_menyebut_wadah_mengalahkan_yang_umum(): void
    {
        $this->aturan(['pack_unit' => 'L', 'pack_size' => '20.000', 'uom' => 'TIN', 'max_qty_per_pallet' => 60]);

        $pail = $this->produk(['pack_unit' => 'L', 'pack_size' => 20, 'uom' => 'PAIL']);
        $tin = $this->produk(['pack_unit' => 'L', 'pack_size' => 20, 'uom' => 'TIN']);

        $this->assertSame(27, $pail->kapasitasPalet(), 'Ikut aturan umum 20 L.');
        $this->assertSame(60, $tin->kapasitasPalet(), 'Aturan khusus TIN menang.');
    }

    /** Wadah disimpan huruf besar supaya "pail" dan "PAIL" bukan dua aturan. */
    public function test_wadah_disimpan_huruf_besar(): void
    {
        $this->login();

        $this->post(route('wms.admin.pallet-capacity.store'), [
            'pack_unit' => 'kg',
            'pack_size' => '16',
            'uom' => 'pail',
            'max_qty_per_pallet' => 48,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('pallet_capacity_rules', [
            'pack_unit' => 'KG',
            'uom' => 'PAIL',
            'max_qty_per_pallet' => 48,
        ]);

        $this->assertSame(48, $this->produk(['pack_size' => 16, 'uom' => 'pail'])->kapasitasPalet());
    }

    /* ------------------------------------------------------ Pagar aturan */

    public function test_aturan_kembar_ditolak(): void
    {
        $this->login();

        $this->post(route('wms.admin.pallet-capacity.store'), [
            'pack_unit' => 'KG',
            'pack_size' => '20',
            'max_qty_per_pallet' => 99,
        ])->assertSessionHas('error');

        $this->assertSame(1, PalletCapacityRule::where('pack_unit', 'KG')
            ->where('pack_size', '20.000')->whereNull('uom')->count());
    }

    public function test_ukuran_nol_atau_kapasitas_nol_ditolak(): void
    {
        $this->login();

        $this->post(route('wms.admin.pallet-capacity.store'), [
            'pack_unit' => 'KG', 'pack_size' => '0', 'max_qty_per_pallet' => 10,
        ])->assertSessionHasErrors('pack_size');

        $this->post(route('wms.admin.pallet-capacity.store'), [
            'pack_unit' => 'KG', 'pack_size' => '16', 'max_qty_per_pallet' => 0,
        ])->assertSessionHasErrors('max_qty_per_pallet');
    }

    public function test_satuan_selain_l_dan_kg_ditolak(): void
    {
        $this->login();

        $this->post(route('wms.admin.pallet-capacity.store'), [
            'pack_unit' => 'PCS', 'pack_size' => '16', 'max_qty_per_pallet' => 10,
        ])->assertSessionHasErrors('pack_unit');
    }

    /**
     * Ukuran dan wadah sebuah aturan TIDAK bisa diubah — hanya angkanya.
     *
     * Mengubahnya berarti aturan ini diam-diam berpindah menutupi kelompok
     * produk yang berbeda, sementara kelompok lamanya kehilangan aturannya
     * tanpa ada yang menyadarinya.
     */
    public function test_ukuran_aturan_tidak_bisa_digeser_lewat_ubah(): void
    {
        $this->login();

        $aturan = PalletCapacityRule::where('pack_unit', 'KG')->where('pack_size', '20.000')->firstOrFail();

        $this->put(route('wms.admin.pallet-capacity.update', $aturan), [
            'pack_unit' => 'L',
            'pack_size' => '99',
            'uom' => 'DRM',
            'max_qty_per_pallet' => 40,
        ])->assertSessionHasNoErrors();

        $aturan->refresh();

        $this->assertSame('KG', $aturan->pack_unit);
        $this->assertSame('20.000', $aturan->pack_size);
        $this->assertNull($aturan->uom);
        $this->assertSame(40, $aturan->max_qty_per_pallet, 'Angkanya tetap boleh berubah.');
    }

    /* --------------------------------------------------------- Penghapusan */

    /**
     * Menghapus aturan MENYEBUT berapa produk yang kehilangan kapasitasnya.
     *
     * Produk yang kehilangan aturannya berhenti bisa dipecah jadi palet, dan
     * gejalanya baru muncul di layar penerimaan barang berikutnya.
     */
    public function test_menghapus_aturan_memperingatkan_produk_yang_terdampak(): void
    {
        $this->login();

        $produk = $this->produk();
        $aturan = PalletCapacityRule::where('pack_unit', 'KG')->where('pack_size', '20.000')->firstOrFail();

        $this->delete(route('wms.admin.pallet-capacity.destroy', $aturan))
            ->assertSessionHas('warning');

        $this->assertNull($produk->fresh()->kapasitasPalet());
        $this->assertDatabaseMissing('pallet_capacity_rules', ['id' => $aturan->id]);
    }

    /* ---------------------------------------------- Ukuran tanpa aturan */

    /** Ukuran di luar daftar mengembalikan NULL, bukan angka terdekat. */
    public function test_ukuran_tak_dikenal_tidak_ditebak(): void
    {
        $this->assertNull(PalletCapacity::resolve('KG', 17.5));
        $this->assertNull(PalletCapacity::resolve(null, 20));
        $this->assertNull(PalletCapacity::resolve('KG', null));
    }

    /** Layar menunjukkan ukuran apa saja yang masih kurang, beserta jumlahnya. */
    public function test_layar_menyebut_ukuran_yang_belum_punya_aturan(): void
    {
        $this->login();

        $this->produk(['pack_unit' => 'KG', 'pack_size' => 16, 'uom' => 'PAIL']);
        $this->produk(['pack_unit' => 'KG', 'pack_size' => 16, 'uom' => 'PAIL']);

        $belum = $this->get(route('wms.admin.pallet-capacity'))->assertOk()->viewData('belum');

        $baris = $belum->firstWhere('pack_size', '16.000');

        $this->assertNotNull($baris, 'Ukuran yang belum punya aturan wajib terlihat.');
        $this->assertSame(2, (int) $baris->jumlah);
    }

    /**
     * Produk yang ukuran kemasannya sendiri kosong dihitung TERPISAH.
     *
     * Aturan ukuran tidak bisa menolong mereka — tidak ada ukuran untuk
     * dicocokkan — jadi menaruhnya di daftar yang sama membuatnya terlihat
     * seperti pekerjaan yang bisa diselesaikan dari layar ini.
     */
    public function test_produk_tanpa_ukuran_kemasan_dihitung_terpisah(): void
    {
        $this->login();

        $this->produk(['pack_unit' => null, 'pack_size' => null, 'uom' => 'DRM']);

        $respons = $this->get(route('wms.admin.pallet-capacity'))->assertOk();

        $this->assertSame(1, $respons->viewData('tanpaUkuran'));
        $this->assertNull($respons->viewData('belum')->firstWhere('uom', 'DRM'));
    }

    /* ------------------------------------------------------------- Jejak */

    public function test_perubahan_kapasitas_tercatat_di_log_aktivitas(): void
    {
        $this->login();

        $aturan = PalletCapacityRule::where('pack_unit', 'KG')->where('pack_size', '20.000')->firstOrFail();

        $this->put(route('wms.admin.pallet-capacity.update', $aturan), [
            'max_qty_per_pallet' => 40,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('activity_logs', [
            'action' => ActivityLog::SETTINGS_UPDATE,
        ]);

        $log = ActivityLog::where('action', ActivityLog::SETTINGS_UPDATE)->latest('id')->firstOrFail();

        $this->assertStringContainsString('36', $log->description);
        $this->assertStringContainsString('40', $log->description);
    }
}
