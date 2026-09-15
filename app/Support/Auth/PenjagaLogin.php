<?php

namespace App\Support\Auth;

use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Kunci percobaan login — PRD §6.1 F-AUTH-03 (revisi v1.5, audit keamanan).
 *
 * MENGAPA TIDAK LAGI HANYA MENGUNCI AKUN.
 *
 * Rancangan awal mengunci AKUN setelah 3 kali gagal, dari mana pun asalnya.
 * Artinya siapa pun yang tahu email seseorang bisa mengunci akun itu dengan
 * tiga permintaan — tanpa perlu tahu sandinya. Pada hari go-live, seluruh
 * akun Logistik bisa dibuat terkunci berulang kali oleh orang di luar
 * perusahaan, dan gudang berhenti tanpa satu sandi pun bocor.
 *
 * Kini ada dua lapis:
 *
 *   1. EMAIL + IP (kelas ini) — 3 kali gagal mengunci pasangan itu saja,
 *      5 -> 10 -> 30 -> 60 -> 120 menit. Penyerang mengunci DIRINYA SENDIRI,
 *      bukan korbannya. Berlaku juga untuk email yang tidak terdaftar, supaya
 *      pesan "terlalu banyak percobaan" tidak membedakan email yang ada dari
 *      yang tidak.
 *
 *   2. AKUN (User::registerFailedLogin) — ambangnya lebih tinggi dan hanya
 *      dihitung dari IP yang BELUM PERNAH berhasil masuk ke akun itu. Inilah
 *      penahan tebak-sandi yang disebar ke banyak IP. Pemilik akun yang masuk
 *      dari IP yang biasa ia pakai (kantor, gudang) tidak ikut terkunci.
 *
 * Disimpan di cache (Redis di production), bukan tabel: umurnya menit, dan
 * kedaluwarsanya ditangani TTL tanpa perlu pembersihan terjadwal.
 */
final class PenjagaLogin
{
    /** Gagal berturut-turut dari satu IP untuk satu email sebelum dikunci. */
    public const AMBANG = 3;

    /** Penghitung gagal dilupakan bila tidak ada percobaan selama ini. */
    private const JENDELA_MENIT = 60;

    /** Tingkat kunci (penentu durasi) diingat selama ini. */
    private const INGAT_TINGKAT_JAM = 24;

    /** IP yang pernah berhasil masuk dalam rentang ini dianggap milik pemilik akun. */
    public const IP_DIKENAL_HARI = 30;

    private static ?string $sandiTiruan = null;

    public static function terkunciSampai(string $email, string $ip): ?Carbon
    {
        $sampai = Cache::get(self::kunci($email, $ip).':sampai');

        // is_numeric, bukan is_int: RedisStore mengembalikan angka sebagai
        // string, store array mengembalikannya sebagai int.
        return is_numeric($sampai) && (int) $sampai > now()->timestamp
            ? Carbon::createFromTimestamp((int) $sampai, config('app.timezone'))
            : null;
    }

    public static function catatGagal(string $email, string $ip): void
    {
        $kunci = self::kunci($email, $ip);

        // add() lalu increment(): add() hanya menulis bila kuncinya belum ada
        // dan memberi TTL; increment() di Redis atomik, jadi dua permintaan
        // serentak tidak saling menimpa hitungan.
        Cache::add($kunci.':gagal', 0, now()->addMinutes(self::JENDELA_MENIT));

        if (Cache::increment($kunci.':gagal') < self::AMBANG) {
            return;
        }

        Cache::add($kunci.':tingkat', 0, now()->addHours(self::INGAT_TINGKAT_JAM));
        $tingkat = (int) Cache::increment($kunci.':tingkat');

        $sampai = now()->addMinutes(User::durasiKunciMenit($tingkat));

        Cache::put($kunci.':sampai', $sampai->timestamp, $sampai);
        Cache::forget($kunci.':gagal');
    }

    public static function bersihkan(string $email, string $ip): void
    {
        $kunci = self::kunci($email, $ip);

        Cache::forget($kunci.':gagal');
        Cache::forget($kunci.':tingkat');
        Cache::forget($kunci.':sampai');
    }

    /** IP ini pernah dipakai pemilik akun untuk masuk dengan sah, belum lama ini. */
    public static function ipDikenal(User $user, string $ip): bool
    {
        return LoginAttempt::query()
            ->where('email', $user->email)
            ->where('is_successful', true)
            ->where('ip_address', $ip)
            ->where('created_at', '>=', now()->subDays(self::IP_DIKENAL_HARI))
            ->exists();
    }

    /**
     * Mencocokkan sandi — SELALU melewati satu bcrypt, termasuk untuk email
     * yang tidak terdaftar.
     *
     * Tanpa hash tiruan, email yang tidak ada dijawab puluhan milidetik lebih
     * cepat daripada email yang ada (bcrypt sengaja lambat), dan selisih itu
     * cukup untuk memetakan daftar email karyawan dari luar.
     */
    public static function sandiCocok(?User $user, string $sandi): bool
    {
        $hash = $user?->password ?? (self::$sandiTiruan ??= Hash::make(Str::random(40)));

        return Hash::check($sandi, $hash) && $user !== null;
    }

    private static function kunci(string $email, string $ip): string
    {
        return 'login:'.hash('sha256', Str::lower(trim($email)).'|'.$ip);
    }
}
