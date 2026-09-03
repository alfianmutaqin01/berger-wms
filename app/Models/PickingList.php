<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu tugas pengambilan barang — PRD §6.5 F-OUT-03.
 *
 * Berisi BEBERAPA pesanan yang berangkat bersama dalam satu container
 * (keputusan pemilik produk). Logistik yang menyusunnya; operator yang
 * mengerjakannya.
 */
class PickingList extends Model
{
    use HasFactory;

    /** Sudah disusun Logistik, belum ada operator yang memegangnya. */
    public const STATUS_OPEN = 'open';

    /** Seorang operator sedang berjalan mengambil barangnya. */
    public const STATUS_PICKING = 'picking';

    /** Seluruh baris ditandai; barang sudah di loading dock. */
    public const STATUS_COMPLETED = 'completed';

    /** Dibubarkan Logistik; pesanan di dalamnya kembali bebas. */
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Menunggu Operator',
        self::STATUS_PICKING => 'Sedang Dikerjakan',
        self::STATUS_COMPLETED => 'Selesai',
        self::STATUS_CANCELLED => 'Dibubarkan',
    ];

    protected $fillable = [
        'list_number', 'warehouse_id', 'status', 'notes',
        'created_by', 'claimed_by', 'claimed_at',
        'completed_at', 'completed_by',
        'cancelled_at', 'cancelled_by', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------ Relasi */

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PickingListItem::class);
    }

    /** Pesanan yang ikut dalam daftar ini. */
    public function orders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /* ------------------------------------------------------------- Scope */

    /** Daftar yang masih perlu dikerjakan seseorang. */
    public function scopeAktif(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_PICKING]);
    }

    /* ------------------------------------------------------------ Aturan */

    /**
     * Boleh dibubarkan selama BELUM ADA satu baris pun yang diambil.
     *
     * Begitu operator mengambil barang pertama dari rak, membubarkan daftar
     * hanya menghapus catatannya — barangnya tetap sudah turun dan tergeletak
     * di dock, dan tidak ada lagi yang menjelaskan kenapa ia di sana.
     */
    public function bolehDibubarkan(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_PICKING], true)
            && ! $this->items()->where('status', '<>', PickingListItem::STATUS_PENDING)->exists();
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => 'warning',
            self::STATUS_PICKING => 'primary',
            self::STATUS_COMPLETED => 'success',
            default => 'secondary',
        };
    }
}
