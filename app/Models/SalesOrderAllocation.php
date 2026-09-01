<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ikatan antara satu baris pesanan dan satu batch stok — docs/2 §3.5.
 *
 * Baris di sini baru ditulis Fase 6. Modelnya sudah ada sejak Fase 5 supaya
 * relasi dari SalesOrderDetail bisa dipasang utuh sejak awal.
 */
class SalesOrderAllocation extends Model
{
    use HasFactory;

    /**
     * Alokasi adalah CATATAN KEJADIAN, bukan angka yang ditimpa. Bila
     * jumlahnya berubah, yang benar adalah membatalkan lalu mengalokasikan
     * ulang — karena itu tabelnya tidak punya updated_at.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'sales_order_detail_id', 'inventory_stock_id', 'qty_allocated',
    ];

    protected function casts(): array
    {
        return ['qty_allocated' => 'integer'];
    }

    public function detail(): BelongsTo
    {
        return $this->belongsTo(SalesOrderDetail::class, 'sales_order_detail_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(InventoryStock::class, 'inventory_stock_id');
    }
}
