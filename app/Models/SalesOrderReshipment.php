<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Satu putaran pengiriman ulang atas kekurangan sebuah pesanan.
 *
 * APPEND-ONLY. Ini catatan pemenuhan kewajiban ke pelanggan — yang bisa
 * disunting belakangan tidak bisa dipakai menjawab "kenapa sisa 20 ini belum
 * juga sampai". Pola yang sama dengan StockMovement, SalesOrderOutstanding,
 * dan SalesOrderRejection.
 */
class SalesOrderReshipment extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'sales_order_id', 'warehouse_id', 'round_no',
        'qty_outstanding', 'qty_allocated', 'note', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'round_no' => 'integer',
            'qty_outstanding' => 'integer',
            'qty_allocated' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Riwayat pengiriman ulang tidak boleh diubah.');
        });

        static::deleting(function () {
            throw new RuntimeException('Riwayat pengiriman ulang tidak boleh dihapus.');
        });
    }

    /* ------------------------------------------------------------ Relasi */

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ----------------------------------------------------------- Atribut */

    /**
     * Yang TIDAK kebagian stok saat putaran ini dibuka.
     *
     * Bukan kegagalan: sisanya tetap terutang dan bisa dikirim ulang lagi
     * nanti. Tetapi harus terbaca, karena orang yang menekan "Kirim Ulang"
     * untuk 50 unit perlu tahu bahwa yang benar-benar terpegang hanya 30.
     */
    public function getQtyBelumKebagianAttribute(): int
    {
        return max(0, $this->qty_outstanding - $this->qty_allocated);
    }
}
