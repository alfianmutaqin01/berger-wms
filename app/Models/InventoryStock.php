<?php

namespace App\Models;

use App\Support\ShelfLife;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Stok aktual di gudang — satu baris per produk × lokasi × batch.
 *
 * Pemecahan per batch itulah yang membuat FIFO (PRD §7.2) dan aturan
 * kedaluwarsa (§7.2.1) bisa berjalan; jangan pernah meleburnya jadi satu
 * angka per produk.
 */
class InventoryStock extends Model
{
    use HasFactory;

    /** Layak jual, ikut alokasi FIFO. */
    public const STATUS_ACTIVE = 'active';

    /** Rusak / karantina — TIDAK PERNAH ikut alokasi. */
    public const STATUS_DDP = 'ddp';

    /** Lewat masa simpan, dipindahkan otomatis oleh sweep harian. */
    public const STATUS_EXPIRED = 'expired';

    public const STATUS_LABELS = [
        self::STATUS_ACTIVE => 'Good Stock',
        self::STATUS_DDP => 'Stok DDP',
        self::STATUS_EXPIRED => 'Kedaluwarsa',
    ];

    public const DDP_EXPIRED = 'EXPIRED';

    public const DDP_RETURN_DAMAGED = 'RETURN_DAMAGED';

    public const DDP_WRITE_OFF = 'WRITE_OFF';

    public const DDP_OPNAME = 'OPNAME';

    public const DDP_REASON_LABELS = [
        self::DDP_EXPIRED => 'Lewat masa simpan',
        self::DDP_RETURN_DAMAGED => 'Retur rusak',
        self::DDP_WRITE_OFF => 'Write-off',
        self::DDP_OPNAME => 'Temuan opname',
    ];

    protected $fillable = [
        'product_id',
        'location_id',
        'warehouse_id',
        'batch_no',
        'qty_available',
        'qty_allocated',
        'production_date',
        'expiry_date',
        'status',
        'ddp_reason',
        'inbound_detail_id',
        'sales_return_detail_id',
        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'qty_available' => 'integer',
            'qty_allocated' => 'integer',
            'production_date' => 'date',
            'expiry_date' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relasi
    |--------------------------------------------------------------------------
    */

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function inboundDetail(): BelongsTo
    {
        return $this->belongsTo(InboundDetail::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    */

    /**
     * Stok yang boleh dijual: aktif DAN belum lewat tanggal kedaluwarsa.
     *
     * Kedua syarat WAJIB bersama-sama (PRD §7.2). Menyaring `status` saja
     * tidak cukup: batch yang kedaluwarsa hari ini masih berstatus 'active'
     * sampai sweep harian jalan pukul 00:05, dan pada sela itu barang
     * kedaluwarsa bisa ikut teralokasi ke pelanggan.
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereDate('expiry_date', '>', now()->toDateString());
    }

    /** Urutan FIFO: batch tertua keluar duluan. */
    public function scopeFifo(Builder $query): Builder
    {
        return $query->orderBy('production_date')->orderBy('id');
    }

    /** Stok yang tidak layak jual: DDP maupun kedaluwarsa. */
    public function scopeQuarantined(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DDP, self::STATUS_EXPIRED]);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('batch_no', 'ILIKE', $like)
                ->orWhereHas('product', fn (Builder $p) => $p->where('sku', 'ILIKE', $like)
                    ->orWhere('name', 'ILIKE', $like))
                ->orWhereHas('inboundDetail', fn (Builder $d) => $d->where('production_order_no', 'ILIKE', $like));
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Accessor
    |--------------------------------------------------------------------------
    */

    /** Total fisik di rak: yang tersedia ditambah yang sudah dikunci untuk order. */
    public function getQtyOnHandAttribute(): int
    {
        return $this->qty_available + $this->qty_allocated;
    }

    /** Sisa umur simpan siap tampil, mis. "5 bln 3 minggu". */
    public function getShelfLifeLabelAttribute(): string
    {
        return ShelfLife::remainingLabel($this->expiry_date);
    }

    /** 'expired' | 'critical' | 'warning' | 'safe' — untuk mewarnai baris. */
    public function getShelfLifeUrgencyAttribute(): string
    {
        return ShelfLife::urgency($this->expiry_date);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getDdpReasonLabelAttribute(): ?string
    {
        return $this->ddp_reason === null
            ? null
            : (self::DDP_REASON_LABELS[$this->ddp_reason] ?? $this->ddp_reason);
    }

    /**
     * Menghitung tanggal kedaluwarsa dari tanggal produksi & masa simpan produk.
     *
     * Dipakai saat stok diaktifkan (PRD §7.2.1 EXPIRY_CALCULATION). Hasilnya
     * DISIMPAN, tidak dihitung ulang saat query — supaya mengubah masa simpan
     * di Master Produk tidak diam-diam menggeser kedaluwarsa batch yang sudah
     * telanjur ada di rak.
     */
    public static function calculateExpiry(\DateTimeInterface $productionDate, ?int $shelfLifeMonths): CarbonInterface
    {
        return Carbon::instance($productionDate)
            ->copy()
            ->addMonths($shelfLifeMonths ?? 30);
    }
}
