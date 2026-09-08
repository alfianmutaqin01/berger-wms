<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Satu baris log aktivitas: siapa melakukan apa, kapan.
 *
 * APPEND-ONLY. Ditegakkan di sini, bukan cuma disepakati: log yang bisa
 * disunting oleh orang yang tercatat di dalamnya tidak bisa dijadikan
 * pegangan saat ada yang perlu dipertanggungjawabkan. Pola yang sama dengan
 * StockMovement.
 */
class ActivityLog extends Model
{
    use HasFactory;

    /* ------------------------------------------------- Nama tindakan baku */

    public const STOCK_ADD = 'inventory.add';

    public const STOCK_ADJUST = 'inventory.adjust';

    public const STOCK_TRANSFER = 'inventory.transfer';

    public const QUARANTINE_PLACE = 'inventory.quarantine.place';

    public const QUARANTINE_RELEASE = 'inventory.quarantine.release';

    public const QUALITY_ISSUE = 'inventory.quality-issue';

    public const PRIORITIZE = 'inventory.prioritize';

    public const PRIORITIZE_RELEASE = 'inventory.prioritize.release';

    public const BOOKING_CREATE = 'booking.create';

    public const BOOKING_CANCEL = 'booking.cancel';

    public const STOCKTAKE_FINALIZE = 'stocktake.finalize';

    public const PICKING_RELEASE = 'picking.release';

    public const RETURN_APPROVE = 'return.approve';

    public const RETURN_REJECT = 'return.reject';

    public const RETURN_PUTAWAY = 'return.putaway';

    public const RETURN_VERIFY = 'return.verify';

    public const ORDER_RESHIP = 'order.reship';

    /** Label Indonesia untuk penyaring & tampilan. */
    public const ACTION_LABELS = [
        self::STOCK_ADD => 'Tambah Stok',
        self::STOCK_ADJUST => 'Koreksi Stok',
        self::STOCK_TRANSFER => 'Pindah Rak',
        self::QUARANTINE_PLACE => 'Karantina',
        self::QUARANTINE_RELEASE => 'Lepas Karantina',
        self::QUALITY_ISSUE => 'Penanda Quality Issue',
        self::PRIORITIZE => 'Dahulukan Keluar',
        self::PRIORITIZE_RELEASE => 'Lepas Dahulukan Keluar',
        self::BOOKING_CREATE => 'Buat Booking',
        self::BOOKING_CANCEL => 'Batal Booking',
        self::STOCKTAKE_FINALIZE => 'Sahkan Stocktake',
        self::PICKING_RELEASE => 'Lepas Tugas Picking',
        self::RETURN_APPROVE => 'Setujui Penolakan Customer',
        self::RETURN_REJECT => 'Tolak Laporan Penolakan',
        self::RETURN_PUTAWAY => 'Naikkan Barang Tolakan',
        self::RETURN_VERIFY => 'Verifikasi Barang Tolakan',
        self::ORDER_RESHIP => 'Kirim Ulang Outstanding',
    ];

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'user_name', 'user_role', 'action', 'description',
        'subject_type', 'subject_id', 'warehouse_id', 'properties',
        'ip_address', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Log aktivitas tidak boleh diubah — isinya jejak pertanggungjawaban.');
        });

        static::deleting(function () {
            throw new RuntimeException('Log aktivitas tidak boleh dihapus — isinya jejak pertanggungjawaban.');
        });
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

    /* ------------------------------------------------------------ Atribut */

    public function getActionLabelAttribute(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    /** Nama pelaku sebagaimana tercatat saat kejadian, bukan sekarang. */
    public function getPelakuAttribute(): string
    {
        return $this->user_name ?? 'Sistem';
    }
}
