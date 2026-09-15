<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setelan operasional yang boleh diubah Super Admin — Fase 10.
 *
 * SATU BARIS PER SETELAN, bukan satu baris berisi seluruh setelan sebagai
 * JSON. Dua orang yang menyimpan setelan berbeda pada detik yang sama akan
 * saling menimpa kalau semuanya tinggal di satu baris — dan yang kalah tidak
 * akan pernah tahu setelannya hilang.
 *
 * NILAINYA TEKS, TIPENYA ADA DI KODE. App\Support\Settings memegang daftar
 * setelan yang dikenal beserta tipe, batas, dan nilai bawaannya; tabel ini
 * hanya menyimpan yang SUDAH DIUBAH dari bawaannya. Konsekuensi yang
 * disengaja: setelan yang dihapus dari kode tidak akan pernah terbaca lagi
 * walau barisnya masih ada, dan setelan baru langsung punya nilai bawaan
 * tanpa perlu migrasi pengisi.
 *
 * TIDAK ADA warehouse_id. Kelimanya aturan seluruh perusahaan — jam cutoff
 * yang berbeda per gudang berarti Sales yang melayani dua gudang harus
 * mengingat dua jam berbeda. Kalau suatu hari benar-benar dibutuhkan, kolom
 * itu ditambahkan bersama keputusannya, bukan disiapkan sekarang untuk
 * kebutuhan yang belum ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();

            $table->string('key', 60)->unique();
            $table->text('value');

            // Siapa yang terakhir mengubah. Riwayat lengkapnya ada di
            // activity_logs — kolom ini hanya supaya halamannya bisa
            // menyebutkannya tanpa query tambahan.
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
