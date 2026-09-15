<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris riwayat perubahan nomor SO — Fase 6 tahap 5.
 *
 * Lihat migrasi 2026_09_18_000002 untuk alasan tabel ini ada.
 */
class SoNumberChange extends Model
{
    use HasFactory;

    /** Disalin sistem dari Surat Jalan BC; tidak diketik ulang manusia. */
    public const SOURCE_PAIRING = 'pairing';

    /** Diketik Logistik sendiri, hanya selama pesanan belum berangkat. */
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_LABELS = [
        self::SOURCE_PAIRING => 'Disalin dari Surat Jalan BC',
        self::SOURCE_MANUAL => 'Dikoreksi manual',
    ];

    protected $fillable = [
        'sales_order_id', 'old_number', 'new_number',
        'source', 'delivery_note_id', 'reason', 'changed_by',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function getSourceLabelAttribute(): string
    {
        return self::SOURCE_LABELS[$this->source] ?? $this->source;
    }
}
