<?php

namespace App\Support\Reporting;

use App\Models\InboundHeader;
use App\Models\PickingList;
use App\Models\StockTake;
use App\Models\User;
use App\Support\WarehouseScope;
use Illuminate\Support\Collection;

/**
 * Dashboard Operator Gudang — daftar pekerjaan, bukan laporan.
 *
 * BEDANYA DENGAN DUA DASHBOARD LAIN
 * ---------------------------------
 * Dashboard utama menjawab "bagaimana keadaannya"; halaman ini menjawab "apa
 * yang harus saya kerjakan sekarang". Operator berdiri di depan rak sambil
 * memegang telepon — yang berguna baginya adalah pekerjaan yang bisa langsung
 * ditekan, bukan angka untuk direnungkan.
 *
 * KARTU YANG TIDAK ADA PEKERJAANNYA TIDAK DITAMPILKAN
 * ---------------------------------------------------
 * Tugas yang sedang dipegang dan sesi stocktake hanya muncul kalau memang
 * ada. Kartu kosong bertuliskan "0" bukan informasi — ia hanya membuat yang
 * benar-benar perlu dikerjakan tenggelam di antara kotak-kotak yang tidak
 * menuntut apa pun. Yang selalu tampil hanya dua antrean tetap (put-away dan
 * daftar picking), karena nol di sana pun kabar yang berguna: berarti tidak
 * ada yang menunggu.
 *
 * SATU KARTU MILIK ORANGNYA, SISANYA MILIK GUDANG. "Tugas Saya" disaring
 * lewat claimed_by; antrean lainnya milik bersama — siapa pun yang lebih dulu
 * mengambil, dialah yang mengerjakan.
 */
class OperatorDashboard
{
    /**
     * @return array{
     *     tugas_saya: Collection<int, PickingList>,
     *     picking_tersedia: array{jumlah:int},
     *     putaway: array{dokumen:int, palet:int},
     *     stocktake: StockTake|null,
     *     riwayat: Collection<int, PickingList>
     * }
     */
    public function untuk(?User $user): array
    {
        return [
            'tugas_saya' => $this->tugasSaya($user),
            'picking_tersedia' => $this->pickingTersedia($user),
            'putaway' => $this->putaway($user),
            'stocktake' => $this->stocktakeBerjalan($user),
            'riwayat' => $this->riwayat($user),
        ];
    }

    /**
     * Daftar picking yang SEDANG dipegang orang ini.
     *
     * Paling atas di layar, dan itu disengaja: daftar yang sudah diambil
     * mengunci pesanan atas nama seseorang, dan yang paling merugikan adalah
     * tugas yang terlupakan — bukan tugas yang belum diambil.
     *
     * @return Collection<int, PickingList>
     */
    private function tugasSaya(?User $user)
    {
        if ($user === null) {
            return collect();
        }

        return PickingList::query()
            ->where('status', PickingList::STATUS_PICKING)
            ->where('claimed_by', $user->id)
            ->withCount([
                'items',
                'items as items_pending_count' => fn ($q) => $q->where('status', 'pending'),
            ])
            ->latest('claimed_at')
            ->get();
    }

    /** Antrean bersama: belum diambil siapa pun. */
    private function pickingTersedia(?User $user): array
    {
        return [
            'jumlah' => WarehouseScope::apply(
                PickingList::query()->where('status', PickingList::STATUS_OPEN),
                $user,
            )->count(),
        ];
    }

    private function putaway(?User $user): array
    {
        $q = fn () => WarehouseScope::apply(
            InboundHeader::query()->where('status', InboundHeader::STATUS_PUTAWAY_PENDING),
            $user,
        );

        return [
            'dokumen' => $q()->count(),
            'palet' => (int) $q()->withCount('details')->get()->sum('details_count'),
        ];
    }

    /**
     * Sesi stocktake yang sedang berjalan di gudang ini — kalau ada.
     *
     * NULL berarti kartunya tidak usah digambar sama sekali. Stocktake bukan
     * pekerjaan harian: menampilkan "0 sesi" setiap hari sepanjang tahun
     * membuat orang berhenti membacanya, justru pada minggu ia benar-benar
     * berisi.
     */
    private function stocktakeBerjalan(?User $user): ?StockTake
    {
        return WarehouseScope::apply(
            StockTake::query()->where('status', StockTake::STATUS_COUNTING),
            $user,
        )->latest('opened_at')->first();
    }

    /**
     * Lima tugas terakhir yang DISELESAIKAN orang ini.
     *
     * Bukan riwayat lengkap gudang — cukup untuk menjawab "tadi saya sudah
     * mengerjakan yang mana", pertanyaan yang muncul tiap kali seseorang
     * kembali dari istirahat.
     *
     * @return Collection<int, PickingList>
     */
    private function riwayat(?User $user)
    {
        if ($user === null) {
            return collect();
        }

        return PickingList::query()
            ->where('status', PickingList::STATUS_COMPLETED)
            ->where('completed_by', $user->id)
            ->withCount('items')
            ->latest('completed_at')
            ->limit(5)
            ->get();
    }
}
