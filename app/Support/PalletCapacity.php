<?php

namespace App\Support;

/**
 * Kapasitas maksimal satu palet, menurut ukuran & satuan kemasan.
 *
 * Aturan berasal dari operasional gudang PT Berger Paints (PRD §7.1). Angkanya
 * TIDAK bisa diturunkan dari rumus volume/berat — perhatikan bahwa 20 Liter
 * memuat 27 pcs sementara 20 Kg memuat 36 pcs. Karena itu satuan (`L` vs `KG`)
 * ikut menentukan hasilnya, bukan cuma angkanya.
 *
 * Ukuran di luar daftar ini sengaja mengembalikan NULL, bukan menebak angka
 * terdekat: salah menghitung kapasitas palet berarti salah membentuk palet di
 * lantai gudang. Produk semacam itu ditandai agar Manager mengisi manual.
 */
class PalletCapacity
{
    public const UNIT_LITER = 'L';

    public const UNIT_KILOGRAM = 'KG';

    public const UNITS = [self::UNIT_LITER, self::UNIT_KILOGRAM];

    /**
     * Satuan => [ukuran kemasan => maksimal pcs per palet].
     *
     * Ukuran disimpan sebagai string 2 desimal agar perbandingan tidak
     * terpengaruh ketidakakuratan pembulatan float (0.1 + 0.2 !== 0.3).
     *
     * @var array<string, array<string, int>>
     */
    private const RULES = [
        self::UNIT_LITER => [
            '0.90' => 720,
            '2.50' => 180,
            '3.60' => 180,
            // 5 Liter menyamai 5 Kg (180) — wadahnya sebesar itu juga secara
            // fisik. Ditambahkan setelah data produksi nyata memuat kemasan
            // 5 Ltr (LUXATHERM, LUXOL) yang belum tercakup aturan awal.
            '5.00' => 180,
            '15.00' => 40,
            '18.00' => 27,
            '20.00' => 27,
        ],
        self::UNIT_KILOGRAM => [
            '0.90' => 720,
            '1.00' => 720,
            '4.00' => 180,
            '5.00' => 180,
            '18.00' => 36,
            '20.00' => 36,
            '25.00' => 36,
        ],
    ];

    /**
     * Kapasitas palet untuk satu kombinasi satuan + ukuran.
     *
     * @return int|null NULL bila kombinasinya tidak ada dalam aturan gudang
     */
    public static function resolve(?string $unit, int|float|string|null $size): ?int
    {
        if ($unit === null || $size === null || $size === '') {
            return null;
        }

        $unit = strtoupper(trim($unit));

        if (! isset(self::RULES[$unit])) {
            return null;
        }

        return self::RULES[$unit][number_format((float) $size, 2, '.', '')] ?? null;
    }

    /**
     * Daftar ukuran yang dikenal untuk satu satuan — dipakai pesan bantuan di
     * form agar Manager tahu ukuran apa saja yang terhitung otomatis.
     *
     * @return list<string>
     */
    public static function knownSizes(string $unit): array
    {
        return array_keys(self::RULES[strtoupper($unit)] ?? []);
    }

    /**
     * Memecah total qty menjadi beberapa palet (PRD §7.1).
     *
     * Palet diisi penuh lebih dulu, sisanya menjadi palet terakhir:
     * 235 pcs dengan kapasitas 180 menghasilkan [180, 55].
     *
     * @return list<int> Qty tiap palet, berurutan
     */
    public static function split(int $totalQty, int $capacity): array
    {
        if ($totalQty <= 0 || $capacity <= 0) {
            return [];
        }

        $pallets = array_fill(0, intdiv($totalQty, $capacity), $capacity);

        if ($remainder = $totalQty % $capacity) {
            $pallets[] = $remainder;
        }

        return $pallets;
    }
}
