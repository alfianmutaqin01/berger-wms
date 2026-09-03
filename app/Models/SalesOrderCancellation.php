<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat pembatalan pesanan yang sudah diterima.
 *
 * TIDAK PERNAH DIBERSIHKAN, berbeda dengan kolom pembatalan di `sales_orders`
 * yang hanya menyimpan keadaan sekarang. Pesanan yang dibatalkan kembali ke
 * antrean dan bisa diterima lagi; begitu itu terjadi kolom di pesanannya
 * ditimpa, dan tanpa tabel ini fakta bahwa suatu nomor SO pernah dipakai lalu
 * dilepas akan hilang — padahal justru itu yang perlu ditelusuri ketika angka
 * di BC dan di WMS berbeda.
 */
class SalesOrderCancellation extends Model
{
    use HasFactory;

    /** Customer yang membatalkan pesanannya. */
    public const SOURCE_CUSTOMER = 'customer';

    /** Sistem BC tidak menyetujui pesanan yang sudah diterima. */
    public const SOURCE_BC = 'bc';

    /** Keputusan internal gudang — mis. induk invoice-nya batal. */
    public const SOURCE_INTERNAL = 'internal';

    public const SOURCE_LABELS = [
        self::SOURCE_CUSTOMER => 'Dibatalkan customer',
        self::SOURCE_BC => 'Tidak disetujui BC',
        self::SOURCE_INTERNAL => 'Keputusan internal',
    ];

    protected $fillable = [
        'sales_order_id',
        'bc_so_number',
        'source',
        'reason',
        'qty_released',
        'approved_at',
        'approved_by',
        'cancelled_at',
        'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'qty_released' => 'integer',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function getSourceLabelAttribute(): string
    {
        return self::SOURCE_LABELS[$this->source] ?? $this->source;
    }
}
