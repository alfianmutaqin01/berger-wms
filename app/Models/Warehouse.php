<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Gudang / dispatch code.
 *
 * `users.warehouse_id` yang bernilai NULL berarti user tersebut tidak dibatasi
 * ke satu gudang (akses lintas gudang), bukan berarti datanya belum diisi.
 */
class Warehouse extends Model
{
    use HasFactory, SoftDeletes;

    /** Melayani SEMUA wilayah; daftar territory diabaikan. */
    public const MODE_ALL = 'all';

    /** Melayani HANYA wilayah yang terdaftar. */
    public const MODE_ONLY = 'only';

    /** Melayani semua wilayah KECUALI yang terdaftar. */
    public const MODE_EXCEPT = 'except';

    protected $fillable = [
        'code',
        'name',
        'address',
        'territory_mode',
        'has_production',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'has_production' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Gudang yang punya lini produksi — hanya Karawang untuk saat ini. */
    public function scopeWithProduction($query)
    {
        return $query->where('has_production', true);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function territories(): HasMany
    {
        return $this->hasMany(WarehouseTerritory::class);
    }

    /** Kode wilayah yang terdaftar untuk gudang ini, semuanya huruf besar. */
    public function territoryCodes(): array
    {
        $daftar = $this->relationLoaded('territories')
            ? $this->territories->pluck('territory_code')
            : $this->territories()->pluck('territory_code');

        return $daftar->map(fn ($kode) => mb_strtoupper(trim((string) $kode)))->all();
    }

    /**
     * Apakah gudang ini boleh melayani pelanggan di wilayah $territoryCode?
     *
     * Wilayah KOSONG dianggap terlayani, bukan tertolak. Pelanggan tanpa
     * territory_code adalah master data yang belum lengkap; menolaknya di sini
     * membuat kesalahan input muncul di layar Sales sebagai pelanggan yang
     * "hilang" tanpa sebab yang bisa ditebak.
     */
    public function servesTerritory(?string $territoryCode): bool
    {
        if ($this->territory_mode === self::MODE_ALL || blank($territoryCode)) {
            return true;
        }

        $cocok = in_array(mb_strtoupper(trim($territoryCode)), $this->territoryCodes(), true);

        return $this->territory_mode === self::MODE_ONLY ? $cocok : ! $cocok;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Label siap tampil untuk dropdown dan badge, contoh: "WH-01 (Karawang)". */
    public function getDisplayLabelAttribute(): string
    {
        return "{$this->code} ({$this->name})";
    }
}
