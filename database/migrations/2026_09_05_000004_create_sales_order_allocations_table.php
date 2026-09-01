<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alokasi FIFO per item pesanan — docs/2 §3.5.
 *
 * Menghubungkan satu baris pesanan ke BATCH stok tertentu. Satu item bisa
 * terpenuhi dari beberapa batch sekaligus (pesan 100, batch tertua tinggal
 * 60, sisanya dari batch berikutnya), karena itu tabelnya berdiri sendiri
 * dan bukan sekadar kolom di sales_order_details.
 *
 * Baris di sini BARU DITULIS di Fase 6 saat Logistik menekan Approve.
 * Tabelnya dibuat sekarang bersama dua saudaranya supaya urutan migrasi
 * tetap satu paket dan relasinya bisa dipasang di model sejak awal.
 *
 * Inilah satu-satunya tempat yang tahu barang mana persisnya yang dijanjikan
 * ke pesanan mana. Tanpanya, membatalkan pesanan berarti menebak batch mana
 * yang harus dikembalikan qty_available-nya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_order_detail_id')->constrained()->cascadeOnDelete();

            // restrictOnDelete: baris stok yang sedang dijanjikan ke pesanan
            // tidak boleh lenyap begitu saja — jejak alokasinya harus utuh.
            $table->foreignId('inventory_stock_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('qty_allocated');

            // Hanya created_at. Alokasi bersifat catatan kejadian: kalau
            // jumlahnya berubah, yang benar adalah membatalkan lalu
            // mengalokasikan ulang, bukan menimpa angka lama.
            $table->timestamp('created_at')->nullable();

            $table->unique(['sales_order_detail_id', 'inventory_stock_id']);
            $table->index('inventory_stock_id');
        });

        DB::statement('ALTER TABLE sales_order_allocations ADD CONSTRAINT sales_order_allocations_qty_positive
            CHECK (qty_allocated > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_allocations');
    }
};
