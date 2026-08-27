<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menghapus sisa kolom MFA/TOTP dari tabel `users`.
 *
 * PRD v1.2 mengganti MFA (Google Authenticator/TOTP) dengan Verifikasi Anti-Bot
 * (Google reCAPTCHA v2). reCAPTCHA memverifikasi manusia vs bot pada form login
 * dan sama sekali tidak menyimpan state per-user, sehingga kedua kolom ini tidak
 * akan pernah terisi. Dihapus daripada dibiarkan menganggur dan menyesatkan.
 *
 * Kolom lockout (`failed_login_attempts`, `locked_until`, `last_lockout_at`,
 * `lockout_count`) TIDAK ikut dihapus — progressive lockout tetap berlaku
 * (PRD §6.1 F-AUTH-03) dan justru kini juga menghitung kegagalan verifikasi
 * anti-bot pada counter yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['google2fa_secret', 'is_mfa_enabled']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google2fa_secret', 255)->nullable();
            $table->boolean('is_mfa_enabled')->default(false);
        });
    }
};
