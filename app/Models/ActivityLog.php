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

    /* ---------------------------------------------- Alur pesanan (Fase 9) */

    public const ORDER_SUBMIT = 'order.submit';

    public const ORDER_APPROVE = 'order.approve';

    public const ORDER_REJECT = 'order.reject';

    public const ORDER_CANCEL = 'order.cancel';

    /* --------------------------------------------- Barang masuk (Fase 9) */

    public const INBOUND_CREATE = 'inbound.create';

    public const INBOUND_PUTAWAY = 'inbound.putaway';

    public const INBOUND_VERIFY = 'inbound.verify';

    /* ----------------------------------------------- Pengiriman (Fase 9) */

    public const DELIVERY_SHIP = 'delivery.ship';

    public const DELIVERY_SUBSTITUTION = 'delivery.substitution';

    public const EPOD_CONFIRM = 'epod.confirm';

    public const PROOF_UPLOAD = 'proof.upload';

    public const PROOF_VERIFY = 'proof.verify';

    public const PROOF_REJECT = 'proof.reject';

    /** Sales melapor; empat tindakan retur lainnya sudah ada di atas. */
    public const RETURN_REPORT = 'return.report';

    /* ------------------------------------------------- Transfer (Fase 9) */

    public const TRANSFER_CREATE = 'transfer.create';

    public const TRANSFER_RECEIVE = 'transfer.receive';

    /* -------------------------------------- Pengguna & master data (Fase 9) */

    public const USER_CREATE = 'user.create';

    public const USER_UPDATE = 'user.update';

    public const USER_DEACTIVATE = 'user.deactivate';

    /**
     * Produk, pelanggan, dan lokasi rak dipakai bersama-sama.
     *
     * Satu nama tindakan untuk ketiganya, bukan sembilan: yang membedakan
     * sudah tercatat di subject_type, dan penyaring yang isinya sembilan
     * baris hampir sama justru lebih susah dipakai.
     */
    public const MASTER_CREATE = 'master.create';

    public const MASTER_UPDATE = 'master.update';

    public const MASTER_DEACTIVATE = 'master.deactivate';

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
        self::ORDER_SUBMIT => 'Kirim Pesanan',
        self::ORDER_APPROVE => 'Setujui Pesanan',
        self::ORDER_REJECT => 'Tolak Pesanan',
        self::ORDER_CANCEL => 'Batalkan Pesanan',
        self::INBOUND_CREATE => 'Input Produksi',
        self::INBOUND_PUTAWAY => 'Naikkan ke Rak',
        self::INBOUND_VERIFY => 'Verifikasi Barang Masuk',
        self::DELIVERY_SHIP => 'Nyatakan Berangkat',
        self::DELIVERY_SUBSTITUTION => 'Konfirmasi Barang Beda SKU',
        self::EPOD_CONFIRM => 'Konfirmasi Sampai (Supir)',
        self::PROOF_UPLOAD => 'Unggah Bukti Surat Jalan',
        self::PROOF_VERIFY => 'Sahkan Bukti Surat Jalan',
        self::PROOF_REJECT => 'Tolak Bukti Surat Jalan',
        self::RETURN_REPORT => 'Lapor Penolakan Customer',
        self::TRANSFER_CREATE => 'Buat Transfer Gudang',
        self::TRANSFER_RECEIVE => 'Terima Transfer Gudang',
        self::USER_CREATE => 'Tambah Pengguna',
        self::USER_UPDATE => 'Ubah Pengguna',
        self::USER_DEACTIVATE => 'Aktifkan / Nonaktifkan Pengguna',
        self::MASTER_CREATE => 'Tambah Master Data',
        self::MASTER_UPDATE => 'Ubah Master Data',
        self::MASTER_DEACTIVATE => 'Nonaktifkan Master Data',
    ];

    /**
     * Umur simpan log — keputusan pemilik produk.
     *
     * Baris yang lebih tua dihapus otomatis oleh App\Console\Commands\
     * PurgeActivityLogs. Penghapusan MASSAL ini satu-satunya pengecualian
     * dari aturan append-only di booted(), dan sengaja tidak lewat model
     * supaya tidak ada jalan menghapus satu baris tertentu — yang mau
     * dicegah adalah orang menghilangkan jejak dirinya sendiri, bukan
     * pembersihan yang berjalan menurut umur.
     */
    public const UMUR_SIMPAN_HARI = 90;

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
