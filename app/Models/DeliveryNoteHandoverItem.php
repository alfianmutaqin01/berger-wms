<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu lembar Surat Jalan di dalam sebuah amplop.
 *
 * TIGA HASIL PEMERIKSAAN, BUKAN DUA. "Sesuai / tidak sesuai" terdengar cukup
 * sampai amplopnya benar-benar dibuka: ada bedanya antara lembar yang ADA
 * tetapi tanda tangannya tidak terbaca, dan lembar yang sama sekali TIDAK ADA
 * di dalam amplop. Yang pertama tidak bisa diperbaiki dengan mengirim ulang —
 * lembarnya memang cuma itu, yang dibutuhkan hanya catatannya. Yang kedua
 * harus kembali ke daftar kirim, karena kertasnya masih ada di gudang atau
 * hilang di jalan dan seseorang harus mencarinya.
 *
 * Tanpa pembedaan itu, lembar yang hilang tercatat "sudah dikirim" selamanya
 * dan tidak pernah muncul lagi di layar siapa pun.
 */
class DeliveryNoteHandoverItem extends Model
{
    use HasFactory;

    /** Lembarnya ada dan isinya benar. */
    public const CHECK_OK = 'ok';

    /** Lembarnya ada, tetapi ada yang tidak beres — wajib beralasan. */
    public const CHECK_ISSUE = 'issue';

    /** Tercatat di daftar, tetapi tidak ada di dalam amplop. */
    public const CHECK_MISSING = 'missing';

    public const CHECK_LABELS = [
        self::CHECK_OK => 'Sesuai',
        self::CHECK_ISSUE => 'Ada, tapi bermasalah',
        self::CHECK_MISSING => 'Tidak ada di amplop',
    ];

    public const CHECK_BADGES = [
        self::CHECK_OK => 'success',
        self::CHECK_ISSUE => 'warning',
        self::CHECK_MISSING => 'danger',
    ];

    /** Amplopnya dibatalkan sebelum sampai. */
    public const RELEASED_CANCELLED = 'cancelled';

    /** CA menyatakan lembarnya tidak ada di dalam amplop. */
    public const RELEASED_MISSING = 'missing';

    protected $fillable = [
        'delivery_note_handover_id', 'delivery_note_id',
        'check_status', 'check_note', 'checked_at', 'checked_by',
        'released_at', 'released_reason',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------ Relasi */

    public function handover(): BelongsTo
    {
        return $this->belongsTo(DeliveryNoteHandover::class, 'delivery_note_handover_id');
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    /* ------------------------------------------------------------- Scope */

    /**
     * Baris yang masih memegang Surat Jalan-nya.
     *
     * Inilah yang menjawab "SJ ini sudah dikirim ke HO belum?" — dan
     * pasangannya di basis data adalah indeks unik parsial dnh_items_satu_
     * paket_hidup, yang memakai syarat yang sama persis.
     */
    public function scopeAktif(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    /* ------------------------------------------------------------ Aturan */

    public function getCheckLabelAttribute(): ?string
    {
        return $this->check_status === null
            ? null
            : (self::CHECK_LABELS[$this->check_status] ?? $this->check_status);
    }

    public function getCheckBadgeAttribute(): string
    {
        return self::CHECK_BADGES[$this->check_status] ?? 'secondary';
    }

    public function dilepas(): bool
    {
        return $this->released_at !== null;
    }
}
