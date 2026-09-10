<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris hitungan stocktake: satu batch, di satu rak.
 *
 * Sepadan satu-satu dengan baris `inventory_stocks` yang ada saat sesi
 * dibuka. Keterangannya (rak, produk, batch) DISALIN ke sini, bukan dibaca
 * lewat relasi saat laporan dibuka — laporan stocktake harus menunjukkan angka
 * yang sama setahun kemudian, sekalipun baris stok aslinya sudah habis dan
 * dibersihkan.
 */
class StockTakeItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_take_id', 'inventory_stock_id', 'location_id', 'product_id', 'batch_no',
        'qty_system', 'qty_physical', 'count_note', 'counted_at', 'counted_by',
        'applied_delta', 'qty_after', 'is_found', 'found_production_date',
    ];

    protected function casts(): array
    {
        return [
            'qty_system' => 'integer',
            'qty_physical' => 'integer',
            'applied_delta' => 'integer',
            'qty_after' => 'integer',
            'counted_at' => 'datetime',
            'is_found' => 'boolean',
            'found_production_date' => 'date',
        ];
    }

    /* ------------------------------------------------------------ Relasi */

    public function stockTake(): BelongsTo
    {
        return $this->belongsTo(StockTake::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(InventoryStock::class, 'inventory_stock_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    /* ------------------------------------------------------------ Scope */

    public function scopeSudahDihitung(Builder $query): Builder
    {
        return $query->whereNotNull('qty_physical');
    }

    public function scopeBelumDihitung(Builder $query): Builder
    {
        return $query->whereNull('qty_physical');
    }

    /* ---------------------------------------------------------- Accessor */

    public function sudahDihitung(): bool
    {
        return $this->qty_physical !== null;
    }

    /**
     * Selisih hasil hitungan terhadap angka sistem yang dibekukan.
     *
     * NULL berarti BELUM DIHITUNG — bukan nol. Nol berarti sudah dicek dan
     * memang cocok, dan itu kabar baik yang berbeda artinya dari belum
     * diperiksa sama sekali.
     */
    public function getSelisihAttribute(): ?int
    {
        return $this->qty_physical === null
            ? null
            : $this->qty_physical - $this->qty_system;
    }
}
