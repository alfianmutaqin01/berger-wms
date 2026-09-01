<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger mutasi stok (PRD §6.4, docs/2 §3.4).
 *
 * TABEL INI APPEND-ONLY. Tidak ada UPDATE, tidak ada DELETE — ini jejak audit
 * keuangan untuk stok. Koreksi dilakukan dengan MENAMBAH baris lawan, bukan
 * mengubah baris lama. Penegakannya ada di App\Models\StockMovement.
 *
 * Sengaja TANPA `updated_at`: kolom itu hanya masuk akal untuk baris yang bisa
 * berubah, dan keberadaannya justru mengundang orang mengubah baris ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            // NULL untuk mutasi yang tidak terikat rak tertentu.
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();

            // IN, OUT, ALLOCATED, DEALLOCATED, ADJUSTMENT,
            // TRANSFER_OUT, TRANSFER_IN, RETURN_IN
            $table->string('movement_type', 20);

            // Positif = menambah, negatif = mengurangi. qty_before/after
            // direkam apa adanya supaya ledger bisa diaudit tanpa harus
            // memutar ulang seluruh riwayat.
            $table->integer('qty_change');
            $table->integer('qty_before');
            $table->integer('qty_after');

            // 'inbound', 'sales_order', 'adjustment', 'stock_transfer', 'sales_return'
            $table->string('reference_type', 50);
            $table->unsignedBigInteger('reference_id');

            $table->string('batch_no', 50)->nullable();
            // Wajib diisi untuk ADJUSTMENT, TRANSFER, dan RETURN_IN —
            // ditegakkan di aplikasi karena "wajib" di sini bergantung tipe.
            $table->text('notes')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->nullable();

            $table->index(['product_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['warehouse_id', 'movement_type']);
            $table->index('batch_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
