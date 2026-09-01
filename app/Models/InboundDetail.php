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
        'location_id',
        'qty_actual',
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
            'qty_actual' => 'integer',
            'is_verified' => 'boolean',
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

    /**
     * Selisih antara jumlah fisik dan jumlah sistem.
     *
     * PRD §6.3 F-INB-02: Operator boleh mengoreksi Qty Aktual; selisihnya
     * ditandai agar mendapat perhatian khusus saat verifikasi Logistik.
     */
    public function getQtyVarianceAttribute(): ?int
    {
        return $this->qty_actual === null ? null : $this->qty_actual - $this->pallet_qty;
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
