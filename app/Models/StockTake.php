<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu sesi stocktake.
 *
 * Siklusnya pendek dan sengaja hanya punya satu pintu keluar yang mengubah
 * stok: counting -> finalized. Selama masih counting, tidak satu pun angka
 * stok tersentuh — hasil hitungan menumpuk sebagai catatan, dan barang tetap
 * boleh keluar-masuk seperti biasa.
 */
class StockTake extends Model
{
    use HasFactory;

    /** Sedang dihitung. Stok BELUM tersentuh sama sekali. */
    public const STATUS_COUNTING = 'counting';

    /** Laporannya sudah disahkan; koreksinya sudah masuk ke stok. */
    public const STATUS_FINALIZED = 'finalized';

    /** Dibatalkan tanpa mengubah apa pun. */
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::STATUS_COUNTING => 'Sedang Dihitung',
        self::STATUS_FINALIZED => 'Selesai & Disahkan',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    /** Seluruh rak di gudang itu. */
    public const SCOPE_WAREHOUSE = 'warehouse';

    /** Satu zona saja, mis. Fast Moving Area. */
    public const SCOPE_ZONE = 'zone';

    /** Satu deret saja, mis. B-01. */
    public const SCOPE_RACK = 'rack';

    public const SCOPE_LABELS = [
        self::SCOPE_WAREHOUSE => 'Seluruh gudang',
        self::SCOPE_ZONE => 'Satu zona',
        self::SCOPE_RACK => 'Satu deret',
    ];

    protected $fillable = [
        'reference', 'warehouse_id', 'scope_type', 'scope_value',
        'status', 'note',
        'opened_at', 'opened_by', 'finalized_at', 'finalized_by',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------ Relasi */

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTakeItem::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    /* ------------------------------------------------------------ Scope */

    public function scopeBerjalan(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COUNTING);
    }

    /* ------------------------------------------------------------ Aturan */

    public function sedangDihitung(): bool
    {
        return $this->status === self::STATUS_COUNTING;
    }

    public function sudahDisahkan(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Cakupan siap tampil, mis. "Satu zona — Fast Moving Area". */
    public function getScopeLabelAttribute(): string
    {
        $dasar = self::SCOPE_LABELS[$this->scope_type] ?? $this->scope_type;

        return $this->scope_value === null ? $dasar : $dasar.' — '.$this->scope_value;
    }
}
