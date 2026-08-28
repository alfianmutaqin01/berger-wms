<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\PalletCapacity;
use Illuminate\Database\Seeder;

/**
 * Produk contoh — disalin apa adanya dari ekspor ERP Berger.
 *
 * Kolom "Inventory" pada ekspor tersebut (108, 126, 72, 0, 3) sengaja TIDAK
 * ikut dimasukkan: jumlah stok bukan data master, melainkan hasil penjumlahan
 * `inventory_stocks` per gudang/lokasi/batch (dibangun pada Fase 4).
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $royale = ProductCategory::where('name', 'Royale Emulsion')->first();

        // [product_code, shade_code, pack_code, nama, uom, pack_unit, volume, net, gross]
        $rows = [
            ['0011', '3202', '203', 'Royale Smart Clean White 0.25Ltr', 'KG', null, null, null, null],
            ['0011', '3202', '225', 'Royale Smart Clean White 2.5Ltr', 'TIN', PalletCapacity::UNIT_LITER, 2.5, null, 4.05],
            ['0011', '3202', '320', 'Royale Smart Clean White 20Ltr', 'PAI', PalletCapacity::UNIT_LITER, 20, null, 26.79],
            ['0011', 'A183', '225', 'Royale Smart Clean L1313 2.5Ltr', 'TIN', PalletCapacity::UNIT_LITER, 2.5, null, 4.08],
            ['0011', 'B050', '320', 'Royale Smart Clean Vanilla Sky 20Ltr', 'PAI', PalletCapacity::UNIT_LITER, 20, null, 27.02],
        ];

        foreach ($rows as [$productCode, $shadeCode, $packCode, $name, $uom, $packUnit, $volume, $net, $gross]) {
            Product::firstOrCreate(
                ['sku' => Product::buildSku($productCode, $shadeCode, $packCode)],
                [
                    'name' => $name,
                    'product_code' => $productCode,
                    'shade_code' => $shadeCode,
                    'pack_code' => $packCode,
                    'category_id' => $royale?->id,
                    'uom' => $uom,
                    'pack_unit' => $packUnit,
                    'unit_volume' => $volume,
                    'net_weight' => $net,
                    'gross_weight' => $gross,
                    // Baris pertama (0.25 Ltr) tidak punya ukuran kemasan yang
                    // terdaftar di aturan palet, jadi nilainya NULL dan akan
                    // ditandai di layar agar Manager melengkapinya.
                    'max_qty_per_pallet' => PalletCapacity::resolve($packUnit, $volume ?? $net),
                    'shelf_life_months' => 30,
                    'stock_threshold_low' => 50,
                    'is_active' => true,
                ]
            );
        }
    }
}
