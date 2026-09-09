<?php

namespace App\Support\Reporting;

use App\Models\ActivityLog;
use App\Models\DeliveryNote;
use App\Models\InboundHeader;
use App\Models\InventoryStock;
use App\Models\PickingList;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\User;
use App\Models\UserSession;
use App\Support\Permission;
use App\Support\Settings;
use App\Support\WarehouseScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Angka-angka Dashboard utama — dihitung dari data, bukan ditulis tangan.
 *
 * SATU KARTU, SATU IZIN, DIHITUNG SEKALI
 * --------------------------------------
 * Dashboard ini dibuka tiga peran dengan kewenangan berbeda: Super Admin,
 * Manager, dan Logistik. Yang boleh mereka lihat TIDAK sama — Logistik
 * mengerjakan alur keluar-masuk barang setiap hari, tetapi selisih hasil
 * stocktake dan log siapa-melakukan-apa bukan urusannya.
 *
 * Karena itu tiap kartu di sini didaftarkan bersama IZIN yang menjaganya, dan
 * angkanya BARU DIHITUNG kalau izinnya lolos. Dua akibatnya disengaja:
 *
 *   1. Kartu yang tidak boleh dilihat tidak pernah masuk ke data yang dikirim
 *      ke layar. Menyembunyikannya dengan @can di Blade saja berarti angkanya
 *      tetap ikut terkirim — dan siapa pun yang membuka "view source" bisa
 *      membacanya. Yang disembunyikan cuma kotaknya, bukan isinya.
 *   2. Query-nya tidak jalan sama sekali. Dashboard dengan 14 kartu yang
 *      selalu menghitung semuanya berarti Logistik menunggu tiga query yang
 *      hasilnya langsung dibuang.
 *
 * IZINNYA MENUMPANG YANG SUDAH ADA, BUKAN IZIN BARU
 * -------------------------------------------------
 * Tiap kartu memakai izin milik HALAMAN yang jadi tujuannya — kartu "Butuh
 * Diterima" memakai OUTBOUND_APPROVAL, kartu "Koreksi Stok" memakai
 * INVENTORY_ADJUST. Membuat izin baru khusus dashboard berarti ada dua tempat
 * yang harus sepakat tentang hal yang sama, dan suatu hari nanti tidak lagi:
 * kartunya tampil, halamannya 403.
 *
 * BATAS GUDANG TETAP BERLAKU. Manager Karawang melihat angka Karawang saja;
 * Super Admin (warehouse_id NULL) melihat seluruhnya. Tanpa ini dashboard
 * menjadi satu-satunya layar yang membocorkan data gudang lain.
 *
 * @phpstan-type Kartu array{izin: string, hitung: callable}
 */
class AdminDashboard
{
    /**
     * Ambang "sebentar lagi kedaluwarsa" dan "karantina segera lepas".
     *
     * METODE, bukan konstanta, sejak Fase 10: keduanya kini diatur Super Admin
     * lewat Pengaturan Sistem. Ambang kedaluwarsa membaca setelan yang SAMA
     * dengan halaman Data Stok — kalau keduanya punya angka sendiri, dashboard
     * akan menyebut "12 batch" sementara halamannya menampilkan 9, dan tidak
     * ada yang tahu mana yang benar.
     */
    public static function ambangKedaluwarsa(): int
    {
        return Settings::get(Settings::EXPIRY_WARNING_DAYS);
    }

    public static function ambangKarantinaLepas(): int
    {
        return Settings::get(Settings::QUARANTINE_SOON_DAYS);
    }

    /** Berapa bulan ke belakang yang digambar di grafik tren. */
    public const BULAN_TREN = 6;

    /**
     * Metrik yang BOLEH dilihat $user, sudah dibatasi ke gudangnya.
     *
     * Kunci yang tidak ada berarti kartunya tidak boleh dilihat — Blade
     * memeriksa keberadaan kunci, bukan izinnya lagi. Itu disengaja: kalau
     * suatu saat izin di sini dan @can di sana berbeda pendapat, yang menang
     * harus yang menentukan datanya ikut terkirim atau tidak.
     *
     * @return array<string, mixed>
     */
    public function untuk(?User $user): array
    {
        $hasil = [];

        foreach ($this->kartu() as $nama => [$izin, $hitung]) {
            if (Permission::allows($user, $izin)) {
                $hasil[$nama] = $hitung($user);
            }
        }

        return $hasil;
    }

    /**
     * Daftar kartu: nama => [izin yang menjaganya, cara menghitungnya].
     *
     * @return array<string, array{0: string, 1: callable(?User): mixed}>
     */
    private function kartu(): array
    {
        return [
            /* ---------------- Alur harian: Logistik, Manager, Super Admin */

            'menunggu_diterima' => [Permission::OUTBOUND_APPROVAL, $this->menungguDiterima(...)],
            'outstanding' => [Permission::OUTBOUND_APPROVAL, $this->outstanding(...)],
            'siap_dipicking' => [Permission::OUTBOUND_PICKING_LIST, $this->siapDipicking(...)],
            'picking_berjalan' => [Permission::OUTBOUND_PICKING_VIEW, $this->pickingBerjalan(...)],
            'dalam_pengiriman' => [Permission::OUTBOUND_DELIVERY, $this->dalamPengiriman(...)],
            'bukti_menunggu' => [Permission::OUTBOUND_VERIFICATION, $this->buktiMenunggu(...)],
            'inbound_menunggu' => [Permission::INBOUND_VERIFY, $this->inboundMenunggu(...)],
            'karantina' => [Permission::INVENTORY_QUARANTINE, $this->karantina(...)],
            'segera_kedaluwarsa' => [Permission::INVENTORY_VIEW, $this->segeraKedaluwarsa(...)],
            'tren' => [Permission::REPORTS_VIEW, $this->tren(...)],

            /* -------------------- Pengawasan: Manager & Super Admin saja.
             |
             | Logistik SENGAJA tidak ikut. Ketiganya adalah angka yang dipakai
             | untuk MENILAI pekerjaan gudang — berapa kali stok dikoreksi,
             | seberapa jauh hitungan fisik meleset, siapa saja yang punya akun.
             | Orang yang dinilai tidak perlu memegang alat penilainya. */

            'koreksi_stok' => [Permission::INVENTORY_ADJUST, $this->koreksiStok(...)],
            'stocktake' => [Permission::STOCKTAKE_MANAGE, $this->stocktake(...)],
            'pengguna' => [Permission::ADMIN_USERS, $this->pengguna(...)],

            /* ------------------------------------------ Super Admin saja.
             |
             | Log aktivitas merekam tindakan Manager juga — lihat alasan
             | lengkapnya di Permission::ADMIN_AUDIT. */

            'aktivitas' => [Permission::ADMIN_AUDIT, $this->aktivitas(...)],
        ];
    }

    /* ------------------------------------------------------ Alur outbound */

    /** @return array{jumlah:int, tertua_hari:int|null} */
    private function menungguDiterima(?User $user): array
    {
        $q = WarehouseScope::apply(
            SalesOrder::query()->where('status', SalesOrder::STATUS_PENDING),
            $user,
        );

        $tertua = (clone $q)->min('submitted_at');

        return [
            'jumlah' => (clone $q)->count(),
            // Umur antrean lebih berguna daripada jumlahnya: 12 pesanan yang
            // masuk pagi ini tidak sama gawatnya dengan 3 yang menunggu
            // sejak minggu lalu.
            'tertua_hari' => $tertua ? (int) Carbon::parse($tertua)->diffInDays(now()) : null,
        ];
    }

    /** @return array{qty:int, pesanan:int} */
    private function outstanding(?User $user): array
    {
        $q = SalesOrderDetail::query()
            ->where('outstanding_qty', '>', 0)
            ->whereHas('salesOrder', fn ($o) => WarehouseScope::apply($o, $user));

        return [
            'qty' => (int) (clone $q)->sum('outstanding_qty'),
            'pesanan' => (clone $q)->distinct('sales_order_id')->count('sales_order_id'),
        ];
    }

    /** @return array{jumlah:int} */
    private function siapDipicking(?User $user): array
    {
        return [
            'jumlah' => WarehouseScope::apply(
                SalesOrder::query()
                    ->where('status', SalesOrder::STATUS_APPROVED)
                    ->whereNull('picking_list_id'),
                $user,
            )->count(),
        ];
    }

    /** @return array{terbuka:int, dikerjakan:int} */
    private function pickingBerjalan(?User $user): array
    {
        $q = fn (string $status) => WarehouseScope::apply(
            PickingList::query()->where('status', $status),
            $user,
        )->count();

        return [
            'terbuka' => $q(PickingList::STATUS_OPEN),
            'dikerjakan' => $q(PickingList::STATUS_PICKING),
        ];
    }

    /** @return array{jumlah:int} */
    private function dalamPengiriman(?User $user): array
    {
        return [
            'jumlah' => WarehouseScope::apply(
                DeliveryNote::query()->where('status', DeliveryNote::STATUS_SHIPPED),
                $user,
            )->count(),
        ];
    }

    /** @return array{jumlah:int} */
    private function buktiMenunggu(?User $user): array
    {
        return [
            'jumlah' => WarehouseScope::apply(
                SalesOrder::query()->where('status', SalesOrder::STATUS_PROOF_UPLOADED),
                $user,
            )->count(),
        ];
    }

    /* ------------------------------------------------------- Alur inbound */

    /** @return array{jumlah:int} */
    private function inboundMenunggu(?User $user): array
    {
        return [
            'jumlah' => WarehouseScope::apply(
                InboundHeader::query()->whereIn('status', [
                    InboundHeader::STATUS_VERIFICATION_PENDING,
                    InboundHeader::STATUS_PARTIAL_VERIFIED,
                ]),
                $user,
            )->count(),
        ];
    }

    /* ----------------------------------------------------- Keadaan gudang */

    /** @return array{batch:int, qty:int, lepas_pekan_ini:int} */
    private function karantina(?User $user): array
    {
        $q = fn () => WarehouseScope::apply(InventoryStock::query()->inQuarantine(), $user);

        return [
            'batch' => $q()->count(),
            'qty' => (int) $q()->sum('qty_available'),
            // Yang paling dicari di layar ini bukan "berapa yang ditahan"
            // melainkan "berapa yang sebentar lagi boleh dijual", karena
            // itulah yang menentukan pesanan mana bisa dijanjikan pekan ini.
            'lepas_pekan_ini' => $q()
                ->whereNotNull('quarantine_until')
                ->whereDate('quarantine_until', '<=', now()->addDays(self::ambangKarantinaLepas()))
                ->count(),
        ];
    }

    /** @return array{batch:int, qty:int, ambang:int} */
    private function segeraKedaluwarsa(?User $user): array
    {
        $q = fn () => WarehouseScope::apply(
            InventoryStock::query()
                ->sellable()
                ->whereDate('expiry_date', '<=', now()->addDays(self::ambangKedaluwarsa())),
            $user,
        );

        return [
            'batch' => $q()->count(),
            'qty' => (int) $q()->sum('qty_available'),
            'ambang' => self::ambangKedaluwarsa(),
        ];
    }

    /* --------------------------------------- Pengawasan (tanpa Logistik) */

    /** @return array{jumlah:int, neto:int, hari:int} */
    private function koreksiStok(?User $user): array
    {
        $q = fn () => WarehouseScope::apply(
            StockMovement::query()
                ->where('movement_type', StockMovement::TYPE_ADJUSTMENT)
                ->where('created_at', '>=', now()->subDays(30)),
            $user,
        );

        return [
            'jumlah' => $q()->count(),
            // Neto, bukan jumlah mutlak: 50 masuk dan 50 keluar adalah dua
            // koreksi yang saling menghapus, dan itu cerita yang berbeda dari
            // 100 unit yang benar-benar hilang.
            'neto' => (int) $q()->sum('qty_change'),
            'hari' => 30,
        ];
    }

    /** @return array{berjalan:int, terakhir:array{referensi:string, tanggal:string, selisih_baris:int, selisih_qty:int}|null} */
    private function stocktake(?User $user): array
    {
        $terakhir = WarehouseScope::apply(
            StockTake::query()->where('status', StockTake::STATUS_FINALIZED),
            $user,
        )->latest('finalized_at')->first();

        $selisih = null;

        if ($terakhir) {
            $baris = StockTakeItem::query()
                ->where('stock_take_id', $terakhir->id)
                ->whereNotNull('applied_delta')
                ->where('applied_delta', '!=', 0);

            $selisih = [
                'referensi' => $terakhir->reference,
                'tanggal' => Carbon::parse($terakhir->finalized_at)->format('d M Y'),
                'selisih_baris' => (clone $baris)->count(),
                // Dijumlahkan dalam nilai mutlak. Kekurangan 20 di satu rak
                // dan kelebihan 20 di rak sebelah BUKAN "nol selisih" — itu
                // dua kesalahan, dan neto akan menyembunyikan keduanya.
                'selisih_qty' => (int) (clone $baris)->sum(DB::raw('ABS(applied_delta)')),
            ];
        }

        return [
            'berjalan' => WarehouseScope::apply(
                StockTake::query()->where('status', StockTake::STATUS_COUNTING),
                $user,
            )->count(),
            'terakhir' => $selisih,
        ];
    }

    /** @return array{aktif:int, nonaktif:int, sesi_hidup:int} */
    private function pengguna(?User $user): array
    {
        $q = fn () => WarehouseScope::apply(User::query(), $user);

        return [
            'aktif' => $q()->where('is_active', true)->count(),
            'nonaktif' => $q()->where('is_active', false)->count(),
            'sesi_hidup' => UserSession::query()
                ->where('last_activity_at', '>=', now()->subMinutes(30))
                ->whereHas('user', fn ($u) => WarehouseScope::apply($u, $user))
                ->count(),
        ];
    }

    /* ------------------------------------------------ Super Admin saja */

    /** @return Collection<int, ActivityLog> */
    private function aktivitas(?User $user)
    {
        return ActivityLog::query()
            ->with('user:id,full_name')
            ->latest('created_at')
            ->latest('id')
            ->limit(8)
            ->get();
    }

    /* ------------------------------------------------------------ Grafik */

    /**
     * Pesanan masuk vs pesanan selesai, per bulan.
     *
     * Dua garis, bukan satu: pesanan masuk saja tidak menceritakan apakah
     * gudang sanggup mengikutinya. Jarak antara keduanya yang melebar dari
     * bulan ke bulan adalah tumpukan yang sedang tumbuh.
     *
     * @return array{label:list<string>, masuk:list<int>, selesai:list<int>}
     */
    private function tren(?User $user): array
    {
        $mulai = now()->startOfMonth()->subMonths(self::BULAN_TREN - 1);

        $hitung = function (string $kolom) use ($user, $mulai): array {
            $baris = WarehouseScope::apply(
                SalesOrder::query()->whereNotNull($kolom)->where($kolom, '>=', $mulai),
                $user,
            )
                ->selectRaw("to_char($kolom, 'YYYY-MM') as bulan, count(*) as jumlah")
                ->groupBy('bulan')
                ->pluck('jumlah', 'bulan')
                ->all();

            return array_map('intval', $baris);
        };

        $masuk = $hitung('submitted_at');
        $selesai = $hitung('completed_at');

        $label = [];
        $deretMasuk = [];
        $deretSelesai = [];

        // Bulan yang tidak punya satu pesanan pun TETAP digambar sebagai nol.
        // Membiarkannya hilang membuat grafik melompati bulan sepi, dan
        // penurunan terbaca seperti tidak pernah terjadi.
        for ($i = 0; $i < self::BULAN_TREN; $i++) {
            $bulan = (clone $mulai)->addMonths($i);
            $kunci = $bulan->format('Y-m');

            $label[] = $bulan->translatedFormat('M Y');
            $deretMasuk[] = $masuk[$kunci] ?? 0;
            $deretSelesai[] = $selesai[$kunci] ?? 0;
        }

        return ['label' => $label, 'masuk' => $deretMasuk, 'selesai' => $deretSelesai];
    }
}
