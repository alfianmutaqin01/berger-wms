<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Satu peristiwa kekurangan pada sebuah baris pesanan.
 *
 * BUKAN keadaan sekarang. Berapa yang masih kurang HARI INI dibaca dari
 * `sales_order_details.outstanding_qty`; tabel ini menjawab pertanyaan yang
 * berbeda — kapan kekurangan itu muncul, sebesar apa, dan karena apa.
 *
 * Append-only, seperti stock_movements: baris riwayat yang bisa diperbaiki
 * belakangan bukan riwayat, melainkan pendapat.
 */
class SalesOrderOutstanding extends Model
{
    use HasFactory;

    /**
     * Disetujui kurang dari yang dipesan.
     *
     * Keputusan Logistik saat menerima pesanan — mis. pesan 10, stok hanya
     * cukup 5. Inilah bentuk outstanding yang paling sering ditanyakan Sales.
     */
    public const CAUSE_APPROVAL = 'approval';

    /**
     * Yang berangkat kurang dari yang dipesan.
     *
     * Muncul setelah Surat Jalan berangkat: barang yang benar-benar naik
     * kendaraan ternyata lebih sedikit daripada yang dijanjikan. Terpisah dari
     * CAUSE_APPROVAL karena tindak lanjutnya berbeda — yang satu soal stok
     * saat penerimaan, yang satu soal apa yang terjadi di dock.
     */
    public const CAUSE_SHIPMENT = 'shipment';

    public const CAUSE_LABELS = [
        self::CAUSE_APPROVAL => 'Disetujui sebagian',
        self::CAUSE_SHIPMENT => 'Kurang dikirim',
    ];

    protected $fillable = [
        'sales_order_id',
        'sales_order_detail_id',
        'product_id',
        'warehouse_id',
        'cause',
        'qty_ordered',
        'qty_fulfilled',
        'qty_outstanding',
        'note',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'qty_ordered' => 'integer',
            'qty_fulfilled' => 'integer',
            'qty_outstanding' => 'integer',
        ];
    }

    /**
     * Memasang pagar append-only.
     *
     * Pola yang sama dengan StockMovement. Menolak lewat exception, bukan
     * `return false`, supaya percobaan menyunting riwayat tidak bisa gagal
     * diam-diam dan luput dari perhatian.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException(
                'sales_order_outstandings bersifat append-only: riwayat outstanding tidak boleh diubah. '.
                'Kekurangan yang berubah dicatat sebagai baris baru.'
            );
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'sales_order_outstandings bersifat append-only: riwayat outstanding tidak boleh dihapus.'
            );
        });
    }

    /* ------------------------------------------------------------ Relasi */

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function detail(): BelongsTo
    {
        return $this->belongsTo(SalesOrderDetail::class, 'sales_order_detail_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /* ------------------------------------------------------------ Scope */

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $pola = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($pola): void {
            $q->whereHas('salesOrder', fn (Builder $o) => $o->where('order_number', 'ILIKE', $pola)
                ->orWhere('customer_po_number', 'ILIKE', $pola)
                ->orWhere('bc_so_number', 'ILIKE', $pola)
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'ILIKE', $pola)
                    ->orWhere('code', 'ILIKE', $pola)))
                ->orWhereHas('product', fn (Builder $p) => $p->where('sku', 'ILIKE', $pola)
                    ->orWhere('name', 'ILIKE', $pola));
        });
    }

    /* --------------------------------------------------------- Accessor */

    public function getCauseLabelAttribute(): string
    {
        return self::CAUSE_LABELS[$this->cause] ?? $this->cause;
    }

    /**
     * Berapa yang MASIH kurang dari baris ini hari ini.
     *
     * Dibaca dari baris pesanannya, bukan dari kolom di sini: angka di sini
     * adalah cuplikan masa lalu dan memang tidak boleh ikut berubah. NULL
     * berarti barisnya sudah dicabut dari pesanan, sehingga tidak ada lagi
     * kewajiban yang bisa ditagih — berbeda dari nol yang berarti terpenuhi.
     */
    public function getSisaSekarangAttribute(): ?int
    {
        return $this->detail?->outstanding_qty;
    }

    /** Sudah tidak menyisakan kewajiban apa pun ke customer. */
    public function getSudahTertutupAttribute(): bool
    {
        return ($this->sisa_sekarang ?? 0) === 0;
    }
}
