<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu kali pemakaian material oleh Produksi.
 *
 * DICATAT SATU DEMI SATU, bukan sebagai angka sisa yang ditimpa. Pemilik
 * produk bertanya persis ini: "masuk Produksi 300 tanggal berapa, dipakai
 * pertama tanggal berapa, dan berikutnya sampai habis". Kolom sisa saja tidak
 * bisa menjawabnya — ia hanya tahu keadaan hari ini dan melupakan jalan yang
 * ditempuh untuk sampai ke sana.
 */
class ProductionMaterialConsumption extends Model
{
    use HasFactory;

    protected $fillable = [
        'production_material_holding_id', 'qty', 'note', 'consumed_at', 'consumed_by',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'consumed_at' => 'datetime',
        ];
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(ProductionMaterialHolding::class, 'production_material_holding_id');
    }

    public function consumedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consumed_by');
    }
}
