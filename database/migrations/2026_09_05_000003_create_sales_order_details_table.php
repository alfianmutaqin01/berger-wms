<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rincian item per pesanan — docs/2 §3.5.
 *
 * qty_approved dan lost_qty SENGAJA sudah ada di sini walau baru terisi di
 * Fase 6 (approval). Menambahkannya belakangan berarti mengubah tabel yang
 * sudah memuat data pesanan sungguhan.
 *
 * lost_qty = qty_ordered - qty_approved (PRD §7.3). Disimpan, bukan dihitung
 * saat query, karena angka ini dipakai laporan Outstanding dan harus tetap
 * mencerminkan keputusan saat approval sekalipun qty_ordered kelak dikoreksi.
 *
 * NAMA KOLOMNYA SUDAH BERUBAH. Migrasi ini sengaja dibiarkan menyebut
 * `lost_qty` karena itulah yang benar-benar dibuatnya; penggantian nama
 * menjadi `outstanding_qty` ada di migrasi 2026_09_12_000001. Mengubah
 * migrasi lama agar "terlihat rapi" berarti riwayatnya tidak lagi
 * mencerminkan apa yang pernah terjadi pada database yang sudah berjalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_details', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('qty_ordered');
            $table->unsignedInteger('qty_approved')->default(0);
            $table->unsignedInteger('qty_shipped')->default(0);
            $table->unsignedInteger('lost_qty')->default(0);

            $table->timestamps();

            // Satu produk hanya boleh muncul SEKALI dalam satu pesanan.
            // Dua baris SKU yang sama membuat cek stok saat approval
            // menghitung ketersediaan yang sama dua kali.
            $table->unique(['sales_order_id', 'product_id']);
        });

        // Qty pesanan tidak boleh nol — baris tanpa jumlah bukan pesanan.
        DB::statement('ALTER TABLE sales_order_details ADD CONSTRAINT sales_order_details_qty_ordered_positive
            CHECK (qty_ordered > 0)');

        // Yang disetujui tidak boleh melebihi yang dipesan; itu bukan
        // partial fulfillment lagi, melainkan mengirim barang yang tak diminta.
        DB::statement('ALTER TABLE sales_order_details ADD CONSTRAINT sales_order_details_approved_within_ordered
            CHECK (qty_approved <= qty_ordered)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_details');
    }
};
