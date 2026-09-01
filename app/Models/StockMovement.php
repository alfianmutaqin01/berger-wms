<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Ledger mutasi stok — APPEND-ONLY.
 *
 * Larangan ubah/hapus ditegakkan di sini, bukan cuma ditulis di dokumen:
 * baris ledger adalah jejak audit keuangan untuk stok, dan sekali boleh
 * diubah, seluruh riwayatnya berhenti bisa dipercaya. Koreksi dilakukan
 * dengan MENAMBAH baris lawan.
 */
class StockMovement extends Model
{
    use HasFactory;

    /** Ledger tidak pernah diperbarui, jadi tidak butuh updated_at. */
    public const UPDATED_AT = null;

    public const TYPE_IN = 'IN';

    public const TYPE_OUT = 'OUT';

    public const TYPE_ALLOCATED = 'ALLOCATED';

    public const TYPE_DEALLOCATED = 'DEALLOCATED';

    public const TYPE_ADJUSTMENT = 'ADJUSTMENT';

    public const TYPE_TRANSFER_OUT = 'TRANSFER_OUT';

    public const TYPE_TRANSFER_IN = 'TRANSFER_IN';

    public const TYPE_RETURN_IN = 'RETURN_IN';

    public const TYPE_LABELS = [
        self::TYPE_IN => 'Masuk',
        self::TYPE_OUT => 'Keluar',
        self::TYPE_ALLOCATED => 'Dialokasikan',
        self::TYPE_DEALLOCATED => 'Batal Alokasi',
        self::TYPE_ADJUSTMENT => 'Koreksi',
        self::TYPE_TRANSFER_OUT => 'Transfer Keluar',
        self::TYPE_TRANSFER_IN => 'Transfer Masuk',
        self::TYPE_RETURN_IN => 'Retur Masuk',
    ];

    /** Tipe yang WAJIB menyertakan alasan (PRD §6.4 F-INV-02, docs/2 §3.4). */
    public const REQUIRES_NOTES = [
        self::TYPE_ADJUSTMENT,
        self::TYPE_TRANSFER_OUT,
        self::TYPE_TRANSFER_IN,
        self::TYPE_RETURN_IN,
    ];

    public const REF_INBOUND = 'inbound';

    public const REF_SALES_ORDER = 'sales_order';

    public const REF_ADJUSTMENT = 'adjustment';

    public const REF_STOCK_TRANSFER = 'stock_transfer';

    public const REF_SALES_RETURN = 'sales_return';

    /** Alasan DDP yang dikenal (docs/2 §3.4 inventory_stocks.ddp_reason). */
    public const REASON_EXPIRED = 'EXPIRED';

    protected $fillable = [
        'product_id',
        'location_id',
        'warehouse_id',
        'movement_type',
        'qty_change',
        'qty_before',
        'qty_after',
        'reference_type',
        'reference_id',
        'batch_no',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'qty_change' => 'integer',
            'qty_before' => 'integer',
            'qty_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Memasang pagar append-only.
     *
     * Dipanggil sekali oleh AppServiceProvider. Menolak lewat exception, bukan
     * `return false`, supaya percobaan mengubah ledger tidak bisa gagal
     * diam-diam dan luput dari perhatian.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException(
                'stock_movements bersifat append-only: baris ledger tidak boleh diubah. '.
                'Untuk mengoreksi, tambahkan baris mutasi lawan.'
            );
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'stock_movements bersifat append-only: baris ledger tidak boleh dihapus.'
            );
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->movement_type] ?? $this->movement_type;
    }

    public function scopeOfReference(Builder $query, string $type, int $id): Builder
    {
        return $query->where('reference_type', $type)->where('reference_id', $id);
    }
}
