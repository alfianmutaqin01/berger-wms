<?php

namespace App\Models;

use App\Support\Settings;
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

    public const STOCKTAKE_FOUND = 'stocktake.found';

    public const STOCKTAKE_FINALIZE = 'stocktake.finalize';

    public const PICKING_RELEASE = 'picking.release';

    public const RETURN_APPROVE = 'return.approve';

    public const RETURN_REJECT = 'return.reject';

    public const RETURN_PUTAWAY = 'return.putaway';

    public const RETURN_VERIFY = 'return.verify';

    public const ORDER_RESHIP = 'order.reship';

    /* ---------------------------------------------- Alur pesanan (Fase 9) */

    public const ORDER_SUBMIT = 'order.submit';

    /**
     * Pesanan dibuat Admin/Manager ATAS NAMA seorang Sales.
     *
     * Jenis tersendiri, bukan digabung ke ORDER_SUBMIT. Yang perlu terbaca
     * bukan "pesanan dikirim" (terjadi ribuan kali) melainkan "dibuat atas
     * nama orang lain" (seharusnya jarang). Digabung, yang jarang tenggelam
     * di antara yang biasa dan penyaring log tidak bisa memisahkannya lagi.
     */
    public const ORDER_PLACED_INTERNAL = 'order.placed_internal';

    public const ORDER_APPROVE = 'order.approve';

    public const ORDER_REJECT = 'order.reject';

    public const ORDER_CANCEL = 'order.cancel';

    /* --------------------------------------------- Barang masuk (Fase 9) */

    public const INBOUND_CREATE = 'inbound.create';

    public const INBOUND_PUTAWAY = 'inbound.putaway';

    public const INBOUND_QTY_ADJUST = 'inbound.qty_adjust';

    public const INBOUND_VERIFY = 'inbound.verify';

    /* ----------------------------------------------- Pengiriman (Fase 9) */

    public const DELIVERY_SHIP = 'delivery.ship';

    public const DELIVERY_SUBSTITUTION = 'delivery.substitution';

    public const EPOD_CONFIRM = 'epod.confirm';

    public const PROOF_UPLOAD = 'proof.upload';

    public const PROOF_VERIFY = 'proof.verify';

    public const PROOF_REJECT = 'proof.reject';

    public const BILLING_PAY = 'billing.pay';

    public const BILLING_VOID = 'billing.void';

    /** Sales melapor; empat tindakan retur lainnya sudah ada di atas. */
    public const RETURN_REPORT = 'return.report';

    /* ------------------------------------------------- Transfer (Fase 9) */

    /*
     | MRF — permintaan material Produksi. Enam jenis, karena enam keputusan
     | berbeda yang masing-masing punya pelakunya sendiri. MRF_APPROVER_DECIDE
     | adalah satu-satunya selain EPOD_CONFIRM yang pelakunya BUKAN pengguna
     | sistem: atasan yang menyetujui lewat tautan WhatsApp tidak punya akun,
     | jadi yang tersisa sebagai jejak hanyalah nama, nomor, dan IP-nya.
     */
    public const MRF_CREATE = 'mrf.create';

    public const MRF_APPROVER_DECIDE = 'mrf.approver_decide';

    public const MRF_LOGISTICS_APPROVE = 'mrf.logistics_approve';

    public const MRF_LOGISTICS_REJECT = 'mrf.logistics_reject';

    public const MRF_RECEIVE = 'mrf.receive';

    public const MRF_CONSUME = 'mrf.consume';

    public const MRF_CANCEL = 'mrf.cancel';

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

    /** Pengaturan Sistem diubah (Fase 10). */
    public const SETTINGS_UPDATE = 'settings.update';

    /**
     * Laporan diunduh (Fase 11).
     *
     * Satu berkas Excel berisi data pelanggan beserta volume pembeliannya
     * bisa beredar selamanya setelah keluar sekali. Yang tercatat bukan
     * pembacaannya di layar (itu akan membanjiri log), melainkan momen
     * datanya MENINGGALKAN sistem.
     */
    public const REPORT_EXPORT = 'report.export';

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
        self::STOCKTAKE_FOUND => 'Temuan Stocktake',
        self::STOCKTAKE_FINALIZE => 'Sahkan Stocktake',
        self::PICKING_RELEASE => 'Lepas Tugas Picking',
        self::RETURN_APPROVE => 'Setujui Penolakan Customer',
        self::RETURN_REJECT => 'Tolak Laporan Penolakan',
        self::RETURN_PUTAWAY => 'Naikkan Barang Tolakan',
        self::RETURN_VERIFY => 'Verifikasi Barang Tolakan',
        self::ORDER_RESHIP => 'Kirim Ulang Outstanding',
        self::ORDER_SUBMIT => 'Kirim Pesanan',
        self::ORDER_PLACED_INTERNAL => 'Buat Pesanan Atas Nama Sales',
        self::ORDER_APPROVE => 'Setujui Pesanan',
        self::ORDER_REJECT => 'Tolak Pesanan',
        self::ORDER_CANCEL => 'Batalkan Pesanan',
        self::INBOUND_CREATE => 'Input Produksi',
        self::INBOUND_PUTAWAY => 'Naikkan ke Rak',
        self::INBOUND_QTY_ADJUST => 'Sesuaikan Qty Produksi',
        self::INBOUND_VERIFY => 'Verifikasi Barang Masuk',
        self::DELIVERY_SHIP => 'Nyatakan Berangkat',
        self::DELIVERY_SUBSTITUTION => 'Konfirmasi Barang Beda SKU',
        self::EPOD_CONFIRM => 'Konfirmasi Sampai (Supir)',
        self::PROOF_UPLOAD => 'Unggah Bukti Surat Jalan',
        self::PROOF_VERIFY => 'Sahkan Bukti Surat Jalan',
        self::PROOF_REJECT => 'Tolak Bukti Surat Jalan',
        self::BILLING_PAY => 'Konfirmasi Lunas',
        self::BILLING_VOID => 'Batalkan Konfirmasi Lunas',
        self::RETURN_REPORT => 'Lapor Penolakan Customer',
        self::MRF_CREATE => 'Buat Permintaan Material',
        self::MRF_APPROVER_DECIDE => 'Keputusan Atasan atas MRF (WhatsApp)',
        self::MRF_LOGISTICS_APPROVE => 'Setujui Permintaan Material',
        self::MRF_LOGISTICS_REJECT => 'Tolak Permintaan Material',
        self::MRF_RECEIVE => 'Terima Material di Produksi',
        self::MRF_CONSUME => 'Catat Pemakaian Material',
        self::MRF_CANCEL => 'Batalkan Permintaan Material',
        self::TRANSFER_CREATE => 'Buat Transfer Gudang',
        self::TRANSFER_RECEIVE => 'Terima Transfer Gudang',
        self::USER_CREATE => 'Tambah Pengguna',
        self::USER_UPDATE => 'Ubah Pengguna',
        self::USER_DEACTIVATE => 'Aktifkan / Nonaktifkan Pengguna',
        self::MASTER_CREATE => 'Tambah Master Data',
        self::MASTER_UPDATE => 'Ubah Master Data',
        self::MASTER_DEACTIVATE => 'Nonaktifkan Master Data',
        self::SETTINGS_UPDATE => 'Ubah Pengaturan Sistem',
        self::REPORT_EXPORT => 'Unduh Laporan',
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
    public static function umurSimpanHari(): int
    {
        return Settings::get(Settings::ACTIVITY_RETENTION_DAYS);
    }

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

    /**
     * Satu nilai `properties` dalam bentuk yang bisa dibaca di halaman log.
     *
     * NILAINYA BEBAS BENTUK, dan itu memang kontrak Activity::record(): kolom
     * yang berubah disimpan sebagai daftar, penyesuaian qty sebagai daftar
     * baris, penyaring laporan sebagai peta. Menampilkannya dengan asumsi
     * "selalu teks" pernah mematikan SELURUH halaman log begitu satu baris
     * saja berisi daftar — tepat di halaman yang dibuka saat ada yang perlu
     * dipertanggungjawabkan.
     */
    public static function tampilkanNilai(mixed $nilai): string
    {
        return match (true) {
            $nilai === null, $nilai === '', $nilai === [] => '—',
            is_bool($nilai) => $nilai ? 'ya' : 'tidak',
            is_scalar($nilai) => (string) $nilai,
            // Daftar sederhana ["max_qty_per_pallet", "updated_at"] dibaca
            // sebagai kalimat, bukan sebagai kode.
            is_array($nilai) && array_is_list($nilai) && collect($nilai)->every(fn ($v) => is_scalar($v) || $v === null) => implode(', ', array_map(fn ($v) => $v === null ? '—' : (is_bool($v) ? ($v ? 'ya' : 'tidak') : (string) $v), $nilai)),
            default => (string) json_encode($nilai, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
        };
    }
}
