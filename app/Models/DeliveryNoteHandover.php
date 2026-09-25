<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Paket Surat Jalan fisik — satu amplop yang berangkat ke Kantor Pusat.
 *
 * Dokumen ini TIDAK berisi barang dan tidak menyentuh stok sedikit pun. Yang
 * dilacaknya adalah kertas: lembar Surat Jalan bertanda tangan pelanggan yang
 * harus sampai ke meja Customer Account di HO. Karena itu satu-satunya hal
 * yang bisa salah di sini juga bukan angka, melainkan kelengkapan — lihat
 * DeliveryNoteHandoverItem.
 */
class DeliveryNoteHandover extends Model
{
    use HasFactory;

    /** Amplop sudah berangkat; CA belum membukanya. */
    public const STATUS_SENT = 'sent';

    /** CA sudah memeriksa seluruh isinya dan menutup paketnya. */
    public const STATUS_RECEIVED = 'received';

    /** Dibatalkan sebelum sempat dikonfirmasi; isinya kembali ke daftar. */
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::STATUS_SENT => 'Dalam Perjalanan',
        self::STATUS_RECEIVED => 'Diterima HO',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    public const STATUS_BADGES = [
        self::STATUS_SENT => 'primary',
        self::STATUS_RECEIVED => 'success',
        self::STATUS_CANCELLED => 'secondary',
    ];

    /* ----------------------------------------------------------- Cara kirim */

    /** Dibawakan orang yang kebetulan berangkat ke pusat. */
    public const CARRIER_TITIPAN = 'titipan';

    /** JNE, J&T, dan sejenisnya — punya nomor resi. */
    public const CARRIER_EKSPEDISI = 'ekspedisi';

    /** Diantar sendiri oleh orang gudang. */
    public const CARRIER_SENDIRI = 'sendiri';

    public const CARRIER_LABELS = [
        self::CARRIER_TITIPAN => 'Dititipkan ke orang',
        self::CARRIER_EKSPEDISI => 'Ekspedisi',
        self::CARRIER_SENDIRI => 'Diantar sendiri',
    ];

    protected $fillable = [
        'code', 'warehouse_id',
        'carrier_type', 'carrier_name', 'tracking_no',
        'status', 'sent_at', 'sent_by', 'notes',
        'received_at', 'received_by', 'received_notes',
        'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------ Relasi */

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryNoteHandoverItem::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
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

    public function scopeDalamPerjalanan(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENT);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $pola = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($pola): void {
            $q->where('code', 'ILIKE', $pola)
                ->orWhere('carrier_name', 'ILIKE', $pola)
                ->orWhere('tracking_no', 'ILIKE', $pola)
                // Dicari juga lewat nomor Surat Jalan yang ada di dalamnya:
                // yang ditanyakan orang hampir selalu "SJ 206215 ikut amplop
                // yang mana?", bukan nomor amplopnya.
                ->orWhereHas('items.deliveryNote', fn (Builder $sj) => $sj->where('document_no', 'ILIKE', $pola));
        });
    }

    /* ------------------------------------------------------------ Aturan */

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getStatusBadgeAttribute(): string
    {
        return self::STATUS_BADGES[$this->status] ?? 'secondary';
    }

    public function getCarrierLabelAttribute(): string
    {
        return self::CARRIER_LABELS[$this->carrier_type] ?? $this->carrier_type;
    }

    public function dalamPerjalanan(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    /** Masih boleh dibatalkan selama CA belum membukanya. */
    public function bolehDibatalkan(): bool
    {
        return $this->dalamPerjalanan();
    }

    /**
     * Semua isinya sudah diputuskan CA.
     *
     * Dasar tombol "Konfirmasi Diterima": selama masih ada baris yang belum
     * diputuskan, menutup paket berarti menyatakan lengkap sesuatu yang belum
     * dilihat seluruhnya.
     */
    public function semuaSudahDiperiksa(): bool
    {
        return $this->items->isNotEmpty()
            && $this->items->every(fn (DeliveryNoteHandoverItem $item) => $item->check_status !== null);
    }

    /** Jumlah lembar yang tidak beres — dipakai label "Diterima (n catatan)". */
    public function jumlahBermasalah(): int
    {
        return $this->items
            ->whereIn('check_status', [
                DeliveryNoteHandoverItem::CHECK_ISSUE,
                DeliveryNoteHandoverItem::CHECK_MISSING,
            ])
            ->count();
    }

    /**
     * Sudah lewat batas wajar tetapi belum dikonfirmasi.
     *
     * Dihitung saat layar dibuka, bukan disimpan di kolom. Keterlambatan
     * adalah keadaan yang berubah sendiri seiring hari berjalan; kolom yang
     * menyimpannya hanya benar sampai tengah malam berikutnya dan butuh
     * penjadwal yang bekerja tiap hari untuk tetap benar.
     */
    public function terlambat(): bool
    {
        if (! $this->dalamPerjalanan()) {
            return false;
        }

        $batas = (int) config('wms.sj_handover.batas_konfirmasi_hari');

        return $batas > 0 && $this->sent_at?->addDays($batas)->isPast() === true;
    }

    public function umurHari(): int
    {
        return (int) ($this->sent_at?->startOfDay()->diffInDays(now()->startOfDay()) ?? 0);
    }
}
