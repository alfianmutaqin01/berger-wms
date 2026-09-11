<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris yang harus diambil operator: satu batch, di satu rak, untuk
 * satu baris pesanan.
 *
 * Bukan satu baris per SKU. Pesanan 100 kaleng bisa terpecah ke tiga batch
 * di tiga rak berbeda karena FIFO; meleburnya jadi satu baris "100 kaleng"
 * membuat operator harus menebak sendiri dari rak mana ia mengambil, dan
 * tebakan itulah yang merusak urutan FIFO yang sudah susah payah dihitung.
 */
class PickingListItem extends Model
{
    use HasFactory;

    /** Belum disentuh operator. */
    public const STATUS_PENDING = 'pending';

    /** Diambil sesuai daftar. */
    public const STATUS_PICKED = 'picked';

    /** Diambil kurang dari daftar — wajib beralasan. */
    public const STATUS_SHORT = 'short';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Belum diambil',
        self::STATUS_PICKED => 'Diambil',
        self::STATUS_SHORT => 'Kurang',
    ];

    protected $fillable = [
        'picking_list_id', 'sales_order_id', 'sales_order_detail_id',
        'stock_transfer_detail_id', 'product_id',
        'inventory_stock_id', 'location_id', 'batch_no', 'production_date',
        'qty_to_pick', 'qty_picked', 'status', 'discrepancy_reason',
        'picked_at', 'picked_by',
    ];

    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'qty_to_pick' => 'integer',
            'qty_picked' => 'integer',
            'picked_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------ Relasi */

    public function pickingList(): BelongsTo
    {
        return $this->belongsTo(PickingList::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function detail(): BelongsTo
    {
        return $this->belongsTo(SalesOrderDetail::class, 'sales_order_detail_id');
    }

    /**
     * Baris transfer antar gudang yang dikerjakan baris ini.
     *
     * Terisi HANYA pada baris transfer; baris pesanan mengisi salesOrder()
     * dan detail() sebagai gantinya. Tepat satu di antaranya wajib ada —
     * ditegakkan CHECK picking_list_items_satu_jenis_pekerjaan, bukan hanya
     * kesepakatan: baris yang menunjuk keduanya dikurangi dua kali dari rak.
     */
    public function transferDetail(): BelongsTo
    {
        return $this->belongsTo(StockTransferDetail::class, 'stock_transfer_detail_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(InventoryStock::class, 'inventory_stock_id');
    }

    public function pickedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'picked_by');
    }

    /* ------------------------------------------------------------ Scope */

    /**
     * Baris picking dari PUTARAN YANG SEDANG BERJALAN saja.
     *
     * WAJIB dipakai setiap kali baris picking dicari lewat sales_order_id.
     * Sebuah pesanan bisa dipicking BERKALI-KALI: pesanan yang dibatalkan
     * kembali ke antrean, barangnya dikembalikan ke rak, lalu pesanan itu
     * diterima dan dipicking lagi di daftar yang baru. Baris picking putaran
     * lama TIDAK dihapus — ia riwayat daftar picking yang memang sudah
     * selesai — sehingga menjumlahkan seluruh baris milik satu pesanan akan
     * menghitung barang yang sudah lama dikembalikan ke rak.
     *
     * Akibatnya bukan cuma angka layar yang keliru. Pengembalian stok juga
     * dicari dengan cara yang sama, jadi tanpa penyaringan ini pembatalan
     * kedua akan mengembalikan barang putaran pertama SEKALI LAGI — stok
     * bertambah dari ketiadaan, dan ledger tetap terlihat rapi.
     *
     * `picking_list_id` pesanan adalah penanda putaran yang berjalan: ia
     * dikosongkan saat pembatalan dan diisi lagi saat pesanan masuk daftar
     * baru. Pesanan tanpa daftar berarti tidak ada putaran yang berjalan,
     * dan scope ini benar-benar tidak boleh mengembalikan apa pun.
     */
    public function scopeForOrderRound(Builder $query, SalesOrder $order): Builder
    {
        if ($order->picking_list_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('picking_list_items.picking_list_id', $order->picking_list_id);
    }

    /* ------------------------------------------------------------ Aturan */

    public function sudahDitandai(): bool
    {
        return $this->status !== self::STATUS_PENDING;
    }

    /** Berapa yang tidak ditemukan di rak. Nol untuk baris yang normal. */
    public function getQtyKurangAttribute(): int
    {
        return max(0, $this->qty_to_pick - (int) $this->qty_picked);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
