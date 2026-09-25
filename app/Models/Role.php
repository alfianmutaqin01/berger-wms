<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Peran pengguna dalam sistem (RBAC).
 *
 * Slug dipakai sebagai acuan di kode, middleware, dan Blade. JANGAN membandingkan
 * peran menggunakan `name` atau `id` — keduanya bisa berubah lewat UI, sedangkan
 * slug bersifat tetap.
 */
class Role extends Model
{
    use HasFactory;

    /** Slug baku, sesuai seed di docs/2_database_design.md §7.1. */
    public const SUPER_ADMIN = 'super_admin';

    public const MANAGER = 'manager';

    public const LOGISTICS = 'logistics';

    public const PRODUCTION = 'production';

    public const WAREHOUSE_OPERATOR = 'warehouse_operator';

    public const SALES = 'sales';

    /**
     * Customer Account — orang Kantor Pusat, BUKAN akun milik pelanggan.
     *
     * Namanya memang begitu di perusahaan ini, dan itu menyimpan jebakan:
     * dibaca sekilas ia terdengar seperti akun yang dipegang customer dari
     * luar. Ia bukan. CA adalah pegawai HO yang menerima Surat Jalan fisik
     * dari gudang dan memastikan lembarnya lengkap. Karena itu labelnya di
     * layar selalu ditulis lengkap dengan keterangan kantor pusat — lihat
     * RoleSeeder — supaya tidak ada yang membuatkan akun ini untuk pelanggan.
     */
    public const CUSTOMER_ACCOUNT = 'customer_account';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'level',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'level' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Role yang boleh dikelola oleh user tertentu.
     *
     * PRD §6.2 F-MASTER-01: Manager boleh CRUD user, tetapi TIDAK boleh membuat
     * atau mengubah akun ber-role Super Admin. Aturan itu diterapkan di sini agar
     * dropdown role dan validasi Form Request memakai sumber kebenaran yang sama.
     */
    public function scopeAssignableBy($query, ?User $actor)
    {
        if (! $actor?->isSuperAdmin()) {
            $query->where('slug', '!=', self::SUPER_ADMIN);
        }

        return $query->where('is_active', true)->orderBy('level');
    }
}
