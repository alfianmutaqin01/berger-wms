<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lupa sandi lewat admin, dan sandi dari admin sebagai sandi sementara.
 *
 * must_change_password
 * --------------------
 * Menyala ketika sandi seseorang DIISI ORANG LAIN — akun baru yang dibuatkan
 * admin, atau sandi yang direset admin. Selama menyala, pemiliknya tidak bisa
 * memakai sistem sebelum membuat sandi baru sendiri.
 *
 * Sebelum ini sandi dari admin berlaku tanpa batas waktu. Artinya admin tahu
 * sandi orang itu selamanya, dan log aktivitas tidak bisa lagi membedakan
 * tindakan pemilik akun dari tindakan orang yang memakai sandinya.
 *
 * DEFAULT FALSE, dan itu disengaja: akun yang sudah ada tidak ikut dipaksa.
 * Yang terkena hanyalah sandi yang diisi admin SESUDAH migrasi ini.
 *
 * password_reset_requested_at
 * ---------------------------
 * Kapan pemilik akun terakhir menekan "Lupa sandi?". Dipakai untuk dua hal:
 * menandai akunnya di Manajemen Pengguna supaya admin tidak perlu mencari,
 * dan menahan permintaan berulang supaya satu orang yang menekan tombolnya
 * lima kali tidak membanjiri lonceng admin. Dikosongkan begitu admin mengisi
 * sandi barunya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->timestamp('password_reset_requested_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'password_reset_requested_at']);
        });
    }
};
