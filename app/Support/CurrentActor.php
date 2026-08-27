<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

/**
 * Menentukan user yang sedang bertindak.
 *
 * Modul autentikasi belum dibangun, sehingga belum ada `auth()->user()` yang bisa
 * diandalkan. Kelas ini menjadi SATU-SATUNYA tempat penentuan aktor, supaya ketika
 * login sungguhan nanti aktif, cukup satu berkas ini yang diubah — bukan tersebar
 * di controller, Form Request, dan Blade.
 *
 * Urutan penentuan:
 *   1. `auth()->user()` bila sudah login (sudah siap untuk masa depan).
 *   2. Parameter `?as=<slug-role>` — mendukung Role Switcher pada fase mockup.
 *   3. Super Admin hasil seed sebagai fallback.
 *
 * @see dokumen arsitektur §5.3 — Role Switcher wajib dihapus sebelum Go-Live.
 */
class CurrentActor
{
    private static ?User $cached = null;

    public static function get(): ?User
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        if ($user = auth()->user()) {
            return self::$cached = $user->loadMissing('role');
        }

        $query = User::with('role')->where('is_active', true);

        if ($slug = request()->query('as')) {
            $impersonated = (clone $query)
                ->whereHas('role', fn ($q) => $q->where('slug', $slug))
                ->first();

            if ($impersonated) {
                return self::$cached = $impersonated;
            }
        }

        return self::$cached = (clone $query)
            ->whereHas('role', fn ($q) => $q->where('slug', Role::SUPER_ADMIN))
            ->first();
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
