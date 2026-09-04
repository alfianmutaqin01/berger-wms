<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu baris item pesanan — docs/2 §3.5.
 *
 * qty_approved dan outstanding_qty baru terisi di Fase 6 (approval). Di Fase 5
 * keduanya nol, dan itu BUKAN berarti "tidak ada yang disetujui" melainkan
 * "belum dinilai" — pembedanya adalah status header, bukan angka di sini.
 */
class SalesOrderDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_order_id', 'product_id',
        'qty_ordered', 'qty_approved', 'qty_shipped', 'outstanding_qty',
        'substitution_note',
    ];

    protected function casts(): array
    {
        return [
            'qty_ordered' => 'integer',
            'qty_approved' => 'integer',
            'qty_shipped' => 'integer',
            'outstanding_qty' => 'integer',
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
     * Outstanding harus tetap mencerminkan keadaan pada saat approval.
     */
    public function getOutstandingQtyCalculatedAttribute(): int
    {
        return max(0, $this->qty_ordered - $this->qty_approved);
    }

    /**
     * Qty yang BENAR-BENAR sudah dicadangkan dari stok.
     *
     * Dihitung dari `sales_order_allocations`, tidak disimpan sebagai kolom:
     * alokasi bisa bertambah belakangan ketika stok yang kurang akhirnya
     * masuk, dan kolom turunan yang lupa diperbarui adalah angka yang
     * berbohong tanpa ada yang tahu.
     */
    public function getQtyAllocatedAttribute(): int
    {
        return (int) ($this->relationLoaded('allocations')
            ? $this->allocations->sum('qty_allocated')
            : $this->allocations()->sum('qty_allocated'));
    }

    /**
     * Qty yang sudah dijanjikan ke customer tetapi belum ada stoknya.
     *
     * Muncul ketika Logistik menyetujui lebih banyak daripada yang tercatat
     * sistem — kasus nyata di Berger: barang sudah sampai gudang tetapi
     * belum di-putaway. Porsi ini BELUM bisa dipicking karena tidak punya
     * batch maupun lokasi rak.
     */
    public function getQtyPendingStockAttribute(): int
    {
        return max(0, $this->qty_approved - $this->qty_allocated);
    }
}
