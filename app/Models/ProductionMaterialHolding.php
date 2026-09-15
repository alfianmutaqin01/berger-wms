<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu batch material yang SEDANG DI TANGAN PRODUKSI.
 *
 * Inilah jawaban atas keluhan pokok pemilik produk: "300 pcs diminta untuk
 * direproses, baru 150 yang dikerjakan, sisanya terlupakan". Barisnya lahir
 * saat Produksi menekan Diterima, berkurang tiap kali sebagian dipakai, dan
 * baru selesai saat qty_consumed menyamai qty_received.
 *
 * BUKAN BARIS inventory_stocks, dan itu keputusan yang disengaja. Begitu
 * Produksi menerima barangnya, barang itu keluar dari inventory Logistik —
 * tidak bisa dijual, tidak bisa dipicking, tidak ikut terhitung sebagai stok
 * gudang. Menyimpannya sebagai baris stok biasa yang "disaring dari layar"
 * berarti satu penyaringan yang terlupa cukup untuk menjualnya ke pelanggan.
 */
class ProductionMaterialHolding extends Model
{
    use HasFactory;

    protected $fillable = [
        'material_requisition_id', 'material_requisition_allocation_id',
        'product_id', 'warehouse_id',
        'batch_no', 'production_date', 'expiry_date', 'production_area',
        'qty_received', 'qty_consumed',
        'received_at', 'received_by', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'expiry_date' => 'date',
            'qty_received' => 'integer',
            'qty_consumed' => 'integer',
            'received_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------ Relasi */

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(MaterialRequisition::class, 'material_requisition_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(MaterialRequisitionAllocation::class, 'material_requisition_allocation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(ProductionMaterialConsumption::class, 'production_material_holding_id');
    }

    /* ------------------------------------------------------------- Scope */

    /** Yang masih ada sisanya di tangan Produksi. */
    public function scopeMasihAda(Builder $query): Builder
    {
        return $query->whereNull('finished_at');
    }

    /**
     * Yang sudah menunggak lebih dari $hari — inti masalahnya.
     *
     * Bukan sekadar penyaring untuk laporan. Baris yang menua di sini adalah
     * barang sungguhan yang berdiri di lantai produksi tanpa ada yang ingat
     * ia pernah diminta, dan satu-satunya cara ia kembali diingat adalah
     * layar yang menyebutkannya tanpa diminta.
     */
    public function scopeMenunggak(Builder $query, int $hari = 30): Builder
    {
        return $query->whereNull('finished_at')
            ->where('received_at', '<=', now()->subDays($hari));
    }

    /* ---------------------------------------------------------- Accessor */

    public function getQtySisaAttribute(): int
    {
        return max(0, $this->qty_received - $this->qty_consumed);
    }

    public function sudahHabis(): bool
    {
        return $this->finished_at !== null;
    }

    /** Berapa hari barang ini sudah berdiri di tangan Produksi. */
    public function getUmurHariAttribute(): int
    {
        return (int) $this->received_at->startOfDay()->diffInDays(now()->startOfDay());
    }
}
