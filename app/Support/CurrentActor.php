<?php

namespace App\Support;

use App\Models\User;

/**
 * Menentukan user yang sedang bertindak: user yang login, atau null.
 *
 * Dipertahankan sebagai SATU-SATUNYA tempat penentuan aktor, supaya
 * controller, Form Request, dan Blade tidak memanggil `auth()` tersebar di
 * banyak tempat.
 *
 * TIDAK ADA LAGI JALUR CADANGAN. Dulu, di luar production, tamu diperlakukan
 * sebagai Super Admin hasil seed dan `?as=<role>` bisa menyamar menjadi role
 * lain — sisa alat bantu sebelum login nyata ada. Pagarnya hanya
 * `APP_ENV=production`: satu salah ketik di .env server (mis. `staging`)
 * sudah cukup membuat siapa pun yang tidak login bertindak sebagai Super
 * Admin. Pengaman yang bergantung pada satu nilai konfigurasi yang benar
 * bukan pengaman, jadi jalurnya dihapus.
 *
 * Satu-satunya pintu masuk kini AuthController, dan Role Switcher di navbar
 * sudah dihapus sejak login sungguhan aktif.
 */
class CurrentActor
{
    private static ?User $cached = null;

    public static function get(): ?User
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $user = auth()->user();

        return $user === null ? null : self::$cached = $user->loadMissing('role');
    }

    /** Dipakai oleh test untuk menetapkan aktor secara eksplisit. */
    public static function fake(?User $user): void
    {
        self::$cached = $user;
    }

    public static function reset(): void
    {
        self::$cached = null;
    }
}
