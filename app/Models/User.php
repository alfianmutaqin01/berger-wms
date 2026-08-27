<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Pengguna sistem (karyawan PT Berger Paints).
 *
 * Setiap user memiliki tepat satu Role. Penonaktifan memakai flag `is_active`,
 * bukan penghapusan, agar jejak kerja historis tetap dapat ditelusuri.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'full_name',
        'email',
        'password',
        'phone_number',
        'avatar_path',
        'role_id',
        'department_id',
        'warehouse_id',
        'manager_id',
        'is_active',
        'created_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'google2fa_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_lockout_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_mfa_enabled' => 'boolean',
            // Secret TOTP tidak boleh tersimpan sebagai teks polos: siapa pun yang
            // bisa membaca database akan mampu membangkitkan kode MFA korban.
            'google2fa_secret' => 'encrypted',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relasi
    |--------------------------------------------------------------------------
    */

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** Atasan langsung, untuk alur approval berjenjang. */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    /** Bawahan langsung. */
    public function subordinates(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Pemeriksaan peran
    |--------------------------------------------------------------------------
    */

    public function hasRole(string $slug): bool
    {
        return $this->role?->slug === $slug;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN);
    }

    /**
     * Apakah user ini boleh mengelola akun lain?
     *
     * PRD §6.2 F-MASTER-01 — hak CRUD user dimiliki Super Admin dan Manager.
     */
    public function canManageUsers(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN) || $this->hasRole(Role::MANAGER);
    }

    /**
     * Apakah user ini boleh mengubah akun $target?
     *
     * Manager boleh mengelola semua role KECUALI Super Admin. Aturan ini menutup
     * celah eskalasi hak akses: tanpa pemeriksaan ini, seorang Manager dapat
     * menyunting akun Super Admin mana pun dan mengambil alih sistem.
     */
    public function canManage(User $target): bool
    {
        if (! $this->canManageUsers()) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        return ! $target->isSuperAdmin();
    }

    /*
    |--------------------------------------------------------------------------
    | Progressive lockout (PRD §6.1 F-AUTH-03)
    |--------------------------------------------------------------------------
    */

    public function isCurrentlyLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Catat satu percobaan gagal (password salah ATAU verifikasi anti-bot
     * gagal — keduanya berbagi counter yang sama, PRD §6.1 F-AUTH-03). Mengunci
     * akun begitu counter mencapai 3, dengan durasi yang meningkat setiap kali
     * akun terkunci lagi setelah unlock sebelumnya (5 -> 10 -> 30 -> 60 -> 120 menit).
     *
     * `lockout_count` sengaja TIDAK direset di sini — itu riwayat berapa kali
     * akun ini pernah terkunci, dan hanya Super Admin yang boleh menuntaskannya
     * lewat unlock manual (lihat PRD: "Reset lockout: Hanya Super Admin...").
     */
    public function registerFailedLogin(): void
    {
        $this->failed_login_attempts++;

        if ($this->failed_login_attempts >= 3) {
            $this->lockout_count++;
            $this->locked_until = now()->addMinutes(self::lockoutDurationMinutes($this->lockout_count));
            $this->last_lockout_at = now();
        }

        $this->save();
    }

    public function registerSuccessfulLogin(): void
    {
        $this->failed_login_attempts = 0;
        $this->last_login_at = now();
        $this->save();
    }

    private static function lockoutDurationMinutes(int $lockoutCount): int
    {
        return match (true) {
            $lockoutCount <= 1 => 5,
            $lockoutCount === 2 => 10,
            $lockoutCount === 3 => 30,
            $lockoutCount === 4 => 60,
            default => 120,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Scope & accessor
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Pencarian bebas untuk kolom cari di halaman Manajemen User.
     *
     * ILIKE dipakai agar pencarian tidak peka huruf besar/kecil di PostgreSQL.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('full_name', 'ILIKE', $like)
                ->orWhere('email', 'ILIKE', $like)
                ->orWhere('employee_id', 'ILIKE', $like);
        });
    }

    /** Inisial untuk avatar teks, contoh: "Khoirun Nisa" -> "KN". */
    public function getInitialsAttribute(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->full_name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return '?';
        }

        $first = mb_substr($parts[0], 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /** Label lokasi tugas; NULL berarti tidak dibatasi ke satu gudang. */
    public function getWarehouseLabelAttribute(): string
    {
        return $this->warehouse?->display_label ?? 'Semua Gudang';
    }
}
