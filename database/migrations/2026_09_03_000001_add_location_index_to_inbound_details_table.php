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
 * TIDAK dibuat unik: satu bin sengaja boleh memuat beberapa palet, bahkan dari
 * produk dan batch yang berbeda — sesuai kondisi nyata di gudang.
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
