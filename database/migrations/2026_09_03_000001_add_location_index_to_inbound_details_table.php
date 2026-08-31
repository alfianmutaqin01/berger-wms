<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kunci "satu bin = satu palet" (PRD §6.3 F-INB-02, direvisi 2026-08-31).
 *
 * Keputusan Fase 4 lama sempat menyatakan satu bin boleh memuat beberapa
 * produk & batch sekaligus. Itu dibatalkan: satu rak fisik hanya memuat satu
 * palet. UNIQUE di sini menjadi penjaga terakhir di lapisan basis data,
 * setelah validasi di InboundController::putawayStore.
 *
 * Unik biasa (bukan partial index) sudah cukup: PostgreSQL memperlakukan
 * setiap NULL sebagai berbeda, jadi banyak palet yang BELUM ditempatkan
 * (location_id NULL) tidak akan saling bentrok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_details', function (Blueprint $table) {
            $table->unique('location_id');
        });
    }

    public function down(): void
    {
        Schema::table('inbound_details', function (Blueprint $table) {
            $table->dropUnique(['location_id']);
        });
    }
};
