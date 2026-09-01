<?php

namespace Database\Seeders;

use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

/**
 * Tipe produk ("Product Type" pada ekspor ERP).
 *
 * Nilainya diambil apa adanya dari kolom Product Type. Nilai "Tidak ditemukan"
 * pada ekspor TIDAK dibuatkan kategori — itu penanda bahwa pencarian kategori
 * di ERP gagal, bukan nama kategori. Produk seperti itu dibiarkan tanpa
 * kategori agar masalahnya terlihat, bukan tersamarkan jadi kategori palsu.
 */
class ProductCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Royale Smart Clean', 'description' => 'Cat tembok interior Royale Smart Clean'],
            ['name' => 'Royale Smart Clean - Base', 'description' => 'Base tinting untuk Royale Smart Clean'],
        ];

        foreach ($categories as $category) {
            ProductCategory::firstOrCreate(
                ['name' => $category['name']],
                $category + ['is_active' => true]
            );
        }
    }
}
