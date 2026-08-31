<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indeks penunjang put-away (PRD §6.3 F-INB-02).
 *
 * Layar put-away menghitung isi tiap bin untuk ditampilkan ke Operator, dan
 * denah rak nanti melakukan hal yang sama untuk 2.264 bin sekaligus. Tanpa
 * indeks ini, keduanya memindai seluruh tabel palet.
 *
 * BUKAN unique: satu bin adalah satu SLOT PALET, dan boleh memuat BEBERAPA
 * baris inbound_details dari SKU yang SAMA sampai kapasitas palet SKU itu
 * (Product::max_qty_per_pallet) — pallet split (PRD §7.1) boleh digabung
 * kembali di bin yang sama. Batas kapasitas & larangan campur SKU ditegakkan
 * di aplikasi (InboundController::putawayStore), bukan di lapisan ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_details', function (Blueprint $table) {
            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::table('inbound_details', function (Blueprint $table) {
            $table->dropIndex(['location_id']);
        });
    }
};
