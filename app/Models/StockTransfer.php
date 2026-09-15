<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu pengiriman stok antar gudang — PRD F-INV-05.
 *
 * EMPAT KEADAAN, dan dua di tengah yang paling mudah terlupakan:
 * selama `pending` barangnya masih di rak gudang asal tetapi sudah
 * dicadangkan — tidak boleh dijual, belum berangkat. Selama `in_transit`
 * barangnya BUKAN milik gudang asal maupun gudang tujuan: sudah keluar dari
 * stok Karawang tetapi belum masuk stok Pekanbaru — dan justru itu yang
 * mencerminkan keadaan sebenarnya di jalan.
 */
class StockTransfer extends Model
{
    use HasFactory;

    /**
     * Sudah disusun, barangnya masih di rak gudang asal — DICADANGKAN.
     *
     * Keadaan ini lahir bersama alur picking: di antara "Admin menyusun
     * transfer" dan "barangnya naik kendaraan" ada pekerjaan sungguhan yang
     * dilakukan seseorang di gudang. Selama itu barangnya belum berangkat,
     * tetapi juga tidak boleh dijual — persis seperti pesanan pelanggan yang
     * sudah disetujui dan menunggu dipicking.
     */
    public const STATUS_PENDING = 'pending';

    /** Sudah berangkat, belum sampai. Tidak bisa dijual siapa pun. */
    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Menunggu Picking',
        self::STATUS_IN_TRANSIT => 'Dalam Perjalanan',
        self::STATUS_RECEIVED => 'Diterima',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    public const STATUS_BADGES = [
        self::STATUS_PENDING => 'bg-info text-dark',
        self::STATUS_IN_TRANSIT => 'bg-warning text-dark',
        self::STATUS_RECEIVED => 'bg-success',
        self::STATUS_CANCELLED => 'bg-secondary',
    ];

    protected $fillable = [
        'transfer_number',
        'from_warehouse_id',
        'to_warehouse_id',
        'status',
        'picking_list_id',
        'notes',
        'requested_at', 'requested_by',
        'shipped_at', 'shipped_by',
        'received_at', 'received_by',
        'cancelled_at', 'cancelled_by', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'shipped_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------ Relasi */

    public function details(): HasMany
    {
        return $this->hasMany(StockTransferDetail::class);
    }

    /** Daftar picking yang mengerjakan transfer ini. */
    public function pickingList(): BelongsTo
    {
        return $this->belongsTo(PickingList::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function shippedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /* ------------------------------------------------------------- Scope */

    /**
     * Transfer yang menyangkut gudang $warehouseId, sebagai asal MAUPUN tujuan.
     *
     * Berbeda dari WarehouseScope::apply() yang menyaring satu kolom: dokumen
     * ini memang milik DUA gudang sekaligus, dan keduanya berhak melihatnya.
     * $warehouseId NULL berarti lintas gudang (Super Admin) — tidak disaring.
     */
    public function scopeTouchingWarehouse(Builder $query, ?int $warehouseId): Builder
    {
        if ($warehouseId === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('from_warehouse_id', $warehouseId)
            ->orWhere('to_warehouse_id', $warehouseId));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where('transfer_number', 'ILIKE', '%'.str_replace('%', '\%', $term).'%');
    }

    /* ---------------------------------------------------------- Accessor */

    public function isInTransit(): bool
    {
        return $this->status === self::STATUS_IN_TRANSIT;
    }

    /** Masih di rak gudang asal, dicadangkan, menunggu operator. */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getStatusBadgeAttribute(): string
    {
        return self::STATUS_BADGES[$this->status] ?? 'bg-secondary';
    }

    /**
     * Total unit yang BENAR-BENAR berangkat, untuk ringkasan di daftar.
     *
     * Nol selama transfernya masih menunggu picking — memang belum ada satu
     * unit pun yang turun dari rak. Yang diminta Admin dibaca dari
     * total_requested, dan sengaja tidak dicampur: selisih di antara keduanya
     * adalah barang yang ternyata tidak ada di rak.
     */
    public function getTotalShippedAttribute(): int
    {
        return (int) ($this->relationLoaded('details')
            ? $this->details->sum('qty_shipped')
            : $this->details()->sum('qty_shipped'));
    }

    /** Total unit yang DIMINTA saat transfernya disusun. */
    public function getTotalRequestedAttribute(): int
    {
        return (int) ($this->relationLoaded('details')
            ? $this->details->sum('qty_requested')
            : $this->details()->sum('qty_requested'));
    }

    /**
     * Unit yang hilang di perjalanan; NULL selama belum diterima.
     *
     * NULL dan 0 sengaja dibedakan: 0 berarti "sudah dihitung, tidak ada yang
     * hilang", NULL berarti "belum ada yang menghitung".
     */
    public function getTotalMissingAttribute(): ?int
    {
        if (! $this->relationLoaded('details')) {
            $this->load('details');
        }

        if ($this->details->contains(fn ($d) => $d->qty_received === null)) {
            return null;
        }

        return (int) $this->details->sum(fn ($d) => $d->qty_shipped - (int) $d->qty_received);
    }
}
