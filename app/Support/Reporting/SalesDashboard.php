<?php

namespace App\Support\Reporting;

use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Dashboard Sales — disusun menurut siapa yang harus bergerak berikutnya.
 *
 * PERTANYAAN YANG SALAH DAN PERTANYAAN YANG BENAR
 * -----------------------------------------------
 * Halaman lamanya menyusun angka menurut STATUS pesanan — dibuat, menunggu,
 * dikirim, selesai — seolah keempatnya setara. Padahal bagi Sales keempatnya
 * sangat tidak setara: dua di antaranya menunggu DIRINYA, dua sisanya
 * menunggu orang lain. Yang pertama harus dikerjakan hari ini juga; yang
 * kedua cuma perlu diketahui saat pelanggan bertanya.
 *
 * Karena itu kelas ini memisahkan keduanya secara tegas: `perlu_tindakan`
 * berisi yang macet di tangan Sales sendiri, `berjalan` berisi yang sedang
 * ditangani gudang.
 *
 * TIGA YANG MACET DI TANGAN SALES, DAN SEMUANYA MUDAH TERLUPAKAN
 * --------------------------------------------------------------
 *   DRAFT     — pesanan yang belum disubmit TIDAK TERLIHAT oleh Logistik
 *               sama sekali. Ia tidak sedang mengantre; ia tidak ada.
 *               Digabung dengan batas jam 15:00, draft yang lupa disubmit
 *               berarti pelanggan mundur satu hari penuh.
 *   DITOLAK   — masih bisa diperbaiki lalu diajukan ulang. Dibiarkan, ia
 *               diam selamanya karena tidak ada yang menagihnya.
 *   BUKTI     — barang sudah sampai pelanggan, tinggal fotonya. Pesanan
 *               tidak pernah dianggap selesai sampai buktinya masuk.
 *
 * ANGKA YANG DIBUANG. Halaman lama punya grafik "Target vs Realisasi" dengan
 * garis target 700 per minggu. Tidak ada tabel target di sistem ini, dan
 * tidak ada satu pun tempat untuk menetapkannya — angka itu ditulis tangan di
 * dalam JavaScript. Grafik yang membandingkan kenyataan dengan angka karangan
 * lebih buruk daripada tidak ada grafik.
 *
 * SEMUANYA MILIK SENDIRI. Setiap query lewat scopeOwnedBy: Sales tidak pernah
 * melihat pesanan rekannya, di sini maupun di halaman lain.
 */
class SalesDashboard
{
    /** Berapa bulan ke belakang yang digambar di grafik. */
    public const BULAN_TREN = 6;

    /** Status yang berarti "sedang ditangani gudang, bukan menunggu Sales". */
    public const STATUS_BERJALAN = [
        SalesOrder::STATUS_APPROVED,
        SalesOrder::STATUS_PICKING,
        SalesOrder::STATUS_READY_TO_SHIP,
        SalesOrder::STATUS_SHIPPING,
        SalesOrder::STATUS_PROOF_UPLOADED,
    ];

    /** Status yang berarti pesanan sudah tuntas. */
    public const STATUS_SELESAI = [
        SalesOrder::STATUS_COMPLETED,
        SalesOrder::STATUS_COMPLETED_BILLING,
    ];

    /**
     * @return array{
     *     perlu_tindakan: array{draft:int, ditolak:int, bukti:int, total:int},
     *     menunggu_gudang: array{jumlah:int, tertua_hari:int|null},
     *     berjalan: array{jumlah:int},
     *     outstanding: array{qty:int, pesanan:int},
     *     selesai_bulan_ini: array{jumlah:int},
     *     daftar_bukti: Collection<int, SalesOrder>,
     *     daftar_draft: Collection<int, SalesOrder>,
     *     tren: array{label:list<string>, dibuat:list<int>, selesai:list<int>}
     * }
     */
    public function untuk(?User $user): array
    {
        $id = $user?->id;

        return [
            'perlu_tindakan' => $this->perluTindakan($id),
            'menunggu_gudang' => $this->menungguGudang($id),
            'berjalan' => ['jumlah' => $this->hitung($id, self::STATUS_BERJALAN)],
            'outstanding' => $this->outstanding($id),
            'selesai_bulan_ini' => $this->selesaiBulanIni($id),
            'daftar_bukti' => $this->daftarBukti($id),
            'daftar_draft' => $this->daftarDraft($id),
            'tren' => $this->tren($id),
        ];
    }

    /* ------------------------------------------------------------ Perkakas */

    private function milik(?int $userId)
    {
        return SalesOrder::query()->ownedBy($userId ?? 0);
    }

    /** @param list<string> $status */
    private function hitung(?int $userId, array $status): int
    {
        return $this->milik($userId)->whereIn('status', $status)->count();
    }

    /* -------------------------------------------- Yang menunggu Sales sendiri */

    /**
     * Tiga hal yang macet di tangan Sales, dan totalnya.
     *
     * Total dipakai layar untuk memutuskan apakah seluruh bagian "Butuh
     * Tindakan Anda" perlu digambar. Nol berarti tidak ada yang tertahan
     * karenanya — dan itu lebih baik dikatakan sekali dengan tenang daripada
     * lewat tiga kotak kosong bertuliskan nol.
     */
    private function perluTindakan(?int $userId): array
    {
        $draft = $this->hitung($userId, [SalesOrder::STATUS_DRAFT]);
        $ditolak = $this->hitung($userId, [SalesOrder::STATUS_REJECTED]);
        $bukti = $this->hitung($userId, [SalesOrder::STATUS_SHIPPING]);

        return [
            'draft' => $draft,
            'ditolak' => $ditolak,
            'bukti' => $bukti,
            'total' => $draft + $ditolak + $bukti,
        ];
    }

    /**
     * Pesanan yang sudah disubmit dan sedang menunggu Logistik.
     *
     * Umur antrean ikut dilaporkan karena itulah yang ditanyakan pelanggan —
     * "sudah saya pesan minggu lalu, kok belum jalan" — dan Sales perlu bisa
     * menjawabnya tanpa membuka satu per satu.
     */
    private function menungguGudang(?int $userId): array
    {
        $q = $this->milik($userId)->where('status', SalesOrder::STATUS_PENDING);

        $tertua = (clone $q)->min('submitted_at');

        return [
            'jumlah' => (clone $q)->count(),
            'tertua_hari' => $tertua ? (int) Carbon::parse($tertua)->diffInDays(now()) : null,
        ];
    }

    /**
     * Kekurangan yang masih terutang ke pelanggan Sales ini.
     *
     * Ada di sini karena Sales-lah yang ditelepon saat barang tidak lengkap,
     * bukan gudang. Angkanya sama dengan yang dilihat Logistik di menu
     * Outstanding, hanya dipersempit ke pesanan miliknya sendiri.
     */
    private function outstanding(?int $userId): array
    {
        $q = SalesOrderDetail::query()
            ->where('outstanding_qty', '>', 0)
            ->whereHas('salesOrder', fn ($o) => $o->ownedBy($userId ?? 0));

        return [
            'qty' => (int) (clone $q)->sum('outstanding_qty'),
            'pesanan' => (clone $q)->distinct('sales_order_id')->count('sales_order_id'),
        ];
    }

    private function selesaiBulanIni(?int $userId): array
    {
        return [
            'jumlah' => $this->milik($userId)
                ->whereIn('status', self::STATUS_SELESAI)
                ->where('completed_at', '>=', now()->startOfMonth())
                ->count(),
        ];
    }

    /* ------------------------------------------------------------- Daftar */

    /**
     * Pesanan yang menunggu foto bukti, LENGKAP DENGAN TAUTANNYA.
     *
     * Halaman lama punya tombol "Upload Bukti" yang membuka jendela unggah
     * palsu: ia menampilkan "Berhasil! Bukti pengiriman berhasil diunggah"
     * tanpa mengirim apa pun ke mana pun. Itu bukan tampilan sementara yang
     * belum tersambung — itu kebohongan aktif, dan Sales yang mempercayainya
     * akan mengira pekerjaannya sudah selesai.
     *
     * Unggahan yang sebenarnya menempel di halaman detail pesanan (Sales
     * mengerjakannya dari HP di depan toko), jadi yang benar dilakukan di
     * sini hanya menunjuk ke sana.
     *
     * @return Collection<int, SalesOrder>
     */
    private function daftarBukti(?int $userId)
    {
        return $this->milik($userId)
            ->where('status', SalesOrder::STATUS_SHIPPING)
            ->with('customer:id,code,name')
            ->orderBy('shipped_at')
            ->limit(5)
            ->get();
    }

    /** @return Collection<int, SalesOrder> */
    private function daftarDraft(?int $userId)
    {
        return $this->milik($userId)
            ->where('status', SalesOrder::STATUS_DRAFT)
            ->with('customer:id,code,name')
            ->withCount('details')
            ->latest('updated_at')
            ->limit(5)
            ->get();
    }

    /* ------------------------------------------------------------ Grafik */

    /**
     * Pesanan dibuat vs selesai, per bulan — TANPA garis target.
     *
     * Sistem ini tidak punya tempat untuk menetapkan target penjualan, jadi
     * garis target apa pun di sini hanya bisa berupa angka karangan.
     *
     * @return array{label:list<string>, dibuat:list<int>, selesai:list<int>}
     */
    private function tren(?int $userId): array
    {
        $mulai = now()->startOfMonth()->subMonths(self::BULAN_TREN - 1);

        $hitung = function (string $kolom) use ($userId, $mulai): array {
            return array_map('intval', $this->milik($userId)
                ->whereNotNull($kolom)
                ->where($kolom, '>=', $mulai)
                ->selectRaw("to_char($kolom, 'YYYY-MM') as bulan, count(*) as jumlah")
                ->groupBy('bulan')
                ->pluck('jumlah', 'bulan')
                ->all());
        };

        $dibuat = $hitung('created_at');
        $selesai = $hitung('completed_at');

        $label = [];
        $deretDibuat = [];
        $deretSelesai = [];

        // Bulan sepi tetap digambar nol, bukan dilompati — kalau tidak,
        // penurunan terbaca seperti tidak pernah terjadi.
        for ($i = 0; $i < self::BULAN_TREN; $i++) {
            $bulan = (clone $mulai)->addMonths($i);
            $kunci = $bulan->format('Y-m');

            $label[] = $bulan->translatedFormat('M Y');
            $deretDibuat[] = $dibuat[$kunci] ?? 0;
            $deretSelesai[] = $selesai[$kunci] ?? 0;
        }

        return ['label' => $label, 'dibuat' => $deretDibuat, 'selesai' => $deretSelesai];
    }
}
