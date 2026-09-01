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

    protected $fillable = [
        'code',
        'name',
        'address',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
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
