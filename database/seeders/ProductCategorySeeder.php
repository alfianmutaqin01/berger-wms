<?php

namespace Database\Seeders;

use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

/**
 * Tipe produk ("Product Type") — mengikuti pilihan yang sudah ada di halaman
 * Master Produk. Daftar ini masih perlu dilengkapi sesuai katalog Berger.
 */
class ProductCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Alk Primer', 'description' => 'Cat dasar berbasis alkyd'],
            ['name' => 'AMC', 'description' => 'Acrylic Multi Coat'],
            ['name' => 'Apex Emulsion', 'description' => 'Cat tembok eksterior'],
            ['name' => 'Royale Emulsion', 'description' => 'Cat tembok interior premium'],
            ['name' => 'Wood & Metal', 'description' => 'Cat kayu dan besi'],
        ];

        foreach ($categories as $category) {
            ProductCategory::firstOrCreate(
                ['name' => $category['name']],
                $category + ['is_active' => true]
            );
        }
    }
}
