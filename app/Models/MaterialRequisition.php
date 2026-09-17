<?php

namespace App\Models;

use App\Support\Messaging\PesanWhatsApp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MRF — satu permintaan material dari Produksi ke Logistik.
 *
 * DELAPAN KEADAAN, dan tiap perpindahannya dikerjakan orang yang berbeda.
 * Itu sebabnya keadaannya sebanyak ini: menggabungkan dua langkah yang
 * dikerjakan dua orang berarti salah satunya tidak punya tempat untuk
 * mengatakan "sudah saya kerjakan".
 *
 *   pending_approval    Produksi menyimpan; tautan WA menunggu ditekan
 *   rejected_approval   approver menolak — berhenti di sini
 *   pending_logistics   approver setuju; Logistik belum melihat
 *   rejected_logistics  Logistik menolak, dengan alasan
 *   pending_picking     Logistik memilih batch; stok DICADANGKAN, operator
 *                       mendapat tugasnya
 *   ready_for_pickup    operator selesai; barang menunggu pemohon di titik
 *                       serah terima — hanya untuk permintaan lewat tautan
 *                       divisi, yang pemohonnya tidak berdiri di gudang
 *   received            barangnya berpindah tangan dan masuk buku pemakaian
 *   cancelled           dibatalkan sebelum barangnya turun dari rak
 *
 * KENAPA DUA LAPIS PERSETUJUAN. Approver WA menjawab "bolehkah Produksi
 * meminta ini" — pertanyaan atasan Produksi. Logistik menjawab "ada tidak
 * barangnya, dan batch mana" — pertanyaan gudang. Keduanya pertanyaan yang
 * berbeda, dan yang satu tidak bisa menjawab yang lain.
 */
class MaterialRequisition extends Model
{
    use HasFactory;

    /**
     * Peran yang hanya melihat permintaan divisinya sendiri.
     *
     * Keduanya divisi PEMINTA: mereka membuka daftar ini untuk mengejar
     * permintaannya, bukan untuk mengurus permintaan orang lain.
     */
    public const PERAN_SEDIVISI = [Role::PRODUCTION, Role::SALES];

    /* ------------------------------------------------------------ Status */

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_REJECTED_APPROVAL = 'rejected_approval';

    public const STATUS_PENDING_LOGISTICS = 'pending_logistics';

    public const STATUS_REJECTED_LOGISTICS = 'rejected_logistics';

    public const STATUS_PENDING_PICKING = 'pending_picking';

    public const STATUS_READY_FOR_PICKUP = 'ready_for_pickup';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::STATUS_PENDING_APPROVAL => 'Menunggu Persetujuan Atasan',
        self::STATUS_REJECTED_APPROVAL => 'Ditolak Atasan',
        self::STATUS_PENDING_LOGISTICS => 'Menunggu Logistik',
        self::STATUS_REJECTED_LOGISTICS => 'Ditolak Logistik',
        self::STATUS_PENDING_PICKING => 'Menunggu Picking',
        self::STATUS_READY_FOR_PICKUP => 'Siap Diambil Pemohon',
        self::STATUS_RECEIVED => 'Sudah Diterima',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    public const STATUS_BADGES = [
        self::STATUS_PENDING_APPROVAL => 'bg-secondary',
        self::STATUS_REJECTED_APPROVAL => 'bg-danger',
        self::STATUS_PENDING_LOGISTICS => 'bg-warning text-dark',
        self::STATUS_REJECTED_LOGISTICS => 'bg-danger',
        self::STATUS_PENDING_PICKING => 'bg-info text-dark',
        self::STATUS_READY_FOR_PICKUP => 'bg-primary',
        self::STATUS_RECEIVED => 'bg-success',
        self::STATUS_CANCELLED => 'bg-secondary',
    ];

    /* ------------------------------------------------------ Jenis permintaan */

    public const TYPE_REPROSES = 'reproses_tinting';

    public const TYPE_TESTING = 'testing_investigation';

    public const TYPE_REPLACEMENT = 'replacement';

    public const TYPE_SAMPLE = 'sample_material';

    /**
     * Empat jenis yang diakui pemilik produk, beserta keterangannya.
     *
     * Keterangan ikut di sini, bukan di Blade: ia dibaca di formulir Produksi
     * DAN di layar persetujuan Logistik, dan dua salinan kalimat yang sama
     * suatu hari akan berbeda — persis pada hari orang memakainya untuk
     * memutuskan.
     *
     * @var array<string, array{label:string, bantuan:string}>
     */
    public const TYPES = [
        self::TYPE_REPROSES => [
            'label' => 'Reproses / Tinting',
            'bantuan' => 'Barang jadi atau DDP yang akan diolah ulang menjadi produk layak jual.',
        ],
        self::TYPE_TESTING => [
            'label' => 'Testing / Investigasi',
            'bantuan' => 'Pemeriksaan mutu atau penelusuran keluhan; barangnya lazimnya tidak kembali utuh.',
        ],
        self::TYPE_REPLACEMENT => [
            'label' => 'Replacement',
            'bantuan' => 'Pengganti barang yang rusak atau kurang pada pengiriman sebelumnya.',
        ],
        self::TYPE_SAMPLE => [
            'label' => 'Sample Material',
            'bantuan' => 'Contoh untuk pelanggan, pameran, atau pengembangan warna baru.',
        ],
    ];

    /* ------------------------------------------------------- Status pesan WA */

    public const NOTIFY_PENDING = 'pending';

    public const NOTIFY_SENT = 'sent';

    public const NOTIFY_MANUAL = 'manual';

    public const NOTIFY_FAILED = 'failed';

    protected $fillable = [
        'mrf_number', 'warehouse_id',
        'requested_by', 'request_link_id', 'requester_name', 'requester_phone',
        'department_id', 'department_name', 'collected_by_name',
        'request_type', 'purpose', 'status',
        'approver_name', 'approver_phone', 'approval_token',
        'approved_at', 'approval_note',
        'approver_rejected_at', 'approver_rejection_reason',
        'notify_status', 'notify_error', 'notify_attempts', 'notified_at',
        'logistics_approved_at', 'logistics_approved_by',
        'logistics_rejected_at', 'logistics_rejected_by', 'logistics_rejection_reason',
        'picking_list_id', 'handover_location_id', 'handover_note', 'picked_at',
        'received_at', 'received_by',
        'cancelled_at', 'cancelled_by', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'approver_rejected_at' => 'datetime',
            'notified_at' => 'datetime',
            'notify_attempts' => 'integer',
            'logistics_approved_at' => 'datetime',
            'logistics_rejected_at' => 'datetime',
            'picked_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------ Relasi */

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** Terisi bila permintaan ini datang lewat tautan divisi, bukan akun. */
    public function requestLink(): BelongsTo
    {
        return $this->belongsTo(MrfRequestLink::class, 'request_link_id');
    }

    /**
     * Nama orang yang meminta, dari jalur mana pun ia datang.
     *
     * Permintaan lewat tautan tidak punya akun sama sekali — namanya diketik
     * di formulir. Dokumen ini harus tetap menjawab "siapa yang meminta"
     * dengan satu cara, entah pemohonnya punya akun atau tidak.
     */
    public function getNamaPemohonAttribute(): string
    {
        return $this->requestedBy?->full_name ?? $this->requester_name ?? '—';
    }

    /**
     * Divisi yang meminta, untuk ditulis di layar.
     *
     * Dipakai di layar operator, yang dulu selalu menulis "Produksi" karena
     * memang hanya Produksi yang meminta. Sekarang Sales, QC dan R&D ikut
     * meminta, dan operator yang membaca "Produksi" di atas permintaan Sales
     * akan menaruh barangnya di tempat yang salah.
     */
    public function getNamaDivisiAttribute(): string
    {
        return $this->department_name ?: ($this->department?->name ?? 'Divisi peminta');
    }

    /**
     * Permintaan dari divisi tanpa akun.
     *
     * Barangnya SELESAI SAAT DIAMBIL, tidak masuk buku pemakaian bertahap:
     * divisi lain lazimnya minta satu-dua pcs yang langsung habis, dan baris
     * sekecil itu di daftar sisa berjalan hanya menenggelamkan sisa Produksi
     * yang benar-benar perlu dikejar.
     */
    public function lewatTautan(): bool
    {
        return $this->request_link_id !== null;
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Baris permintaan Produksi: SKU + qty. */
    public function items(): HasMany
    {
        return $this->hasMany(MaterialRequisitionItem::class);
    }

    /** Batch sungguhan yang dipilih Logistik. */
    public function allocations(): HasMany
    {
        return $this->hasMany(MaterialRequisitionAllocation::class);
    }

    public function holdings(): HasMany
    {
        return $this->hasMany(ProductionMaterialHolding::class);
    }

    /**
     * Seluruh penolakan yang pernah dialami permintaan ini.
     *
     * Kosong berarti belum pernah ditolak. Terisi berarti pernah — SEKALIPUN
     * permintaannya sekarang sudah disetujui dan selesai.
     */
    public function rejections(): HasMany
    {
        return $this->hasMany(MaterialRequisitionRejection::class)->orderBy('rejected_at');
    }

    public function pickingList(): BelongsTo
    {
        return $this->belongsTo(PickingList::class);
    }

    public function handoverLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'handover_location_id');
    }

    public function logisticsApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logistics_approved_by');
    }

    public function logisticsRejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logistics_rejected_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /* ------------------------------------------------------------- Scope */

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $pola = '%'.str_replace('%', '\%', trim($term)).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('mrf_number', 'ILIKE', $pola)
            ->orWhere('purpose', 'ILIKE', $pola)
            ->orWhere('approver_name', 'ILIKE', $pola));
    }

    /*
     | DIVISI PEMINTA HANYA MELIHAT PERMINTAAN DIVISINYA SENDIRI.
     |
     | Satu daftar yang memuat semua divisi tidak menolong siapa pun yang
     | meminta: Produksi tidak berkepentingan pada sampel Sales, dan Sales
     | tidak berkepentingan pada bahan baku sepalet. Yang tercampur begitu
     | bukan sekadar panjang — ia membuat orang membaca nomor yang bukan
     | miliknya lalu ragu apakah itu salah satu permintaannya.
     |
     | Dibatasi per DEPARTEMEN, bukan per orang: di dalam satu divisi
     | pekerjaannya memang dioper — yang meminta pagi ini bukan yang mengambil
     | sore nanti — dan membatasi tiap orang ke permintaannya sendiri justru
     | memutus operan itu.
     |
     | Logistik, Manager, Operator dan Super Admin tidak dibatasi: pekerjaan
     | mereka justru MELINTASI divisi.
     |
     | Akun tanpa departemen jatuh ke permintaannya sendiri, bukan ke semua —
     | data yang belum lengkap tidak boleh membuka pintu yang lebih lebar.
     */
    public function scopeUntukPembaca(Builder $query, ?User $pembaca): Builder
    {
        if ($pembaca === null || ! in_array($pembaca->role?->slug, self::PERAN_SEDIVISI, true)) {
            return $query;
        }

        return $pembaca->department_id !== null
            ? $query->where('department_id', $pembaca->department_id)
            : $query->where('requested_by', $pembaca->id);
    }

    /** Yang masih menunggu seseorang berbuat sesuatu. */
    public function scopeBerjalan(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_PENDING_LOGISTICS,
            self::STATUS_PENDING_PICKING,
            self::STATUS_READY_FOR_PICKUP,
        ]);
    }

    /* ------------------------------------------------------------ Aturan */

    /**
     * Sedang ditolak dan menunggu diperbaiki Produksi.
     *
     * Dua pintu penolakan, satu keadaan bagi pemohonnya: ditolak atasan
     * ("belum boleh meminta ini") dan ditolak Logistik ("barangnya tidak ada,
     * atau permintaannya keliru"). Keduanya sama-sama mengembalikan berkas ke
     * meja Produksi.
     */
    public function sedangDitolak(): bool
    {
        return $this->status === self::STATUS_REJECTED_APPROVAL
            || $this->status === self::STATUS_REJECTED_LOGISTICS;
    }

    /**
     * Boleh diperbaiki isinya lalu diajukan lagi, NOMORNYA TETAP.
     *
     * Sebelumnya penolakan adalah jalan buntu: permintaan yang ditolak karena
     * satu baris keliru memaksa Produksi mengetik ulang seluruhnya sebagai
     * permintaan baru — dan permintaan barunya tidak punya hubungan apa pun
     * dengan yang ditolak, sehingga atasan dan Logistik tidak pernah tahu ini
     * pengajuan kedua atas hal yang sama. Aturan yang sama sudah berlaku untuk
     * pesanan Sales yang ditolak.
     *
     * NOMOR MRF-NYA DIPAKAI ULANG, bukan diganti. Nomor itu sudah beredar di
     * WhatsApp atasan dan di catatan Logistik; memberi nomor baru pada berkas
     * yang sama membuat satu urusan punya dua nama.
     */
    public function bolehDiperbaiki(): bool
    {
        return $this->sedangDitolak();
    }

    /**
     * Tautan persetujuan untuk approver.
     *
     * Alamat publik, sama pola dengan tautan konfirmasi supir: approver tidak
     * punya akun WMS dan tidak akan dibuatkan — ia atasan di lantai produksi
     * yang dimintai persetujuan lewat HP, bukan pengguna sistem.
     */
    public function approvalUrl(): ?string
    {
        return $this->approval_token === null ? null : url('/mrf/'.$this->approval_token);
    }

    /**
     * Pesan WhatsApp untuk approver.
     *
     * Disusun di SATU tempat, alasannya sama dengan pesan untuk supir: isinya
     * harus sama persis entah dikirim penyedia otomatis atau ditekan sendiri
     * oleh Produksi lewat tautan wa.me.
     */
    public function pesanUntukApprover(): string
    {
        $barisProduk = $this->items
            ->map(fn (MaterialRequisitionItem $item) => sprintf(
                '- %s x%d',
                $item->product?->sku ?? 'produk',
                $item->qty_requested,
            ))
            ->all();

        return implode("\n", array_filter(array_merge([
            'Halo '.$this->approver_name.',',
            '',
            'Permintaan material dari Produksi Berger Paints menunggu persetujuan Anda:',
            'Nomor: '.$this->mrf_number,
            'Pemohon: '.$this->nama_pemohon
                .($this->department_name ? ' ('.$this->department_name.')' : ''),
            'Gudang: '.($this->warehouse?->name ?? '—'),
            'Jenis: '.$this->jenis_label,
            'Keperluan: '.$this->purpose,
            '',
            'Barang yang diminta:',
        ], $barisProduk, [
            '',
            'Tekan tautan ini untuk menyetujui atau menolak:',
            $this->approvalUrl(),
            '',
            'Terima kasih.',
        ]), fn ($baris) => $baris !== null));
    }

    /**
     * Kabar untuk pemohon lewat tautan: barangnya sudah bisa diambil.
     *
     * Dikirim lewat WhatsApp, bukan lonceng di dalam WMS — pemohonnya tidak
     * punya akun dan tidak akan pernah melihat lonceng itu. Tempat
     * pengambilannya disebut dengan nama yang bisa didatangi orang luar
     * gudang, bukan kode rak yang hanya dipahami operator.
     */
    public function pesanSiapDiambil(): PesanWhatsApp
    {
        $tempat = $this->handoverLocation?->nama_serah_terima ?? 'gudang';

        $teks = implode("\n", array_filter([
            'Halo '.$this->nama_pemohon.',',
            '',
            'Permintaan material '.$this->mrf_number.' sudah disiapkan dan BISA DIAMBIL.',
            'Tempat: '.$tempat,
            'Gudang: '.($this->warehouse?->name ?? '—'),
            $this->handover_note ? 'Catatan gudang: '.$this->handover_note : null,
            '',
            'Bawa serta nomor MRF di atas saat mengambil. Petugas gudang akan mencatat nama pengambilnya.',
            '',
            'Terima kasih.',
        ], fn ($baris) => $baris !== null));

        return new PesanWhatsApp(
            teks: $teks,
            template: PesanWhatsApp::TEMPLATE_MRF_SIAP_DIAMBIL,
            variabel: [
                (string) $this->nama_pemohon,
                (string) $this->mrf_number,
                (string) $tempat,
            ],
        );
    }

    /**
     * Pesan atasan dalam bentuk yang diterima seluruh penyedia WhatsApp.
     *
     * Urutan variabel adalah kontrak dengan template persetujuan_mrf di Meta —
     * lihat PesanWhatsApp::TEMPLATE_PERSETUJUAN_MRF.
     */
    public function pesanWhatsAppApprover(): PesanWhatsApp
    {
        return new PesanWhatsApp(
            teks: $this->pesanUntukApprover(),
            template: PesanWhatsApp::TEMPLATE_PERSETUJUAN_MRF,
            variabel: [
                (string) $this->approver_name,
                (string) $this->mrf_number,
                (string) $this->nama_pemohon,
                (string) $this->approvalUrl(),
            ],
        );
    }

    /** Sudah disetujui atasan, menunggu Logistik memilih batch. */
    public function menungguLogistik(): bool
    {
        return $this->status === self::STATUS_PENDING_LOGISTICS;
    }

    /**
     * Masih boleh dibatalkan Produksi?
     *
     * Batasnya jelas: selama barangnya BELUM turun dari rak. Sesudah operator
     * menekan Siap Loading, membatalkan hanya menghapus catatannya — barangnya
     * tetap berdiri di rak serah terima dan tidak ada lagi yang menjelaskan
     * kenapa ia di sana. Persis keadaan yang membuat fitur ini dibuat.
     */
    public function bolehDibatalkan(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_PENDING_LOGISTICS,
            self::STATUS_PENDING_PICKING,
        ], true);
    }

    public function sudahSelesai(): bool
    {
        return in_array($this->status, [
            self::STATUS_RECEIVED,
            self::STATUS_CANCELLED,
            self::STATUS_REJECTED_APPROVAL,
            self::STATUS_REJECTED_LOGISTICS,
        ], true);
    }

    /* ---------------------------------------------------------- Accessor */

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getStatusBadgeAttribute(): string
    {
        return self::STATUS_BADGES[$this->status] ?? 'bg-secondary';
    }

    public function getJenisLabelAttribute(): string
    {
        return self::TYPES[$this->request_type]['label'] ?? $this->request_type;
    }

    /** Total unit yang DIMINTA Produksi. */
    public function getTotalDimintaAttribute(): int
    {
        return (int) ($this->relationLoaded('items')
            ? $this->items->sum('qty_requested')
            : $this->items()->sum('qty_requested'));
    }

    /** Total unit yang benar-benar DITERIMA Produksi; nol selama belum. */
    public function getTotalDiterimaAttribute(): int
    {
        return (int) ($this->relationLoaded('holdings')
            ? $this->holdings->sum('qty_received')
            : $this->holdings()->sum('qty_received'));
    }
}
