<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris barang pada Surat Jalan BC.
 *
 * `sku` dan `product_id` sengaja ada berdampingan: yang pertama adalah apa
 * yang tertulis di dokumen resmi dan harus bisa dibaca ulang apa adanya, yang
 * kedua adalah hasil pencocokan ke Master Produk kami. Keduanya bisa saja
 * tidak sejalan — dan justru saat itulah keduanya paling dibutuhkan.
 */
class DeliveryNoteLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_note_id', 'sku', 'product_id', 'description',
        'qty', 'qty_invoiced', 'uom_code',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'qty_invoiced' => 'integer',
        ];
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
