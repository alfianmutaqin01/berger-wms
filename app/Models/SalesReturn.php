<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu laporan penolakan customer — barang yang ditolak lalu kembali ke rak.
 *
 * BUKAN sales_order_rejections. Tabel itu mencatat LOGISTIK menolak pesanan
 * sebelum barang bergerak; ini mencatat CUSTOMER menolak barang yang sudah
 * sampai di depan tokonya. Lihat alasan lengkapnya di migrasinya.
 *
 * STATUSNYA MENYALIN inbound_headers dengan sengaja: barang tolakan masuk
 * lewat pintu yang sama dengan barang produksi — dinaikkan Operator,
 * diverifikasi Logistik, baru resmi jadi stok.
 */
class SalesReturn extends Model
{
    use HasFactory;

    /** Sales sudah melapor, Logistik belum menilai klaimnya. */
    public const STATUS_REPORTED = 'reported';

    /** Logistik tidak menyetujui laporannya — barang tidak masuk rak. */
    public const STATUS_REJECTED = 'rejected';

    /** Klaim disetujui; barang menunggu dinaikkan Operator. */
    public const STATUS_PUTAWAY_PENDING = 'putaway_pending';

    /** Sudah di rak; menunggu Logistik memeriksa barangnya. */
    public const STATUS_VERIFICATION_PENDING = 'verification_pending';

    public const STATUS_PARTIAL_VERIFIED = 'partial_verified';

    /** Selesai — stoknya sudah resmi. */
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_LABELS = [
        self::STATUS_REPORTED => 'Menunggu Persetujuan',
        self::STATUS_REJECTED => 'Laporan Ditolak',
        self::STATUS_PUTAWAY_PENDING => 'Menunggu Naik Rak',
        self::STATUS_VERIFICATION_PENDING => 'Menunggu Verifikasi',
        self::STATUS_PARTIAL_VERIFIED => 'Sebagian Terverifikasi',
        self::STATUS_VERIFIED => 'Selesai',
    ];

    protected $fillable = [
        'reference', 'sales_order_id', 'delivery_note_id', 'customer_id', 'warehouse_id',
        'status', 'reason',
        'reported_by', 'reported_at',
        'approved_by', 'approved_at', 'approval_note',
        'verified_by', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'approved_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------ Relasi */

    public function details(): HasMany
    {
        return $this->hasMany(SalesReturnDetail::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /* ------------------------------------------------------------ Scope */

    /** Menunggu keputusan Logistik atas klaimnya. */
    public function scopeMenungguPersetujuan(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REPORTED);
    }

    /** Barangnya menunggu dinaikkan Operator. */
    public function scopeMenungguPutaway(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUTAWAY_PENDING);
    }

    /** Sudah di rak, menunggu Logistik memeriksa barangnya. */
    public function scopeMenungguVerifikasi(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_VERIFICATION_PENDING,
            self::STATUS_PARTIAL_VERIFIED,
        ]);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('reference', 'ILIKE', $like)
                ->orWhereHas('salesOrder', fn ($o) => $o->where('order_number', 'ILIKE', $like)
                    ->orWhere('bc_so_number', 'ILIKE', $like))
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'ILIKE', $like)
                    ->orWhere('code', 'ILIKE', $like));
        });
    }

    /* ---------------------------------------------------------- Atribut */

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_REPORTED => 'warning',
            self::STATUS_REJECTED => 'danger',
            self::STATUS_VERIFIED => 'success',
            default => 'primary',
        };
    }

    /**
     * Seluruh barisnya sudah diperiksa Logistik?
     *
     * Dipakai untuk memutuskan verified vs partial_verified. Menghitungnya di
     * sini, bukan menyimpannya sebagai kolom, karena jawabannya seluruhnya
     * ditentukan baris-barisnya — kolom terpisah hanya menambah satu tempat
     * lagi yang bisa berbeda pendapat.
     */
    public function seluruhBarisTerverifikasi(): bool
    {
        return ! $this->details()->where('is_verified', false)->exists();
    }

    public function seluruhBarisSudahNaikRak(): bool
    {
        return ! $this->details()->whereNull('putaway_at')->exists();
    }
}
