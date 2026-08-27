<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

/**
 * Menentukan user yang sedang bertindak.
 *
 * Sejak Fase 1 (Autentikasi Nyata), `auth()->user()` sudah bisa diandalkan —
 * kelas ini tetap dipertahankan sebagai SATU-SATUNYA tempat penentuan aktor,
 * supaya controller, Form Request, dan Blade tidak perlu memanggil `auth()`
 * secara langsung tersebar di banyak tempat.
 *
 * Urutan penentuan:
 *   1. `auth()->user()` bila sudah login — jalur utama sejak Fase 1.
 *   2. Parameter `?as=<slug-role>` — sisa alat bantu dev, dipagari
 *      `app()->environment('production')`. Praktis tidak terjangkau lewat
 *      HTTP karena rute wms/sales sudah dibungkus middleware `auth`.
 *   3. Super Admin hasil seed sebagai fallback (juga hanya di luar production).
 *
 * @see dokumen arsitektur §5.3 — Role Switcher sudah dihapus.
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

        // Jalur ini praktis sudah tidak terjangkau lewat HTTP sejak Fase 1:
        // rute wms/sales kini dibungkus middleware `auth`, sehingga tamu
        // ditolak sebelum sempat mencapai baris ini. Pagar environment ini
        // adalah lapis pertahanan kedua, bukan satu-satunya pengaman.
        if (app()->environment('production')) {
            return self::$cached = null;
        }

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
