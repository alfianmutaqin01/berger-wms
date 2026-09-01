<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master SKU produk (PRD §6.2 F-MASTER-02, docs/2 §3.2).
 *
 * TIDAK menyimpan jumlah stok. Kolom "Inventory" pada ekspor ERP adalah hasil
 * penjumlahan, bukan data master — di sistem ini stok tinggal di
 * `inventory_stocks` (per gudang, per lokasi, per batch, per tanggal
 * kedaluwarsa) supaya FIFO dan aturan expiry bisa berjalan. Angka stok pada
 * layar dihitung dari sana lewat SUM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // SKU dari ERP, contoh: ID1-F00113202203. Nilainya adalah gabungan
            // prefix + product_code + shade_code + pack_code (lihat Product::buildSku).
            $table->string('sku', 50)->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();

            // Tiga komponen penyusun SKU. Disimpan terpisah agar bisa difilter
            // (mis. "semua warna 3202") tanpa membedah string SKU.
            $table->string('product_code', 10);
            $table->string('shade_code', 10);
            $table->string('pack_code', 10);

            $table->foreignId('category_id')->nullable()
                ->constrained('product_categories')->nullOnDelete();

            // Satuan kemasan dari ERP: KG, TIN, PAI, CAN, dst.
            $table->string('uom', 20);

            // Ukuran kemasan NOMINAL (ukuran wadah) + satuannya. Inilah yang
            // menentukan kapasitas palet, BUKAN `unit_volume`: pail "20Ltr"
            // bisa berisi 19.4 L saja karena menyisakan ruang untuk pewarna,
            // sementara paletnya tetap memuat 27 pail. Satuan wajib eksplisit
            // karena 20 L (27 pcs) dan 20 Kg (36 pcs) berbeda kapasitasnya.
            $table->decimal('pack_size', 10, 3)->nullable();
            $table->string('pack_unit', 2)->nullable();

            // Angka apa adanya dari ERP — volume isi & bobot sebenarnya.
            // Dipakai untuk perencanaan muatan, bukan untuk aturan palet.
            $table->decimal('unit_volume', 10, 3)->nullable();
            $table->decimal('net_weight', 10, 3)->nullable();
            $table->decimal('gross_weight', 10, 3)->nullable();

            // Nullable karena tidak semua ukuran kemasan ada di aturan gudang
            // (lihat App\Support\PalletCapacity). Produk yang belum terisi
            // ditandai di layar agar Manager melengkapinya manual — lebih baik
            // kosong daripada diisi angka tebakan.
            $table->unsignedInteger('max_qty_per_pallet')->nullable();

            $table->unsignedSmallInteger('shelf_life_months')->default(30);
            $table->unsignedInteger('stock_threshold_low')->default(50);

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Pola pencarian yang paling sering dipakai halaman Master Produk.
            $table->index(['is_active', 'category_id']);
            $table->index('product_code');
            $table->index('shade_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
