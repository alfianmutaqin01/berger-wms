<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pesanan penjualan — docs/1 §6.5, docs/2 §3.5.
 *
 * Siklus hidupnya panjang (draft -> pending -> approved -> picking ->
 * ready_to_ship -> shipping -> proof_uploaded -> completed), tapi Fase 5
 * hanya memegang dua status pertama. Sisanya diisi Fase 6 ke atas; daftar
 * lengkapnya ditulis di sini sejak awal supaya tidak ada string status
 * telanjang bertebaran di controller nanti.
 */
class SalesOrder extends Model
{
    use HasFactory, SoftDeletes;

    /** Belum disubmit. Masih boleh diubah dan dihapus Sales. */
    public const STATUS_DRAFT = 'draft';

    /** Sudah disubmit, menunggu Logistik. TIDAK BOLEH diubah Sales lagi. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PICKING = 'picking';

    public const STATUS_READY_TO_SHIP = 'ready_to_ship';

    public const STATUS_SHIPPING = 'shipping';

    public const STATUS_PROOF_UPLOADED = 'proof_uploaded';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_BILLING = 'completed_billing';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_PENDING => 'Menunggu Diterima',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_REJECTED => 'Ditolak',
        self::STATUS_PICKING => 'Proses Picking',
        self::STATUS_READY_TO_SHIP => 'Siap Kirim',
        self::STATUS_SHIPPING => 'Dalam Pengiriman',
        self::STATUS_PROOF_UPLOADED => 'Menunggu Verifikasi Bukti',
        self::STATUS_COMPLETED => 'Complete',
        self::STATUS_COMPLETED_BILLING => 'Complete (Menunggu Bayar)',
    ];

    /** Sales mengisi sendiri rincian item dan qty-nya. */
    public const SOURCE_MANUAL = 'manual';

    /**
     * Sales mengunggah dokumen PO customer; rincian item menyusul, diisi
     * Logistik saat approval sambil membaca dokumen itu (Fase 6).
     */
    public const SOURCE_DOCUMENT = 'document';

    protected $fillable = [
        'order_number', 'customer_po_number', 'bc_so_number',
        'customer_id', 'user_id', 'warehouse_id', 'payment_term_id',
        'status', 'order_source',
        'document_path', 'document_name', 'document_size', 'document_mime',
        'submitted_at', 'approved_at', 'approved_by',
        'rejected_at', 'rejected_by', 'rejection_reason',
        'picking_completed_at', 'shipped_at', 'delivered_at',
        'completed_at', 'sla_hours', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'picking_completed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
            'document_size' => 'integer',
            'sla_hours' => 'decimal:2',
        ];
    }

    /* ------------------------------------------------------------ Relasi */

    public function details(): HasMany
    {
        return $this->hasMany(SalesOrderDetail::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    /** Sales yang membuat pesanan. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /* ------------------------------------------------------------ Scope */

    public function scopeDrafts(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /** Pesanan milik satu Sales. Dipakai halaman "Pesanan Saya". */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $pola = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($pola): void {
            $q->where('order_number', 'ILIKE', $pola)
                ->orWhere('customer_po_number', 'ILIKE', $pola)
                ->orWhere('bc_so_number', 'ILIKE', $pola)
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'ILIKE', $pola)
                    ->orWhere('code', 'ILIKE', $pola));
        });
    }

    /* ------------------------------------------------------------ Aturan */

    /**
     * Hanya draft yang boleh diubah atau dihapus Sales (F-OUT-01 #7).
     *
     * Sesudah submit, pesanan sudah masuk antrean Logistik dan mungkin
     * sedang dinilai; mengubah isinya di belakang layar berarti Logistik
     * menyetujui sesuatu yang berbeda dari yang dilihatnya.
     */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** Pesanan bermetode dokumen: rincian item menyusul dari Logistik. */
    public function isDocumentBased(): bool
    {
        return $this->order_source === self::SOURCE_DOCUMENT;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Warna badge status di layar (docs/4 §6). */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'secondary',
            self::STATUS_PENDING => 'warning',
            self::STATUS_REJECTED => 'danger',
            self::STATUS_COMPLETED, self::STATUS_COMPLETED_BILLING => 'success',
            default => 'primary',
        };
    }

    /**
     * Nomor yang paling berarti bagi customer.
     *
     * Untuk pesanan bermetode dokumen, customer mengenali pesanannya lewat
     * nomor PO mereka sendiri — nomor internal kita tidak berarti apa-apa
     * di sisi mereka.
     */
    public function getDisplayNumberAttribute(): string
    {
        return $this->customer_po_number ?: $this->order_number;
    }
}
