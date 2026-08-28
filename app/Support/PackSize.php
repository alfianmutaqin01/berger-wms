<?php

namespace App\Support;

/**
 * Membaca ukuran kemasan NOMINAL dari nama produk.
 *
 * Perbedaan penting dengan `unit_volume`: kolom itu berisi volume ISI
 * sebenarnya menurut ERP, yang sering lebih kecil dari ukuran wadahnya —
 * "Royale Smart Clean Blue Smoke 20Ltr" punya unit_volume 19.4 karena pail
 * 20 liter sengaja tidak diisi penuh agar ada ruang untuk pewarna.
 *
 * Aturan kapasitas palet bergantung pada UKURAN WADAH (pail 20 L memuat 27 pcs
 * per palet, berapa pun isinya), sehingga nilai itulah yang harus dipakai —
 * bukan volume isi. Nama produk secara konsisten memuat ukuran nominal di
 * bagian akhir ("20Ltr", "2.5Ltr", "4Kg"), jadi di situlah kita membacanya.
 */
class PackSize
{
    /**
     * @return array{size: float, unit: string}|null NULL bila tidak terbaca
     */
    public static function parse(?string $name): ?array
    {
        if (blank($name)) {
            return null;
        }

        // Angka + satuan, contoh: "20Ltr", "2.5 Ltr", "0.25Ltr", "4Kg", "20 KG".
        // Sengaja MENUNTUT satuan menempel setelah angka agar potongan seperti
        // "Solitaire 8500" atau kode warna "L1313" tidak ikut tertangkap.
        if (! preg_match_all('/(\d+(?:[.,]\d+)?)\s*(Ltr|L|Kg)\b/i', $name, $matches, PREG_SET_ORDER)) {
            return null;
        }

        // Ambil kecocokan TERAKHIR: ukuran kemasan selalu di ujung nama produk,
        // sementara angka lain (kode warna, seri) muncul lebih awal.
        $last = end($matches);

        $unit = strtoupper($last[2]) === 'KG'
            ? PalletCapacity::UNIT_KILOGRAM
            : PalletCapacity::UNIT_LITER;

        return [
            'size' => (float) str_replace(',', '.', $last[1]),
            'unit' => $unit,
        ];
    }
}
