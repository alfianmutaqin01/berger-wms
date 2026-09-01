<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu baris item pesanan — docs/2 §3.5.
 *
 * qty_approved dan lost_qty baru terisi di Fase 6 (approval). Di Fase 5
 * keduanya nol, dan itu BUKAN berarti "tidak ada yang disetujui" melainkan
 * "belum dinilai" — pembedanya adalah status header, bukan angka di sini.
 */
class SalesOrderDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_order_id', 'product_id',
        'qty_ordered', 'qty_approved', 'qty_shipped', 'lost_qty',
    ];

    protected function casts(): array
    {
        return [
            'qty_ordered' => 'integer',
            'qty_approved' => 'integer',
            'qty_shipped' => 'integer',
            'lost_qty' => 'integer',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SalesOrderAllocation::class);
    }

    /**
     * Selisih yang tidak terpenuhi — PRD §7.3.
     *
     * Dihitung dari kolom yang tersimpan, bukan dari stok saat ini: angka
     * Lost Sales harus tetap mencerminkan keadaan pada saat approval.
     */
    public function getLostQtyCalculatedAttribute(): int
    {
        return max(0, $this->qty_ordered - $this->qty_approved);
    }
}
