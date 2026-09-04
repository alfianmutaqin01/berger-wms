<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foto Surat Jalan bertanda tangan pelanggan — PRD §6.5 F-OUT-05/F-OUT-06.
 *
 * Diunggah Sales dari HP-nya (galeri atau kamera langsung), diperiksa
 * Logistik. Foto yang ditolak TIDAK dihapus: ia bagian dari riwayat, dan
 * alasan penolakannyalah yang memberi tahu Sales apa yang harus diulang.
 */
class DeliveryProof extends Model
{
    use HasFactory;

    /** Sudah diunggah, menunggu diperiksa Logistik. */
    public const STATUS_PENDING = 'pending';

    /** Logistik menyatakan bukti ini sah. */
    public const STATUS_VERIFIED = 'verified';

    /** Ditolak; Sales harus mengunggah ulang. */
    public const STATUS_REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Menunggu Diperiksa',
        self::STATUS_VERIFIED => 'Sah',
        self::STATUS_REJECTED => 'Ditolak',
    ];

    public const STATUS_COLORS = [
        self::STATUS_PENDING => 'warning',
        self::STATUS_VERIFIED => 'success',
        self::STATUS_REJECTED => 'danger',
    ];

    /** PRD F-OUT-05: PNG atau JPG saja, maksimal 5 MB, paling banyak 3 foto. */
    public const MAKS_UKURAN_KB = 5120;

    public const MAKS_FOTO = 3;

    public const MIME_DIIZINKAN = ['image/jpeg', 'image/png'];

    protected $fillable = [
        'sales_order_id', 'delivery_note_id',
        'path', 'original_name', 'size', 'mime',
        'status', 'rejection_reason',
        'uploaded_by', 'uploaded_at', 'verified_by', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'uploaded_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------ Relasi */

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /* ----------------------------------------------------------- Saringan */

    /**
     * Foto yang masih "hidup": menunggu diperiksa atau sudah dinyatakan sah.
     *
     * Batas 3 foto dihitung terhadap ini, BUKAN terhadap seluruh baris.
     * Kalau yang ditolak ikut dihitung, Sales yang tiga kali salah foto
     * terkunci selamanya dan pesanannya tidak akan pernah selesai.
     */
    public function scopeMasihBerlaku(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_VERIFIED]);
    }

    public function scopeMenunggu(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /* ---------------------------------------------------------- Tampilan */

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'secondary';
    }

    /** Ukuran yang enak dibaca di layar HP. */
    public function getUkuranRingkasAttribute(): string
    {
        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1).' MB'
            : max(1, (int) round($this->size / 1024)).' KB';
    }
}
