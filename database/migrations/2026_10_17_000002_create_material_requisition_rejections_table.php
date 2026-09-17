<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat penolakan MRF — satu baris tiap kali sebuah permintaan dikembalikan.
 *
 * Permintaan yang ditolak kini boleh diperbaiki dan diajukan lagi dengan nomor
 * yang sama. Begitu itu terjadi, kolom penolakan di `material_requisitions`
 * dikosongkan supaya keadaan sekarangnya jujur — dan tanpa tabel ini, fakta
 * bahwa permintaan itu pernah ditolak akan lenyap bersamaan.
 *
 * Yang membacanya adalah atasan dan Logistik pada pengajuan berikutnya. Mereka
 * berhak tahu berkas di tangannya pernah ditolak dan karena apa; tanpa itu,
 * perbaikan yang tidak memperbaiki apa pun lolos hanya karena pembacanya lupa.
 *
 * `oleh` disimpan sebagai TEKS, bukan relasi. Penolakan atasan datang dari
 * orang yang tidak punya akun WMS sama sekali — ia menekan tautan WhatsApp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_requisition_rejections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_requisition_id')->constrained()->cascadeOnDelete();
            // 'approver' atau 'logistics' — dua pintu penolakan yang berbeda
            // pertanyaannya, dan pembacanya perlu tahu yang mana.
            $table->string('stage', 20);
            $table->text('reason');
            // Pengajuan ke berapa saat ditolak. Angka inilah yang membuat
            // "sudah tiga kali bolak-balik" terbaca tanpa menghitung baris.
            $table->unsignedSmallInteger('attempt_no')->default(1);
            $table->string('rejected_by_name', 100)->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at');
            $table->timestamps();

            $table->index(['material_requisition_id', 'rejected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_requisition_rejections');
    }
};
