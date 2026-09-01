<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel master departemen / divisi.
 *
 * Dipisah menjadi tabel tersendiri (bukan kolom enum) karena spesifikasi
 * menyebut `department_id` sebagai foreign key, dan daftar divisi perusahaan
 * dapat bertambah tanpa perlu mengubah kode maupun menjalankan migration baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 50)->unique();
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
