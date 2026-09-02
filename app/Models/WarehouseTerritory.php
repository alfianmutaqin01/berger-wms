<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris cakupan wilayah gudang.
 *
 * Artinya bergantung pada `warehouses.territory_mode`: pada mode `only` baris
 * ini berarti "dilayani", pada mode `except` berarti "TIDAK dilayani". Sengaja
 * satu tabel untuk keduanya — dua tabel yang isinya sama bentuknya hanya
 * menambah tempat untuk lupa menyalin.
 */
class WarehouseTerritory extends Model
{
    use HasFactory;

    protected $fillable = ['warehouse_id', 'territory_code'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
