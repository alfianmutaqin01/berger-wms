<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\PackSize;
use App\Support\PalletCapacity;
use Illuminate\Database\Seeder;

/**
 * Produk contoh — disalin apa adanya dari ekspor ERP Berger.
 *
 * Kolom "Inventory" pada ekspor tersebut (108, 126, 72, ...) sengaja TIDAK
 * ikut dimasukkan: jumlah stok bukan data master, melainkan hasil penjumlahan
 * `inventory_stocks` per gudang/lokasi/batch (dibangun pada Fase 4).
 *
 * Perhatikan `unit_volume` yang tidak bulat (19.4 untuk kemasan 20 Ltr, 2.425
 * untuk 2.5 Ltr): itu volume ISI sebenarnya, karena wadah tinting base sengaja
 * tidak diisi penuh agar ada ruang untuk pewarna. Kapasitas palet tetap
 * dihitung dari ukuran WADAH (`pack_size`), bukan dari volume isi ini.
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $regular = ProductCategory::where('name', 'Royale Smart Clean')->first();
        $base = ProductCategory::where('name', 'Royale Smart Clean - Base')->first();

        // [shade, pack, nama, uom, unit_volume, gross_weight, kategori]
        // Kategori NULL = kolom Product Type pada ERP berisi "Tidak ditemukan".
        $rows = [
            ['3202', '203', 'Royale Smart Clean White 0.25Ltr', 'KG', 0, 0, $regular],
            ['3202', '225', 'Royale Smart Clean White 2.5Ltr', 'TIN', 2.5, 4.05, $regular],
            ['3202', '320', 'Royale Smart Clean White 20Ltr', 'PAIL', 20, 26.79, $regular],
            ['A183', '225', 'Royale Smart Clean L1313 2.5Ltr', 'TIN', 2.5, 4.08, $regular],
            ['B050', '320', 'Royale Smart Clean Vanilla Sky 20Ltr', 'PAIL', 20, 27.02, $regular],
            ['B128', '320', 'Royale Smart Clean Blue Smoke 20Ltr', 'PAIL', 19.4, 26.32, $regular],
            ['B137', '320', 'Royale Smart Clean Solitaire 8500 20Ltr', 'PAIL', 18.4, 24, $regular],
            ['X000', '225', 'Royale Smart Clean 0 Base 2.5Ltr', 'TIN', 2.425, 3.99, null],
            ['X000', '320', 'Royale Smart Clean 0 Base 20Ltr', 'PAIL', 19.4, 26.32, null],
            ['X001', '225', 'Royale Smart Clean 1 Base 2.5Ltr', 'TIN', 2.425, 4.33, $base],
            ['X001', '320', 'Royale Smart Clean 1 Base 20Ltr', 'PAIL', 19.4, 26.25, $base],
            ['X002', '225', 'Royale Smart Clean 2 Base 2.5Ltr', 'TIN', 2.375, 3.9, $base],
            ['X002', '320', 'Royale Smart Clean 2 Base 20Ltr', 'PAIL', 19, 25.63, $base],
            ['X100', '225', 'Royale Smart Clean 100 Base 2.5Ltr', 'TIN', 2.3, 3.7, $base],
            ['X100', '320', 'Royale Smart Clean 100 Base 20Ltr', 'PAIL', 18.4, 24, $base],
        ];

        foreach ($rows as [$shade, $pack, $name, $uom, $volume, $gross, $category]) {
            $parsed = PackSize::parse($name);

            Product::firstOrCreate(
                ['sku' => Product::buildSku('0011', $shade, $pack)],
                [
                    'name' => $name,
                    'product_code' => '0011',
                    'shade_code' => $shade,
                    'pack_code' => $pack,
                    'category_id' => $category?->id,
                    'uom' => $uom,
                    'pack_size' => $parsed['size'] ?? null,
                    'pack_unit' => $parsed['unit'] ?? null,
                    'unit_volume' => $volume ?: null,
                    'net_weight' => null,
                    'gross_weight' => $gross ?: null,
                    'max_qty_per_pallet' => PalletCapacity::resolve($parsed['unit'] ?? null, $parsed['size'] ?? null),
                    'shelf_life_months' => 30,
                    'stock_threshold_low' => 50,
                    'is_active' => true,
                ]
            );
        }
    }
}
