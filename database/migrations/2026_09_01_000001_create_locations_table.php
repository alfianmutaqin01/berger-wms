<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master lokasi bin gudang (PRD §6.2, docs/2 §3.2).
 *
 * Kode lokasi berpola [Rak]-[Level]-[Sel], contoh: B-01-01 = Rak B, Level 1,
 * Sel 1. Ketiga komponen tetap disimpan terpisah agar bisa diurutkan dan
 * difilter secara numerik — mengurutkan berdasarkan string kode akan
 * menempatkan "B-01-10" sebelum "B-01-02".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();

            // Kode lengkap seperti tertulis di lantai gudang: "B-01-01".
            $table->string('code', 20);

            // Komponen penyusun kode. `rack` bisa dua huruf (ZA–ZD).
            $table->string('rack', 5);
            $table->unsignedTinyInteger('level');
            $table->unsignedSmallInteger('cell');

            // Zona pergerakan barang: Fast / Slow / Middle Moving Area.
            // Dipakai strategi put-away — barang cepat laku ditempatkan di zona
            // yang paling dekat jalur keluar.
            $table->string('zone', 30)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Unik PER GUDANG, bukan global. Penamaan rak A/B/C lazim berulang
            // di gudang berbeda; memaksa unik global akan menolak gudang kedua
            // yang memakai penamaan sama.
            $table->unique(['warehouse_id', 'code']);

            // Pola query put-away: cari bin kosong pada zona tertentu di satu
            // gudang, diurutkan mendekati jalur keluar.
            $table->index(['warehouse_id', 'zone', 'is_active']);
            $table->index(['warehouse_id', 'rack', 'level', 'cell']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
