<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menghapus dua indeks pada products yang TIDAK MUNGKIN terpakai.
 *
 * products.product_code dan products.shade_code hanya pernah disentuh lewat
 * Product::scopeSearch(), dan scope itu memakai `ILIKE '%kata%'`. Indeks
 * B-tree tidak bisa melayani pola berawalan wildcard sama sekali — PostgreSQL
 * pasti mengabaikannya. Terbukti di pg_stat_user_indexes: idx_scan = 0
 * setelah 1.735 produk terimpor dan halaman pencarian dipakai.
 *
 * Yang tersisa hanyalah ongkosnya: setiap sisip/ubah baris products harus
 * ikut memperbarui dua indeks ini, dan impor master produk menulis ribuan
 * baris sekaligus.
 *
 * BUKAN penghematan besar (88 kB pada basis data 11 MB) — yang dibereskan
 * adalah beban tulis pada jalur impor, dan berkurangnya struktur yang
 * menyesatkan pembaca berikutnya seolah kolom itu dicari lewat indeks.
 *
 * Bila kelak product_code/shade_code dicari dengan kecocokan PERSIS
 * (mis. `where('product_code', $kode)`), indeksnya boleh dipasang lagi.
 * Untuk pencarian ILIKE, yang tepat adalah indeks GIN + ekstensi pg_trgm,
 * bukan B-tree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_product_code_index');
            $table->dropIndex('products_shade_code_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index('product_code');
            $table->index('shade_code');
        });
    }
};
