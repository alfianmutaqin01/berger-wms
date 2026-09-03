<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Surat Jalan — CERMINAN dokumen milik sistem BC, bukan dokumen kami.
 *
 * Nomornya (`document_no`) disalin dari BC dan tidak pernah dibangkitkan di
 * sini. Qty pada barisnya adalah qty yang BERLAKU: bila berbeda dengan hasil
 * picking, yang menang adalah dokumen ini (keputusan pemilik produk).
 */
class DeliveryNote extends Model
{
    use HasFactory;

    /** Sudah disalin dari BC; barangnya belum dinyatakan berangkat. */
    public const STATUS_IMPORTED = 'imported';

    /** Barang dinyatakan berangkat lewat sistem ini. */
    public const STATUS_SHIPPED = 'shipped';

    /** Supir sudah mengonfirmasi barang sampai. */
    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_LABELS = [
        self::STATUS_IMPORTED => 'Menunggu Berangkat',
        self::STATUS_SHIPPED => 'Dalam Pengiriman',
        self::STATUS_DELIVERED => 'Sampai Tujuan',
    ];

    protected $fillable = [
        'document_no', 'bc_so_number', 'sales_order_id',
        'customer_code', 'customer_id', 'warehouse_id',
        'bc_location_code', 'shipment_date', 'status',
        'imported_at', 'imported_by',
    ];

    protected function casts(): array
    {
        return [
            'shipment_date' => 'date',
            'imported_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------ Relasi */

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryNoteLine::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /* ------------------------------------------------------------- Scope */

    /**
     * Surat Jalan yang belum menemukan pesanannya di sistem ini.
     *
     * Bukan kesalahan: ekspor harian BC memuat seluruh SJ perusahaan,
     * termasuk pesanan yang tidak pernah lewat portal ini. Tetapi ia harus
     * TERLIHAT — SJ yang tidak berpasangan padahal seharusnya berpasangan
     * berarti nomor SO-nya berbeda antara BC dan yang diketik Logistik, dan
     * itu justru yang perlu segera dibetulkan.
     */
    public function scopeBelumBerpasangan(Builder $query): Builder
    {
        return $query->whereNull('sales_order_id');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $pola = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($pola): void {
            $q->where('document_no', 'ILIKE', $pola)
                ->orWhere('bc_so_number', 'ILIKE', $pola)
                ->orWhere('customer_code', 'ILIKE', $pola);
        });
    }

    /* ------------------------------------------------------------ Aturan */

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_IMPORTED => 'warning',
            self::STATUS_SHIPPED => 'primary',
            self::STATUS_DELIVERED => 'success',
            default => 'secondary',
        };
    }

    /** Total unit yang tertulis di dokumen ini. */
    public function getTotalQtyAttribute(): int
    {
        return (int) ($this->relationLoaded('lines')
            ? $this->lines->sum('qty')
            : $this->lines()->sum('qty'));
    }
}
