<?php

namespace App\Support;

use App\Models\PalletCapacityRule;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Kapasitas maksimal satu palet, menurut ukuran & satuan kemasan.
 *
 * ANGKANYA TIDAK BISA DITURUNKAN DARI RUMUS volume/berat — perhatikan bahwa
 * 20 Liter memuat 27 pcs sementara 20 Kg memuat 36 pcs. Karena itu satuan
 * (`L` vs `KG`) ikut menentukan hasilnya, bukan cuma angkanya.
 *
 * ATURANNYA SEKARANG DATA, BUKAN KODE. Dulu daftarnya tertanam di kelas ini,
 * sehingga ukuran baru berarti menunggu rilis kode — dan 316 produk yang
 * ukurannya tidak tercakup berdiri tanpa kapasitas palet sama sekali. Sekarang
 * ia tinggal di `pallet_capacity_rules` dan diatur Super Admin lewat Setelan
 * Operasional.
 *
 * DUA TINGKAT PENCOCOKAN. Aturan yang menyebut wadah (`uom`) menang atas yang
 * tidak. "20 L PAIL" bisa berbeda dari "20 L TIN" — dua wadah yang tidak
 * menumpuk sama di atas palet — tanpa memaksa siapa pun mengetik ulang angka
 * yang sama untuk setiap wadah yang pernah dipakai.
 *
 * UKURAN DI LUAR DAFTAR TETAP MENGEMBALIKAN NULL, bukan menebak angka
 * terdekat: salah menghitung kapasitas palet berarti salah membentuk palet di
 * lantai gudang. Produk semacam itu ditandai agar dilengkapi.
 *
 * DIBACA DI SETIAP PEMBENTUKAN PALET, jadi di-cache selamanya dan dibuang saat
 * aturannya berubah. Kalau cache-nya atau tabelnya bermasalah, yang
 * dikembalikan NULL — bukan galat: aturan yang gagal dibaca tidak boleh
 * menjatuhkan layar penerimaan barang.
 */
class PalletCapacity
{
    public const UNIT_LITER = 'L';

    public const UNIT_KILOGRAM = 'KG';

    public const UNITS = [self::UNIT_LITER, self::UNIT_KILOGRAM];

    private const CACHE_KEY = 'wms.pallet_capacity_rules';

    /**
     * Kapasitas palet untuk satu kombinasi satuan + ukuran (+ wadah).
     *
     * @param  string|null  $uom  wadahnya (PAIL/TIN/...). Bila diisi dan ada
     *                            aturan khusus untuknya, aturan itu yang dipakai.
     * @return int|null NULL bila kombinasinya tidak ada dalam aturan gudang
     */
    public static function resolve(?string $unit, int|float|string|null $size, ?string $uom = null): ?int
    {
        if ($unit === null || $size === null || $size === '') {
            return null;
        }

        $aturan = self::aturan();

        $unit = mb_strtoupper(trim($unit));
        $ukuran = self::kunciUkuran($size);

        // Yang menyebut wadah lebih dulu: aturan khusus mengalahkan yang umum.
        if ($uom !== null && $uom !== '') {
            $khusus = $aturan[$unit.'|'.$ukuran.'|'.mb_strtoupper(trim($uom))] ?? null;

            if ($khusus !== null) {
                return $khusus;
            }
        }

        return $aturan[$unit.'|'.$ukuran.'|'] ?? null;
    }

    /**
     * Daftar ukuran yang dikenal untuk satu satuan — dipakai pesan bantuan di
     * form agar yang mengisi tahu ukuran apa saja yang terhitung otomatis.
     *
     * @return list<string>
     */
    public static function knownSizes(string $unit): array
    {
        $unit = mb_strtoupper(trim($unit));
        $hasil = [];

        foreach (array_keys(self::aturan()) as $kunci) {
            [$satuan, $ukuran] = explode('|', $kunci);

            if ($satuan === $unit) {
                $hasil[$ukuran] = true;
            }
        }

        $hasil = array_keys($hasil);
        sort($hasil, SORT_NATURAL);

        return $hasil;
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

    public static function lupakan(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Ukuran dijadikan string 3 desimal supaya 20 dan 20.000 dianggap sama. */
    public static function kunciUkuran(int|float|string $size): string
    {
        return number_format((float) $size, 3, '.', '');
    }

    /* -------------------------------------------------------------- Dalam */

    /**
     * Seluruh aturan, berkunci "SATUAN|UKURAN|WADAH".
     *
     * Wadah kosong berarti aturan umum, jadi kuncinya berakhir dengan "|".
     *
     * @return array<string, int>
     */
    private static function aturan(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, function (): array {
                $hasil = [];

                foreach (PalletCapacityRule::query()->get() as $baris) {
                    $kunci = $baris->pack_unit
                        .'|'.self::kunciUkuran($baris->pack_size)
                        .'|'.($baris->uom === null ? '' : mb_strtoupper($baris->uom));

                    $hasil[$kunci] = (int) $baris->max_qty_per_pallet;
                }

                return $hasil;
            });
        } catch (Throwable) {
            // Tabelnya belum ada (migrasi baru), atau basis datanya sedang
            // bermasalah. Kapasitas yang gagal dibaca terbaca sebagai "belum
            // diketahui" — dan itu memang keadaan yang sudah ditangani layar.
            return [];
        }
    }
}
