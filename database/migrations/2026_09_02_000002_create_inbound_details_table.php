<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rincian inbound — SATU BARIS PER PALET (PRD §6.3 F-INB-01, §7.1).
 *
 * Satu baris Excel dari Tim Produksi bisa menghasilkan beberapa baris di sini:
 * 235 pcs kemasan 5 Kg (maks 180/palet) menjadi dua palet — 180 dan 55.
 * Pemecahan dilakukan saat menyimpan agar Operator langsung mendapat daftar
 * palet fisik yang harus ditempatkan, bukan angka total yang harus dibagi
 * sendiri di lantai gudang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_details', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inbound_header_id')->constrained('inbound_headers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // Nomor order produksi dari kolom A berkas Excel (mis. RMO26080294).
            // Disimpan agar tiap palet bisa ditelusuri balik ke order produksinya.
            $table->string('production_order_no', 50)->nullable();

            // Nomor batch dari kolom E ("QC Number"). SENGAJA TIDAK UNIK:
            // beberapa order produksi berbeda bisa berbagi batch yang sama.
            $table->string('batch_no', 50);

            $table->unsignedInteger('total_qty');
            $table->unsignedSmallInteger('pallet_no');
            $table->unsignedInteger('pallet_qty');

            // Diisi Operator saat put-away (tahap berikutnya Fase 3).
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->unsignedInteger('qty_actual')->nullable();
            $table->foreignId('putaway_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('putaway_at')->nullable();

            // Diisi Logistik saat verifikasi Maker-Checker.
            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index(['inbound_header_id', 'pallet_no']);
            $table->index('batch_no');
            $table->index(['product_id', 'is_verified']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_details');
    }
};
