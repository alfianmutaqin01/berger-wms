<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cakupan wilayah per gudang — Fase 6 sisipan multi-gudang.
 *
 * MENGAPA BUKAN KOLOM `warehouse_id` DI `customers`
 * -------------------------------------------------
 * Satu wilayah boleh dilayani lebih dari satu gudang: Sumatera bisa dikirim
 * dari Karawang maupun Pekanbaru. Memberi setiap pelanggan satu gudang berarti
 * memaksa keputusan yang di lapangan memang tidak tunggal, dan 1.840 baris
 * pelanggan harus ditebak satu per satu. Yang dibatasi adalah CAKUPAN, bukan
 * kepemilikan.
 *
 * MENGAPA ADA `territory_mode`, BUKAN SEKADAR DAFTAR BARIS
 * --------------------------------------------------------
 * Karawang melayani SEMUA wilayah. Kalau itu ditulis sebagai 14 baris hasil
 * salinan daftar territory hari ini, maka territory ke-15 yang muncul besok
 * TIDAK terlayani gudang mana pun — pesanannya ditolak tanpa ada yang tahu
 * kenapa. Karena itu aturannya disimpan sebagai bentuknya, bukan sebagai
 * hasil perhitungannya saat ini:
 *
 *   all    : semua wilayah, tabel ini diabaikan          (Karawang)
 *   only   : HANYA wilayah yang terdaftar di tabel ini   (Pekanbaru)
 *   except : semua KECUALI yang terdaftar di tabel ini   (Surabaya)
 *
 * Dengan begitu wilayah baru otomatis masuk cakupan Karawang dan Surabaya,
 * dan tidak pernah diam-diam masuk cakupan Pekanbaru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            // Default 'all' sengaja: gudang yang baru dibuat lewat menu Admin
            // harus bisa dipakai, bukan langsung buntu tanpa satu pun wilayah.
            $table->string('territory_mode', 10)->default('all')->after('address');
        });

        DB::statement("ALTER TABLE warehouses ADD CONSTRAINT warehouses_territory_mode_valid
            CHECK (territory_mode IN ('all', 'only', 'except'))");

        Schema::create('warehouse_territories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

            // Panjangnya menyamai customers.territory_code; nilainya memang
            // kode itu apa adanya, termasuk yang terpotong seperti "JAWA TENGA" (30 karakter, sama persis).
            $table->string('territory_code', 30);

            $table->timestamps();

            $table->unique(['warehouse_id', 'territory_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_territories');

        DB::statement('ALTER TABLE warehouses DROP CONSTRAINT IF EXISTS warehouses_territory_mode_valid');

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('territory_mode');
        });
    }
};
