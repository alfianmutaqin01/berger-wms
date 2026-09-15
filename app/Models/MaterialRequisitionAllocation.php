<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Satu batch sungguhan yang dijanjikan Logistik untuk sebuah MRF.
 *
 * Sepadan dengan StockTransferDetail pada transfer antar gudang, dan memang
 * disengaja mirip: keduanya menjawab pertanyaan yang sama — "barang yang mana
 * persisnya yang akan turun dari rak" — dan keduanya harus tetap terbaca
 * setelah baris stok asalnya habis lalu hilang.
 */
class MaterialRequisitionAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'material_requisition_id', 'material_requisition_item_id', 'product_id',
        'source_stock_id', 'batch_no', 'production_date', 'expiry_date',
        'status', 'ddp_reason',
        'qty_allocated', 'qty_picked', 'qty_received', 'discrepancy_reason',
    ];

    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'expiry_date' => 'date',
            'qty_allocated' => 'integer',
            'qty_picked' => 'integer',
            'qty_received' => 'integer',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(MaterialRequisition::class, 'material_requisition_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MaterialRequisitionItem::class, 'material_requisition_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceStock(): BelongsTo
    {
        return $this->belongsTo(InventoryStock::class, 'source_stock_id');
    }

    /** Baris daftar picking yang mengambil batch ini dari rak. */
    public function pickingItem(): HasOne
    {
        return $this->hasOne(PickingListItem::class, 'material_requisition_allocation_id');
    }

    public function holding(): HasOne
    {
        return $this->hasOne(ProductionMaterialHolding::class, 'material_requisition_allocation_id');
    }

    /**
     * Yang dijanjikan tetapi tidak ditemukan operator di rak.
     *
     * NULL selama belum dipicking — dan NULL sengaja dibedakan dari nol: nol
     * berarti "sudah dicari dan lengkap", NULL berarti "belum ada yang
     * mencari".
     */
    public function getQtyKurangAttribute(): ?int
    {
        return $this->qty_picked === null
            ? null
            : max(0, $this->qty_allocated - $this->qty_picked);
    }
}
