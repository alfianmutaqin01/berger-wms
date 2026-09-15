<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu SKU+batch yang ditolak customer.
 *
 * TIGA ANGKA, DAN KETIGANYA SENGAJA BISA BERBEDA: qty_rejected (kata Sales),
 * qty_approved (kata Logistik setelah mencocokkan SJ), qty_good + qty_ddp
 * (yang benar-benar sampai di rak). Menyatukannya menghapus jejak perbedaan
 * yang justru paling perlu dilihat.
 */
class SalesReturnDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_return_id', 'sales_order_detail_id', 'product_id', 'batch_no', 'production_date',
        'qty_rejected', 'qty_approved',
        'qty_good', 'qty_ddp', 'location_id', 'ddp_location_id', 'condition_note',
        'putaway_by', 'putaway_at',
        'is_verified', 'verified_by', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'putaway_at' => 'datetime',
            'verified_at' => 'datetime',
            'is_verified' => 'boolean',
        ];
    }

    /* ------------------------------------------------------------ Relasi */

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function salesOrderDetail(): BelongsTo
    {
        return $this->belongsTo(SalesOrderDetail::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function ddpLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'ddp_location_id');
    }

    public function putawayBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'putaway_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /* ---------------------------------------------------------- Atribut */

    /** Yang benar-benar sampai di rak, bagus maupun rusak. */
    public function getQtySampaiAttribute(): int
    {
        return (int) $this->qty_good + (int) $this->qty_ddp;
    }

    /**
     * Selisih antara yang DISETUJUI dan yang SAMPAI di rak.
     *
     * Negatif berarti ada yang hilang di perjalanan pulang — pertanyaan yang
     * harus dijawab sebelum Logistik memverifikasi, bukan sesudahnya.
     */
    public function getSelisihAttribute(): ?int
    {
        if ($this->putaway_at === null || $this->qty_approved === null) {
            return null;
        }

        return $this->qty_sampai - (int) $this->qty_approved;
    }

    public function sudahNaikRak(): bool
    {
        return $this->putaway_at !== null;
    }
}
