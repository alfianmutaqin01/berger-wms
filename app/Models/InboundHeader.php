<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dokumen produksi masuk. Satu dokumen memuat banyak palet.
 */
class InboundHeader extends Model
{
    use HasFactory, SoftDeletes;

    /* Alur status mengikuti PRD §6.3. */
    public const STATUS_PUTAWAY_PENDING = 'putaway_pending';

    public const STATUS_VERIFICATION_PENDING = 'verification_pending';

    public const STATUS_PARTIAL_VERIFIED = 'partial_verified';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_LABELS = [
        self::STATUS_PUTAWAY_PENDING => 'Menunggu Put-away',
        self::STATUS_VERIFICATION_PENDING => 'Menunggu Verifikasi',
        self::STATUS_PARTIAL_VERIFIED => 'Sebagian Terverifikasi',
        self::STATUS_VERIFIED => 'Selesai',
    ];

    protected $fillable = [
        'document_number',
        'warehouse_id',
        'production_date',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'production_date' => 'date',
        ];
    }

    public function details(): HasMany
    {
        return $this->hasMany(InboundDetail::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('document_number', 'ILIKE', $like)
                ->orWhereHas('details', fn (Builder $d) => $d->where('batch_no', 'ILIKE', $like)
                    ->orWhere('production_order_no', 'ILIKE', $like));
        });
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Jumlah palet fisik pada dokumen ini. */
    public function getTotalPalletsAttribute(): int
    {
        return $this->details_count ?? $this->details()->count();
    }
}
