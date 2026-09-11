<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lonceng notifikasi — Fase 9.
 *
 * SATU BARIS PER PENERIMA, bukan satu baris per kejadian yang dibaca banyak
 * orang. Yang dibutuhkan lonceng adalah "sudah saya baca belum", dan itu
 * melekat pada ORANG, bukan pada kejadiannya. Menyimpan satu baris bersama
 * lalu menaruh status baca di tabel penghubung terdengar lebih hemat, tetapi
 * setiap pembacaan lonceng — yang terjadi di setiap halaman — jadi perlu
 * join, dan jumlah penerimanya di sini paling banyak belasan orang per
 * kejadian. Tidak sepadan.
 *
 * TIDAK MEMAKAI TABEL notifications BAWAAN LARAVEL. Bawaannya berkunci UUID
 * dengan kolom `data` json dan `notifiable_type` polimorfik — dirancang untuk
 * mengirim ke banyak saluran (mail, sms, database). Yang dibutuhkan di sini
 * cuma satu saluran, dan kolom yang jelas namanya jauh lebih mudah dibaca
 * orang yang membuka tabelnya enam bulan lagi.
 *
 * `url` DISIMPAN, bukan disusun ulang saat menampilkan. Notifikasi menunjuk
 * ke halaman yang alamatnya bisa berubah; menyimpan hasil jadinya membuat
 * pemberitahuan lama tetap bisa diklik walau rutenya sudah dipindah — dan
 * kalau halamannya benar-benar hilang, yang muncul 404 yang jujur, bukan
 * galat saat merender lonceng di SETIAP halaman.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('type', 60);
            $table->string('title', 150);
            $table->text('body');
            $table->string('url', 255)->nullable();

            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Pertanyaan yang ditanyakan di SETIAP halaman: "berapa yang belum
            // saya baca". Indeks parsial, karena yang sudah dibaca tidak
            // pernah ikut dihitung dan jumlahnya akan jauh lebih banyak.
            $table->index(['user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement('
            CREATE INDEX notifications_belum_dibaca
            ON notifications (user_id, created_at DESC)
            WHERE read_at IS NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
