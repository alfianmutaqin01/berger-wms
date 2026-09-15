<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jatah yang ditahan untuk satu customer sebelum pesanannya resmi masuk.
 *
 * TIGA ANGKA YANG HARUS DIBEDAKAN, dan inilah yang paling mudah tertukar:
 *
 *   qty_booked   = yang dijanjikan ke customer.
 *   tercadang    = yang BENAR-BENAR sudah dipegang dari stok nyata
 *                  (jumlah stock_booking_allocations).
 *   menunggu     = sisanya, yang stoknya belum ada sama sekali.
 *
 * Booking 5 unit saat gudang kosong adalah booking yang sah: ia berstatus
 * menunggu, dan begitu produksi masuk, jatahnya diambilkan otomatis. Kalau
 * ketiganya dilebur jadi satu angka, "sudah aman 5" dan "baru dijanjikan 5"
 * jadi tidak bisa dibedakan — padahal yang satu boleh ditagih besok dan yang
 * satu lagi belum tentu ada barangnya.
 */
class StockBooking extends Model
{
    use HasFactory;

    /** Masih berlaku: memegang jatah, atau menunggu stok. */
    public const STATUS_OPEN = 'open';

    /** Seluruh jatahnya sudah berpindah ke pesanan sungguhan. */
    public const STATUS_CLOSED = 'closed';

    /** Dibatalkan; jatah yang sempat dipegang sudah dikembalikan ke stok. */
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Berlaku',
        self::STATUS_CLOSED => 'Sudah Dipakai',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    protected $fillable = [
        'reference', 'warehouse_id', 'customer_id', 'product_id',
        'qty_booked', 'qty_used', 'needed_by', 'note', 'status',
        'created_by', 'closed_at', 'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'qty_booked' => 'integer',
            'qty_used' => 'integer',
            'needed_by' => 'date',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------ Relasi */

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(StockBookingAllocation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /* ------------------------------------------------------------ Scope */

    public function scopeBerlaku(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /* ------------------------------------------------------------ Aturan */

    public function masihBerlaku(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** Jatah yang BENAR-BENAR sudah dipegang dari stok nyata. */
    public function getQtyReservedAttribute(): int
    {
        return (int) ($this->relationLoaded('allocations')
            ? $this->allocations->sum('qty')
            : $this->allocations()->sum('qty'));
    }

    /**
     * Jatah yang stoknya BELUM ADA sama sekali.
     *
     * Inilah yang ikut antre saat barang baru masuk. Dihitung, tidak
     * disimpan: menyimpannya berarti satu angka lagi yang harus diperbarui
     * setiap kali alokasi bergerak, dan angka turunan yang lupa diperbarui
     * adalah angka yang berbohong tanpa ada yang tahu.
     */
    public function getQtyWaitingAttribute(): int
    {
        return max(0, $this->qty_booked - $this->qty_used - $this->qty_reserved);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Sudah lewat tanggal dibutuhkan tetapi jatahnya belum diambil. */
    public function terlambat(): bool
    {
        return $this->masihBerlaku()
            && $this->needed_by !== null
            && $this->needed_by->isPast();
    }
}
