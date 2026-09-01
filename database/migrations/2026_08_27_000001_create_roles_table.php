<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel master peran pengguna (RBAC).
 *
 * Satu user memiliki tepat satu role (relasi one-to-many lewat users.role_id),
 * sesuai desain di docs/2_database_design.md §3.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->comment('Nama tampilan, contoh: "Super Admin"');
            $table->string('slug', 50)->unique()->comment('Kode internal dipakai di kode & middleware, contoh: "super_admin"');
            $table->string('description', 255)->nullable();

            // Menentukan urutan tampil di dropdown sekaligus hierarki wewenang.
            // Angka lebih kecil = wewenang lebih tinggi.
            $table->unsignedSmallInteger('level')->default(99);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
