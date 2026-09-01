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

    /**
     * Dokumen yang masih menunggu put-away.
     *
     * Termasuk dokumen yang put-away-nya baru sebagian: statusnya tetap
     * `putaway_pending` sampai SELURUH palet punya lokasi, karena pekerjaan
     * fisik di lantai gudang lazim terputus di tengah jalan dan Operator harus
     * bisa melanjutkannya nanti tanpa kehilangan yang sudah ditempatkan.
     */
    public function scopeAwaitingPutaway(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUTAWAY_PENDING);
    }

    /**
     * Apakah seluruh palet sudah punya lokasi rak?
     *
     * Menjadi penentu apakah dokumen naik ke tahap verifikasi Logistik.
     */
    public function isFullyPlaced(): bool
    {
        return $this->details()->whereNull('location_id')->doesntExist();
    }

    /**
     * Dokumen yang menunggu verifikasi Logistik (F-INB-03).
     *
     * `partial_verified` ikut masuk: dokumen yang baru sebagian diverifikasi
     * HARUS tetap muncul di daftar, karena PRD §6.3 F-INB-03 langkah 8
     * mengizinkan Logistik MENUNDA verifikasi di tengah jalan. Kalau
     * statusnya dikeluarkan dari daftar, sisa paletnya tidak akan pernah
     * bisa diselesaikan lewat layar manapun.
     */
    public function scopeAwaitingVerification(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_VERIFICATION_PENDING,
            self::STATUS_PARTIAL_VERIFIED,
        ]);
    }

    /**
     * Status yang seharusnya dipegang dokumen ini menurut isi paletnya.
     *
     * Dihitung dari data, bukan dari urutan aksi — sehingga tidak bisa
     * tertinggal tidak sinkron kalau suatu saat ada jalur lain yang mengubah
     * palet.
     */
    public function resolveVerificationStatus(): string
    {
        $total = $this->details()->count();
        $terverifikasi = $this->details()->where('is_verified', true)->count();

        return match (true) {
            $total > 0 && $terverifikasi === $total => self::STATUS_VERIFIED,
            $terverifikasi > 0 => self::STATUS_PARTIAL_VERIFIED,
            default => self::STATUS_VERIFICATION_PENDING,
        };
    }
}
