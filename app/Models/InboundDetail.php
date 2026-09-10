<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu palet fisik dalam dokumen inbound.
 *
 * `total_qty` menyimpan jumlah asli sebelum dipecah, sehingga tetap terlihat
 * bahwa palet 1 (180) dan palet 2 (55) berasal dari satu baris produksi 235 pcs.
 */
class InboundDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'inbound_header_id',
        'product_id',
        'production_order_no',
        'batch_no',
        'total_qty',
        'pallet_no',
        'pallet_qty',
        'pallet_qty_original',
        'location_id',
        'qty_actual',
        'qty_adjusted_by',
        'qty_adjusted_at',
        'qty_adjust_reason',
        'putaway_by',
        'putaway_at',
        'is_verified',
        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'total_qty' => 'integer',
            'pallet_no' => 'integer',
            'pallet_qty' => 'integer',
            'pallet_qty_original' => 'integer',
            'qty_actual' => 'integer',
            'is_verified' => 'boolean',
            'qty_adjusted_at' => 'datetime',
            'putaway_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function header(): BelongsTo
    {
        return $this->belongsTo(InboundHeader::class, 'inbound_header_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** Operator yang menempatkan palet ini (maker pada alur Maker-Checker). */
    public function putawayBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'putaway_by');
    }

    /** Logistik yang memverifikasi palet ini (checker pada alur Maker-Checker). */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** Tim Produksi yang menyesuaikan angka dokumen ke hasil hitung fisik. */
    public function qtyAdjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qty_adjusted_by');
    }

    /**
     * Angka yang DITULIS TIM PRODUKSI di berkasnya, sebelum disesuaikan.
     *
     * Inilah acuan seluruh perhitungan selisih. Setelah tombol "Sesuaikan"
     * ditekan, pallet_qty sudah sama dengan hasil hitung fisik — memakai
     * pallet_qty sebagai acuan akan membuat selisihnya menguap, dan layar
     * verifikasi Logistik berhenti menyala merah untuk palet yang justru
     * paling perlu diperiksa.
     */
    public function getQtySistemAsliAttribute(): int
    {
        return $this->pallet_qty_original ?? $this->pallet_qty;
    }

    /**
     * Selisih antara jumlah fisik dan jumlah yang DITULIS PRODUKSI.
     *
     * PRD §6.3 F-INB-02: Operator boleh mengoreksi Qty Aktual; selisihnya
     * ditandai agar mendapat perhatian khusus saat verifikasi Logistik.
     *
     * Dibandingkan dengan qty_sistem_asli, bukan pallet_qty — lihat alasannya
     * di atas. Penyesuaian Produksi menambah keterangan, tidak menghapus
     * temuan.
     */
    public function getQtyVarianceAttribute(): ?int
    {
        return $this->qty_actual === null ? null : $this->qty_actual - $this->qty_sistem_asli;
    }

    /** Sudah disesuaikan Tim Produksi ke hasil hitung fisik? */
    public function getSudahDisesuaikanAttribute(): bool
    {
        return $this->pallet_qty_original !== null;
    }

    /**
     * Palet yang qty fisiknya berbeda dari yang ditulis Produksi.
     *
     * Ditulis sebagai scope, bukan diulang sebagai `whereColumn('qty_actual',
     * '!=', 'pallet_qty')` di tiap tempat yang membutuhkannya. Pengulangan
     * itulah yang membuat penyesuaian Produksi diam-diam menghapus selisih
     * dari layar Logistik: satu tempat diperbarui, dua tempat lain tidak.
     */
    public function scopeBerselisih(Builder $query): Builder
    {
        return $query
            ->whereNotNull('qty_actual')
            ->whereRaw('qty_actual <> COALESCE(pallet_qty_original, pallet_qty)');
    }

    /** Selisih yang BELUM ditanggapi Tim Produksi — inilah yang perlu tombol. */
    public function scopeSelisihBelumDitanggapi(Builder $query): Builder
    {
        return $query->berselisih()->whereNull('qty_adjusted_at');
    }

    /** Palet yang sudah ditempatkan Operator ke sebuah bin. */
    public function scopePlaced(Builder $query): Builder
    {
        return $query->whereNotNull('location_id');
    }

    /**
     * Jumlah yang berlaku untuk stok: hasil hitung fisik bila ada.
     *
     * Sebelum put-away, `qty_actual` masih kosong dan angka sistem yang dipakai.
     */
    public function getEffectiveQtyAttribute(): int
    {
        return $this->qty_actual ?? $this->pallet_qty;
    }
}
