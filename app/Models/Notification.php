<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu pemberitahuan untuk satu orang.
 *
 * BUKAN log aktivitas. Keduanya lahir dari kejadian yang sama tetapi menjawab
 * pertanyaan berbeda, dan itulah alasan mereka tidak digabung:
 *
 *   ActivityLog — "siapa melakukan apa", dibaca Super Admin saat menelusuri
 *                 sesuatu yang sudah terjadi. Append-only, tidak pernah
 *                 dianggap selesai dibaca.
 *   Notification — "ada yang perlu saya kerjakan", dibaca pemiliknya sendiri
 *                 dan HABIS begitu ditindaklanjuti.
 *
 * Log mencatat SEMUA tindakan; notifikasi hanya yang menuntut orang lain
 * bergerak. Menyamakan keduanya berarti lonceng berbunyi untuk setiap
 * perubahan master data, dan orang berhenti membukanya dalam seminggu.
 */
class Notification extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    /* ------------------------------------------------------ Jenis lonceng */

    public const ORDER_PENDING = 'order.pending';

    public const ORDER_APPROVED = 'order.approved';

    public const ORDER_REJECTED = 'order.rejected';

    public const PICKING_READY = 'picking.ready';

    public const PUTAWAY_READY = 'putaway.ready';

    public const INBOUND_VERIFY_READY = 'inbound.verify_ready';

    public const INBOUND_QTY_VARIANCE = 'inbound.qty_variance';

    public const PROOF_NEEDED = 'proof.needed';

    public const PROOF_REJECTED = 'proof.rejected';

    public const RETURN_REPORTED = 'return.reported';

    public const RETURN_APPROVED = 'return.approved';

    public const RETURN_VERIFY_READY = 'return.verify_ready';

    public const TRANSFER_INCOMING = 'transfer.incoming';

    /** MRF sudah disetujui atasan; Logistik yang ditunggu sekarang. */
    public const MRF_NEEDS_LOGISTICS = 'mrf.needs_logistics';

    /** Barang MRF sudah turun dari rak dan menunggu diambil Produksi. */
    public const MRF_READY_FOR_PICKUP = 'mrf.ready_for_pickup';

    /** Keputusan Logistik atas permintaan Produksi — disetujui atau ditolak. */
    public const MRF_DECIDED = 'mrf.decided';

    /**
     * Ikon & warna per jenis.
     *
     * Ditaruh di model, bukan di Blade: lonceng dirender di dua layout
     * (Portal WMS dan Portal Sales) ditambah halaman daftarnya, dan tiga
     * salinan tabel warna yang sama pasti akan berbeda suatu hari.
     */
    public const TAMPILAN = [
        self::ORDER_PENDING => ['bi-inbox-fill', 'primary'],
        self::ORDER_APPROVED => ['bi-check-circle-fill', 'success'],
        self::ORDER_REJECTED => ['bi-x-circle-fill', 'danger'],
        self::PICKING_READY => ['bi-list-check', 'primary'],
        self::PUTAWAY_READY => ['bi-box-arrow-in-down', 'primary'],
        self::INBOUND_VERIFY_READY => ['bi-clipboard-check', 'info'],
        self::INBOUND_QTY_VARIANCE => ['bi-exclamation-diamond-fill', 'warning'],
        self::PROOF_NEEDED => ['bi-camera-fill', 'warning'],
        self::PROOF_REJECTED => ['bi-exclamation-triangle-fill', 'danger'],
        self::RETURN_REPORTED => ['bi-arrow-return-left', 'warning'],
        self::RETURN_APPROVED => ['bi-check2-square', 'success'],
        self::RETURN_VERIFY_READY => ['bi-clipboard-check', 'info'],
        self::TRANSFER_INCOMING => ['bi-truck', 'info'],
        self::MRF_NEEDS_LOGISTICS => ['bi-clipboard2-plus', 'warning'],
        self::MRF_READY_FOR_PICKUP => ['bi-box-seam', 'primary'],
        self::MRF_DECIDED => ['bi-clipboard2-check', 'info'],
    ];

    /** Yang ditampilkan di dalam lonceng sebelum "Lihat Semua". */
    public const JUMLAH_DI_LONCENG = 5;

    protected $fillable = [
        'user_id', 'type', 'title', 'body', 'url',
        'subject_type', 'subject_id', 'warehouse_id',
        'read_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------ Relasi */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /* ------------------------------------------------------------ Scope */

    public function scopeBelumDibaca(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeMilik(Builder $query, ?int $userId): Builder
    {
        // 0 untuk user yang tidak ada: lebih aman daripada mengembalikan
        // seluruh tabel saat id-nya kebetulan null.
        return $query->where('user_id', $userId ?? 0);
    }

    /* ---------------------------------------------------------- Tampilan */

    public function getIkonAttribute(): string
    {
        return self::TAMPILAN[$this->type][0] ?? 'bi-bell-fill';
    }

    public function getWarnaAttribute(): string
    {
        return self::TAMPILAN[$this->type][1] ?? 'secondary';
    }

    public function getSudahDibacaAttribute(): bool
    {
        return $this->read_at !== null;
    }
}
