<?php

namespace App\Models;

use App\Support\Messaging\PesanWhatsApp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Surat Jalan — CERMINAN dokumen milik sistem BC, bukan dokumen kami.
 *
 * Nomornya (`document_no`) disalin dari BC dan tidak pernah dibangkitkan di
 * sini. Qty pada barisnya adalah qty yang BERLAKU: bila berbeda dengan hasil
 * picking, yang menang adalah dokumen ini (keputusan pemilik produk).
 */
class DeliveryNote extends Model
{
    use HasFactory;

    /** Sudah disalin dari BC; barangnya belum dinyatakan berangkat. */
    public const STATUS_IMPORTED = 'imported';

    /** Barang dinyatakan berangkat lewat sistem ini. */
    public const STATUS_SHIPPED = 'shipped';

    /** Supir sudah mengonfirmasi barang sampai. */
    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_LABELS = [
        self::STATUS_IMPORTED => 'Menunggu Berangkat',
        self::STATUS_SHIPPED => 'Dalam Pengiriman',
        self::STATUS_DELIVERED => 'Sampai Tujuan',
    ];

    /* -------------------------------------------------- Status pengiriman pesan */

    /** Belum dicoba dikirim. */
    public const NOTIFY_PENDING = 'pending';

    /** Disiapkan untuk dikirim Logistik sendiri lewat WhatsApp-nya. */
    public const NOTIFY_MANUAL = 'manual';

    public const NOTIFY_SENT = 'sent';

    public const NOTIFY_FAILED = 'failed';

    public const NOTIFY_LABELS = [
        self::NOTIFY_PENDING => 'Belum dikirim',
        self::NOTIFY_MANUAL => 'Perlu dikirim manual',
        self::NOTIFY_SENT => 'Terkirim',
        self::NOTIFY_FAILED => 'Gagal terkirim',
    ];

    protected $fillable = [
        'document_no', 'bc_so_number', 'sales_order_id',
        'customer_code', 'customer_id', 'warehouse_id',
        'bc_location_code', 'shipment_date', 'status',
        'imported_at', 'imported_by',
        'driver_name', 'driver_phone', 'vehicle_plate',
        'shipped_at', 'shipped_by', 'epod_token',
        'delivered_at', 'received_by_name',
        'arrival_photo_path', 'arrival_photo_mime', 'arrival_photo_size',
        'arrival_photo_source', 'arrival_photo_taken_at',
        'notify_status', 'notify_attempts', 'notified_at', 'notify_error',
        'sales_notify_status', 'sales_notify_attempts', 'sales_notified_at',
        'sales_notify_error', 'sales_notify_phone',
        'substitution_confirmed_at', 'substitution_confirmed_by', 'substitution_reason',
    ];

    protected function casts(): array
    {
        return [
            'shipment_date' => 'date',
            'imported_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'arrival_photo_taken_at' => 'datetime',
            'arrival_photo_size' => 'integer',
            'notified_at' => 'datetime',
            'substitution_confirmed_at' => 'datetime',
            'notify_attempts' => 'integer',
            'sales_notified_at' => 'datetime',
            'sales_notify_attempts' => 'integer',
        ];
    }

    /* ------------------------------------------------------------ Relasi */

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryNoteLine::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function shippedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by');
    }

    /** Siapa yang menyatakan barang beda SKU memang naik kendaraan. */
    public function substitutionConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'substitution_confirmed_by');
    }

    /* ------------------------------------------------------------- Scope */

    /**
     * Surat Jalan yang belum menemukan pesanannya di sistem ini.
     *
     * Bukan kesalahan: ekspor harian BC memuat seluruh SJ perusahaan,
     * termasuk pesanan yang tidak pernah lewat portal ini. Tetapi ia harus
     * TERLIHAT — SJ yang tidak berpasangan padahal seharusnya berpasangan
     * berarti nomor SO-nya berbeda antara BC dan yang diketik Logistik, dan
     * itu justru yang perlu segera dibetulkan.
     */
    public function scopeBelumBerpasangan(Builder $query): Builder
    {
        return $query->whereNull('sales_order_id');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $pola = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($pola): void {
            $q->where('document_no', 'ILIKE', $pola)
                ->orWhere('bc_so_number', 'ILIKE', $pola)
                ->orWhere('customer_code', 'ILIKE', $pola);
        });
    }

    /* ------------------------------------------------------------ Aturan */

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_IMPORTED => 'warning',
            self::STATUS_SHIPPED => 'primary',
            self::STATUS_DELIVERED => 'success',
            default => 'secondary',
        };
    }

    /** Total unit yang tertulis di dokumen ini. */
    public function getTotalQtyAttribute(): int
    {
        return (int) ($this->relationLoaded('lines')
            ? $this->lines->sum('qty')
            : $this->lines()->sum('qty'));
    }

    public function getNotifyLabelAttribute(): string
    {
        return self::NOTIFY_LABELS[$this->notify_status] ?? $this->notify_status;
    }

    /** Alamat penuh tautan konfirmasi untuk supir. */
    public function epodUrl(): ?string
    {
        return $this->epod_token === null ? null : url('/epod/'.$this->epod_token);
    }

    /**
     * Pesan WhatsApp untuk supir.
     *
     * Dibangun di SATU tempat supaya isi pesan sama persis, entah dikirim
     * otomatis lewat penyedia atau dikirim Logistik sendiri lewat tautan
     * wa.me. Dua penyusun pesan untuk satu pesan cepat atau lambat berbeda,
     * dan yang menerima perbedaannya adalah orang di luar organisasi.
     */
    public function pesanUntukSupir(): string
    {
        return implode("\n", array_filter([
            'Halo'.($this->driver_name ? ' '.$this->driver_name : '').',',
            '',
            'Pengiriman Berger Paints:',
            'Surat Jalan: '.$this->document_no,
            'Tujuan: '.($this->customer?->name ?? '—'),
            $this->vehicle_plate ? 'Kendaraan: '.$this->vehicle_plate : null,
            '',
            'Setelah barang sampai, mohon tekan tautan ini lalu tekan tombol konfirmasi:',
            $this->epodUrl(),
            '',
            'Terima kasih.',
        ], fn ($baris) => $baris !== null));
    }

    /** Pesan supir dalam bentuk yang diterima seluruh penyedia WhatsApp. */
    public function pesanWhatsAppSupir(): PesanWhatsApp
    {
        return new PesanWhatsApp(
            teks: $this->pesanUntukSupir(),
            template: PesanWhatsApp::TEMPLATE_KONFIRMASI_SUPIR,
            variabel: [(string) $this->epodUrl()],
        );
    }

    /**
     * Kabar barang sampai untuk Sales pemilik pesanan.
     *
     * KENAPA LEWAT WHATSAPP, BUKAN CUKUP LONCENG. Lonceng web hanya terbaca
     * kalau Sales kebetulan membuka sistemnya — dan Sales bekerja di jalan,
     * dari toko ke toko, dengan HP di saku. Justru di saat barang sampai
     * itulah ia harus bergerak: foto Surat Jalan bertanda tangan harus
     * diambil selagi dokumennya masih di tangan pelanggan. Kabar yang baru
     * terbaca besok sore berarti Surat Jalan yang sudah terselip entah di
     * mana.
     *
     * Tautannya menuju halaman pesanan di Portal Sales, tempat tombol unggah
     * bukti berada — bukan halaman publik. Sales punya akun; yang ia butuhkan
     * adalah jalan terpendek ke pekerjaan berikutnya.
     */
    public function pesanUntukSales(): string
    {
        $order = $this->salesOrder;

        return implode("\n", array_filter([
            'Halo '.($order?->user?->full_name ?? 'Sales').',',
            '',
            'Pesanan Anda sudah SAMPAI di tujuan:',
            'Pesanan: '.($order?->bc_so_number ?: ($order?->order_number ?? '—')),
            'Customer: '.($this->customer?->name ?? '—'),
            'Surat Jalan: '.$this->document_no,
            'Sampai: '.($this->delivered_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?? '—'),
            filled($this->received_by_name) ? 'Diterima oleh: '.$this->received_by_name : null,
            '',
            'Langkah berikutnya: unggah foto Surat Jalan bertanda tangan agar pesanan bisa ditutup:',
            $this->urlPesananSales(),
            '',
            'Pesan otomatis dari Berger WMS.',
        ], fn ($baris) => $baris !== null));
    }

    /**
     * Urutan variabel adalah kontrak dengan template barang_sampai_sales di
     * Meta — lihat PesanWhatsApp::TEMPLATE_BARANG_SAMPAI.
     */
    public function pesanWhatsAppSales(): PesanWhatsApp
    {
        $order = $this->salesOrder;

        return new PesanWhatsApp(
            teks: $this->pesanUntukSales(),
            template: PesanWhatsApp::TEMPLATE_BARANG_SAMPAI,
            variabel: [
                (string) ($order?->user?->full_name ?? 'Sales'),
                (string) ($order?->bc_so_number ?: ($order?->order_number ?? '—')),
                (string) ($this->customer?->name ?? '—'),
                (string) $this->document_no,
                $this->urlPesananSales(),
            ],
        );
    }

    private function urlPesananSales(): string
    {
        return url('/sales/orders/'.$this->sales_order_id);
    }

    public function getSalesNotifyLabelAttribute(): ?string
    {
        return $this->sales_notify_status === null
            ? null
            : (self::NOTIFY_LABELS[$this->sales_notify_status] ?? $this->sales_notify_status);
    }
}
