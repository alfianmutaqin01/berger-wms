<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu email kabar pesanan untuk Sales pemilik pesanan.
 *
 * Penerimanya SELALU pemilik pesanan (sales_orders.user_id) dan hanya dia —
 * keputusan pemilik produk. Bukan Admin yang membuatkan pesanannya, bukan
 * atasan, bukan customer.
 *
 * Email adalah JALUR CADANGAN, berdampingan dengan lonceng web dan WhatsApp.
 * Kegagalannya tidak pernah membatalkan apa pun yang memicunya.
 */
class SalesOrderEmail extends Model
{
    public const TYPE_APPROVED = 'pesanan_diterima';

    public const TYPE_REJECTED = 'pesanan_ditolak';

    public const TYPE_SHIPPED = 'barang_dikirim';

    public const TYPE_DELIVERED = 'barang_sampai';

    public const TYPE_COMPLETED = 'pesanan_selesai';

    public const TYPE_LABELS = [
        self::TYPE_APPROVED => 'Pesanan diterima',
        self::TYPE_REJECTED => 'Pesanan ditolak',
        self::TYPE_SHIPPED => 'Barang dikirim',
        self::TYPE_DELIVERED => 'Barang sampai',
        self::TYPE_COMPLETED => 'Ringkasan pesanan selesai',
    ];

    /** Sudah dicatat, menunggu antrean. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    /** Ada yang rusak dan perlu ditindaklanjuti — alasannya di kolom error. */
    public const STATUS_FAILED = 'failed';

    /**
     * Sengaja tidak dikirim karena kabarnya sudah tidak benar lagi saat
     * antrean sampai padanya — mis. pesanan yang dibatalkan sebelum email
     * "diterima" keluar. BUKAN kegagalan: tidak ada yang perlu diperbaiki.
     */
    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Menunggu dikirim',
        self::STATUS_SENT => 'Terkirim',
        self::STATUS_FAILED => 'Gagal',
        self::STATUS_SKIPPED => 'Tidak dikirim',
    ];

    protected $fillable = [
        'sales_order_id', 'delivery_note_id', 'type', 'data',
        'status', 'attempts', 'recipient_user_id', 'recipient_email', 'error', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
