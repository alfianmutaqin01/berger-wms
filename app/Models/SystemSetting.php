<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu setelan operasional yang sudah diubah dari bawaannya.
 *
 * Tabel ini TIDAK memuat seluruh setelan — hanya yang pernah disentuh orang.
 * Daftar setelan yang dikenal beserta tipe, batas, dan nilai bawaannya ada di
 * App\Support\Settings, dan ke sanalah seluruh sistem membacanya. Model ini
 * sengaja tidak dipakai langsung dari mana pun kecuali kelas itu.
 */
class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value', 'updated_by'];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
