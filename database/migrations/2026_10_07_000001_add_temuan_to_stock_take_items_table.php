<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Barang yang DITEMUKAN di rak tetapi tidak ada di sistem.
 *
 * MASALAHNYA
 * ----------
 * Layar penghitungan hanya memuat baris yang sudah ada di `inventory_stocks`.
 * Operator yang berdiri di depan rak dan menemukan satu palet yang tidak ada
 * di daftar tidak punya tempat menuliskannya sama sekali. Yang terjadi
 * selanjutnya bisa ditebak: dicatat di kertas, lalu hilang.
 *
 * Kebalikannya sudah lama bisa — barang yang ada di sistem tetapi tidak ada di
 * rak tinggal dihitung 0. Yang belum bisa justru arah yang menambah stok.
 *
 * KENAPA PERLU PENANDA SENDIRI
 * ----------------------------
 * `inventory_stock_id IS NULL` TIDAK cukup untuk mengenali baris temuan.
 * Kolom itu ber-ON DELETE SET NULL, jadi baris biasa yang batch stoknya habis
 * dan dibersihkan di tengah sesi juga berakhir NULL. Keduanya harus
 * diperlakukan berlawanan saat pengesahan: yang satu tidak menghasilkan apa
 * pun, yang lain justru MELAHIRKAN baris stok baru. Menebaknya dari NULL cepat
 * atau lambat akan menciptakan stok dari batch yang justru sudah habis.
 *
 * KENAPA TANGGAL PRODUKSI IKUT DIMINTA
 * ------------------------------------
 * `inventory_stocks.production_date` dan `expiry_date` keduanya NOT NULL, dan
 * kedaluwarsa dihitung dari tanggal produksi. Menebaknya dengan "hari ini"
 * akan memberi palet lama umur simpan bertahun-tahun yang tidak pernah ia
 * miliki — dan barang itu akan dijual paling akhir oleh FIFO, tepat kebalikan
 * dari yang seharusnya. Tanggalnya tercetak di palet; yang menemukannya sedang
 * berdiri di depannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_take_items', function (Blueprint $table) {
            $table->boolean('is_found')->default(false)->after('inventory_stock_id');
            $table->date('found_production_date')->nullable()->after('is_found');
        });

        // Baris temuan selalu berangkat dari nol: kalau batch itu sudah ada di
        // sistem, ia bukan temuan melainkan baris hitungan biasa yang tinggal
        // diisi. Tanpa CHECK ini, baris temuan ber-qty_system bukan nol akan
        // menerapkan selisih ke stok yang belum ada.
        DB::statement(<<<'SQL'
            ALTER TABLE stock_take_items
            ADD CONSTRAINT stock_take_items_temuan_lengkap CHECK (
                (is_found = false AND found_production_date IS NULL)
                OR
                (is_found = true AND found_production_date IS NOT NULL AND qty_system = 0)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_take_items DROP CONSTRAINT IF EXISTS stock_take_items_temuan_lengkap');

        Schema::table('stock_take_items', function (Blueprint $table) {
            $table->dropColumn(['is_found', 'found_production_date']);
        });
    }
};
