<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu batch yang ikut dalam satu pengiriman antar gudang.
 *
 * batch_no, production_date, expiry_date, dan status DISALIN ke sini, bukan
 * dibaca dari baris stok asal saat dibutuhkan. Baris stok asal wajar habis
 * lalu hilang; dokumen transfernya harus tetap bisa menjawab "barang apa
 * yang berangkat" bertahun-tahun kemudian.
 */
class StockTransferDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_transfer_id',
        'product_id',
        'source_stock_id',
        'batch_no',
        'production_date',
        'expiry_date',
        'status',
        'ddp_reason',
        'qty_shipped',
        'qty_received',
        'to_location_id',
        'discrepancy_reason',
    ];

    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'expiry_date' => 'date',
            'qty_shipped' => 'integer',
            'qty_received' => 'integer',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceStock(): BelongsTo
    {
        return $this->belongsTo(InventoryStock::class, 'source_stock_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    /** Unit yang hilang di perjalanan; NULL selama belum dihitung. */
    public function getMissingQtyAttribute(): ?int
    {
        return $this->qty_received === null ? null : $this->qty_shipped - $this->qty_received;
    }
}
