<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Melengkapi tabel `users` bawaan Laravel menjadi tabel manajemen user
 * PT Berger Paints.
 *
 * Ditulis sebagai migration tambahan (bukan mengubah 0001_01_01_000000) agar
 * tabel `roles`, `departments`, dan `warehouses` sudah ada lebih dulu saat
 * foreign key dipasang, dan agar riwayat perubahan skema tetap terbaca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // --- Identitas karyawan ---
            // Nullable pada tahap ini supaya baris lama (bila ada) tidak menggagalkan
            // migration; keunikan tetap dijaga dan validasi "wajib" ditegakkan di
            // Form Request. Lihat catatan di bawah kelas ini.
            $table->string('employee_id', 50)->nullable()->unique()->after('id')
                ->comment('NIK resmi dari HRD, contoh: EMP-2023-019');

            $table->renameColumn('name', 'full_name');
            $table->string('phone_number', 20)->nullable()->after('email');
            $table->string('avatar_path', 255)->nullable();

            // --- Penempatan & hak akses ---
            $table->foreignId('role_id')->nullable()->constrained('roles')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();

            // NULL berarti akses lintas gudang (mis. Direksi/Super Admin).
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            // Self-reference untuk alur approval berjenjang.
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();

            // --- Status akun ---
            $table->boolean('is_active')->default(true)
                ->comment('false = resign/nonaktif; data historis tetap tersimpan');
            $table->timestamp('last_login_at')->nullable();

            // --- Jejak audit ---
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->softDeletes();
        });

        // Kolom keamanan sesuai docs/2_database_design.md §3.1.
        //
        // Progressive lockout (PRD §6.1 F-AUTH-03): 3 kali percobaan gagal —
        // password salah ATAU verifikasi anti-bot gagal, satu counter bersama —
        // mengunci akun 5/10/30/60/120 menit secara progresif.
        //
        // Tidak ada kolom MFA/TOTP: PRD v1.2 memakai Google reCAPTCHA yang
        // memverifikasi manusia vs bot per-request dan tidak menyimpan state
        // apa pun per-user.
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_lockout_at')->nullable();
            $table->unsignedSmallInteger('lockout_count')->default(0);
        });

        // Panjang kolom disesuaikan dengan spesifikasi. renameColumn mempertahankan
        // tipe asli bawaan Laravel (varchar 255), jadi perlu diubah eksplisit.
        Schema::table('users', function (Blueprint $table) {
            $table->string('full_name', 150)->change();
            $table->string('email', 150)->change();
        });

        // Index untuk pola query yang paling sering dipakai halaman Manajemen User:
        // filter daftar user per gudang dan per role, serta pencarian user aktif.
        Schema::table('users', function (Blueprint $table) {
            $table->index(['is_active', 'role_id']);
            $table->index('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'role_id']);
            $table->dropIndex(['warehouse_id']);

            $table->dropConstrainedForeignId('role_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropConstrainedForeignId('manager_id');
            $table->dropConstrainedForeignId('created_by');

            $table->dropColumn([
                'employee_id',
                'phone_number',
                'avatar_path',
                'is_active',
                'last_login_at',
                'failed_login_attempts',
                'locked_until',
                'last_lockout_at',
                'lockout_count',
                'deleted_at',
            ]);

            $table->renameColumn('full_name', 'name');
        });
    }
};
