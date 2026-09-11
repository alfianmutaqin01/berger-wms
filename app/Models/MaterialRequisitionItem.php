<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu SKU yang diminta Produksi — APA, bukan DARI BATCH MANA.
 *
 * Produksi tidak tahu isi rak dan tidak perlu tahu. Yang ia tahu: butuh 200
 * pcs SKU X untuk direproses. Penerjemahannya menjadi batch sungguhan
 * pekerjaan Logistik, dan hasilnya tinggal di material_requisition_allocations.
 */
class MaterialRequisitionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'material_requisition_id', 'product_id', 'qty_requested', 'note',
    ];

    protected function casts(): array
    {
        return ['qty_requested' => 'integer'];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(MaterialRequisition::class, 'material_requisition_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(MaterialRequisitionAllocation::class, 'material_requisition_item_id');
    }

    /** Berapa yang sudah dijanjikan Logistik dari baris ini. */
    public function getQtyDialokasikanAttribute(): int
    {
        return (int) ($this->relationLoaded('allocations')
            ? $this->allocations->sum('qty_allocated')
            : $this->allocations()->sum('qty_allocated'));
    }

    /**
     * Yang diminta tetapi tidak ada barangnya di rak.
     *
     * Bukan kekurangan yang harus disembunyikan: Produksi berhak tahu bahwa
     * dari 200 yang ia minta hanya 120 yang bisa dijanjikan, SEBELUM ia
     * menyusun rencana produksi hari itu.
     */
    public function getQtyTidakTerpenuhiAttribute(): int
    {
        return max(0, $this->qty_requested - $this->qty_dialokasikan);
    }
}
