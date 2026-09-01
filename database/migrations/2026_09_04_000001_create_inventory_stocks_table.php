<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stok aktual di gudang (PRD §6.4, docs/2 §3.4).
 *
 * SATU BARIS = satu kombinasi produk × lokasi × batch. Pemecahan inilah yang
 * membuat FIFO (§7.2) dan aturan kedaluwarsa (§7.2.1) bisa berjalan: stok
 * tertua harus bisa keluar duluan, jadi batch tidak boleh dilebur jadi satu
 * angka.
 *
 * JANGAN menambahkan unique constraint pada (location_id) atau
 * (location_id, product_id). Aturan "satu bin = satu SKU sampai kapasitas"
 * ditegakkan saat PUT-AWAY (App\Support\Inbound\BinAllocator), bukan di sini —
 * satu bin tetap boleh memuat beberapa BATCH dari SKU yang sama, dan tiap
 * batch wajib jadi baris tersendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            // Didenormalisasi dari locations.warehouse_id: query FIFO selalu
            // menyaring per gudang, dan join ke locations untuk itu saja mahal.
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();

            $table->string('batch_no', 50);

            $table->integer('qty_available')->default(0);
            $table->integer('qty_allocated')->default(0);

            $table->date('production_date');
            // production_date + products.shelf_life_months, dihitung saat stok
            // diaktifkan. Disimpan (bukan dihitung saat query) supaya perubahan
            // masa simpan di Master Produk tidak diam-diam menggeser tanggal
            // kedaluwarsa batch yang sudah telanjur ada di rak.
            $table->date('expiry_date');

            $table->string('status', 20)->default('active');
            // 'EXPIRED', 'RETURN_DAMAGED', 'WRITE_OFF', 'OPNAME'
            $table->string('ddp_reason', 100)->nullable();

            $table->foreignId('inbound_detail_id')->nullable()
                ->constrained('inbound_details')->nullOnDelete();

            // FK-nya SENGAJA belum dipasang: tabel sales_return_details baru
            // dibuat di Fase 7. Memasang constraint sekarang berarti migration
            // ini menunjuk tabel yang belum ada (lihat catatan sirkular
            // docs/2 §8). Constraint susulan dibuat di Fase 7.
            $table->unsignedBigInteger('sales_return_detail_id')->nullable();

            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at');

            $table->timestamps();

            // Pola query FIFO: stok aktif satu produk di satu gudang,
            // diurutkan dari yang paling tua.
            $table->index(['product_id', 'warehouse_id', 'status', 'production_date'], 'inventory_stocks_fifo_index');
            // Sweep harian batch kedaluwarsa + peringatan dini 90 hari.
            $table->index(['status', 'expiry_date']);
            $table->index('location_id');
            $table->index('batch_no');
        });

        // Stok minus dijaga di lapisan BASIS DATA, bukan cuma di aplikasi:
        // ini angka yang dipercaya keuangan, dan jalur tulis di masa depan
        // (alokasi, picking, retur) belum tentu semuanya lewat model.
        DB::statement('ALTER TABLE inventory_stocks ADD CONSTRAINT inventory_stocks_qty_available_non_negative CHECK (qty_available >= 0)');
        DB::statement('ALTER TABLE inventory_stocks ADD CONSTRAINT inventory_stocks_qty_allocated_non_negative CHECK (qty_allocated >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stocks');
    }
};
