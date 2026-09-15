<?php

namespace App\Support\Reporting;

use App\Models\InboundDetail;
use App\Models\InboundHeader;
use App\Models\User;
use App\Support\WarehouseScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard Produksi — hanya yang benar-benar dipakai Produksi.
 *
 * APA YANG SEBENARNYA DIKERJAKAN PRODUKSI DI SISTEM INI
 * ----------------------------------------------------
 * Satu hal: menyerahkan barang jadi ke gudang lewat dokumen inbound. Sesudah
 * itu Operator menaikkannya ke rak dan Logistik memverifikasinya. Produksi
 * TIDAK memesan, tidak memicking, tidak menyentuh stok.
 *
 * Dashboard lamanya menampilkan target produksi, mesin aktif, dan stok bahan
 * baku yang menipis. Tidak satu pun ada di sistem ini — tidak ada tabel bahan
 * baku, tidak ada mesin, tidak ada purchasing. Angka-angka itu bukan sekadar
 * dummy yang belum diisi; mereka menjanjikan modul yang memang tidak pernah
 * dibangun, dan sepanjang masih terpasang orang akan menunggu angkanya
 * berubah sendiri suatu hari.
 *
 * EMPAT ANGKA, DAN BATASNYA DISENGAJA
 * -----------------------------------
 * Yang ditanyakan orang Produksi setiap hari cuma: barang yang sudah saya
 * serahkan sudah naik rak belum, sudah diakui gudang belum, berapa yang
 * masuk bulan ini, dan adakah yang jumlahnya berselisih saat dinaikkan.
 * Menambahkan angka di luar itu berarti mereka harus memilah dulu mana yang
 * urusannya — dan dashboard yang perlu dipilah sama saja dengan tidak ada.
 *
 * DIBATASI PER GUDANG, BUKAN PER ORANG. Serah terima adalah pekerjaan satu
 * regu, bukan milik akun yang kebetulan menekan tombol simpan; menyaring
 * "dokumen saya" membuat rekannya sendiri tidak terlihat.
 */
class ProductionDashboard
{
    /** Berapa hari ke belakang selisih put-away dihitung. */
    public const HARI_SELISIH = 30;

    /**
     * @return array{
     *     menunggu_putaway: array{dokumen:int, palet:int},
     *     menunggu_verifikasi: array{dokumen:int},
     *     masuk_bulan_ini: array{dokumen:int, unit:int},
     *     selisih_putaway: array{baris:int, unit:int, hari:int},
     *     terakhir: Collection<int, InboundHeader>
     * }
     */
    public function untuk(?User $user): array
    {
        return [
            'menunggu_putaway' => $this->menungguPutaway($user),
            'menunggu_verifikasi' => $this->menungguVerifikasi($user),
            'masuk_bulan_ini' => $this->masukBulanIni($user),
            'selisih_putaway' => $this->selisihPutaway($user),
            'terakhir' => $this->dokumenTerakhir($user),
        ];
    }

    /** Barang sudah diserahkan, tetapi belum ada yang menaikkannya ke rak. */
    private function menungguPutaway(?User $user): array
    {
        $q = fn () => WarehouseScope::apply(
            InboundHeader::query()->where('status', InboundHeader::STATUS_PUTAWAY_PENDING),
            $user,
        );

        return [
            'dokumen' => $q()->count(),
            // Palet, bukan unit: yang menentukan berapa lama pekerjaannya
            // adalah berapa kali palet harus diangkat, bukan isinya.
            'palet' => (int) $q()->withCount('details')->get()->sum('details_count'),
        ];
    }

    /**
     * Sudah di rak, tetapi stoknya BELUM RESMI.
     *
     * Ini yang paling sering disalahpahami: barang yang sudah naik rak belum
     * bisa dijual sampai Logistik memverifikasinya. Selama masih tertahan di
     * sini, Sales tidak melihatnya sebagai stok — dan Produksi berhak tahu
     * kalau serahannya menumpuk di tahap itu.
     */
    private function menungguVerifikasi(?User $user): array
    {
        return [
            'dokumen' => WarehouseScope::apply(
                InboundHeader::query()->whereIn('status', [
                    InboundHeader::STATUS_VERIFICATION_PENDING,
                    InboundHeader::STATUS_PARTIAL_VERIFIED,
                ]),
                $user,
            )->count(),
        ];
    }

    /** Hasil kerja yang sudah tuntas bulan ini. */
    private function masukBulanIni(?User $user): array
    {
        $q = WarehouseScope::apply(
            InboundHeader::query()
                ->where('status', InboundHeader::STATUS_VERIFIED)
                ->where('updated_at', '>=', now()->startOfMonth()),
            $user,
        );

        $id = (clone $q)->pluck('id');

        return [
            'dokumen' => $id->count(),
            'unit' => $id->isEmpty() ? 0 : (int) InboundDetail::query()
                ->whereIn('inbound_header_id', $id)
                ->sum(DB::raw('COALESCE(qty_actual, pallet_qty)')),
        ];
    }

    /**
     * Selisih antara yang DINYATAKAN Produksi dan yang SAMPAI di rak.
     *
     * Angka paling berguna di halaman ini, dan satu-satunya yang berupa
     * kabar buruk. `pallet_qty` diisi Produksi saat menyerahkan, `qty_actual`
     * diisi Operator saat menaikkan. Keduanya berselisih berarti ada yang
     * hilang, tertukar, atau salah hitung di antara dua tahap itu — dan
     * kalau tidak ditampilkan di sini, Produksi baru tahu berbulan-bulan
     * kemudian lewat stocktake.
     */
    private function selisihPutaway(?User $user): array
    {
        $q = fn () => InboundDetail::query()
            ->whereNotNull('qty_actual')
            ->whereColumn('qty_actual', '!=', 'pallet_qty')
            ->whereNotNull('putaway_at')
            ->where('putaway_at', '>=', now()->subDays(self::HARI_SELISIH))
            ->whereHas('header', fn ($h) => WarehouseScope::apply($h, $user));

        return [
            'baris' => $q()->count(),
            // Mutlak, bukan neto: kurang 5 di satu palet dan lebih 5 di palet
            // lain adalah dua kesalahan, bukan nol.
            'unit' => (int) $q()->sum(DB::raw('ABS(qty_actual - pallet_qty)')),
            'hari' => self::HARI_SELISIH,
        ];
    }

    /** @return Collection<int, InboundHeader> */
    private function dokumenTerakhir(?User $user)
    {
        return WarehouseScope::apply(InboundHeader::query(), $user)
            ->withCount('details')
            ->latest('created_at')
            ->latest('id')
            ->limit(6)
            ->get();
    }
}
