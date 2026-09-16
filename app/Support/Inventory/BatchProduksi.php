<?php

namespace App\Support\Inventory;

use Carbon\CarbonImmutable;

/**
 * Membaca tanggal produksi dari nomor batch.
 *
 * Nomor batch pabrik sudah memuat tahun dan bulan produksinya:
 *
 *     I126080071
 *     │└┬┘└┬┘└┬─┘
 *     │ │  │  └── nomor urut
 *     │ │  └───── bulan produksi (08)
 *     │ └──────── tahun produksi (26 -> 2026)
 *     └────────── kode pabrik/lini
 *
 * MENGAPA DIBACA, BUKAN DIKETIK ULANG
 * -----------------------------------
 * Tanggal produksi menentukan kedaluwarsa dan urutan FIFO. Selama ia diketik
 * terpisah dari batchnya, dua keterangan tentang palet yang sama bisa saling
 * bertentangan — dan yang salah justru yang menentukan barang mana dijual lebih
 * dulu. Nomor batchnya sendiri sudah menjawab pertanyaan itu, jadi orang yang
 * berdiri di depan rak cukup membaca satu hal dari label.
 *
 * TANGGALNYA SELALU 01. Nomor batch hanya membawa tahun dan bulan; mengarang
 * tanggal yang lebih tepat dari itu berarti mengaku tahu sesuatu yang tidak
 * tertulis di mana pun. Tanggal 1 adalah pembacaan paling awal dalam bulan itu,
 * sehingga umur simpan yang dihitung darinya tidak pernah lebih panjang
 * daripada yang sebenarnya.
 *
 * NULL BERARTI TIDAK BISA DIBACA, dan itu bukan kegagalan yang perlu
 * disembunyikan: ada batch lama yang tidak mengikuti pola ini sama sekali
 * (mis. "642346774"). Pemanggilnya menyediakan jalan isi manual untuk kasus
 * itu, bukan menolak barangnya.
 */
class BatchProduksi
{
    /**
     * Tanggal produksi dari nomor batch, format Y-m-d.
     *
     * NULL kalau nomornya tidak mengikuti pola, bulannya di luar 01-12, atau
     * tanggalnya jatuh di masa depan — tanggal maju memberi umur simpan yang
     * tidak pernah dimiliki palet itu, dan lebih baik ditanyakan ke orangnya.
     */
    public static function tanggal(mixed $batch): ?string
    {
        if (! is_string($batch)) {
            return null;
        }

        if (! preg_match('/^[A-Za-z]{1,3}\d(\d{2})(\d{2})\d{3,4}$/', trim($batch), $cocok)) {
            return null;
        }

        $bulan = (int) $cocok[2];

        if ($bulan < 1 || $bulan > 12) {
            return null;
        }

        $tanggal = CarbonImmutable::create(2000 + (int) $cocok[1], $bulan, 1);

        if ($tanggal === null || $tanggal->isAfter(CarbonImmutable::today())) {
            return null;
        }

        return $tanggal->toDateString();
    }
}
