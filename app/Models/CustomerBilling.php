<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu tagihan = satu invoice BC dari pesanan tempo yang sudah sampai.
 *
 * Menempel ke pesanan INDUK invoice; pesanan yang digabung ke nomor SO yang
 * sama (so_merged_into_id) ikut di dalamnya. Lihat migrasi
 * create_billing_tables untuk alasan tiap penyimpangan dari docs/2 §3.6.
 *
 * STATUS TIDAK DISIMPAN. Lunas = billing_payment_id terisi; lewat jatuh
 * tempo = belum lunas dan due_date sebelum hari ini. "Hari ini" SELALU
 * tanggal kalender Asia/Jakarta — lihat hariIni().
 */
class CustomerBilling extends Model
{
    /** Tagihan yang jatuh tempo dalam sekian hari masuk tab "segera" dan diingatkan. */
    public const HARI_SEGERA = 7;

    /** Manager diingatkan sekian hari sebelum jatuh tempo. */
    public const HARI_PENGINGAT = 3;

    protected $fillable = [
        'sales_order_id', 'customer_id', 'warehouse_id', 'payment_term_id',
        'term_days', 'delivered_on', 'due_date', 'billing_payment_id',
        'reminded_due_soon_at', 'reminded_overdue_at',
    ];

    protected function casts(): array
    {
        return [
            'term_days' => 'integer',
            'delivered_on' => 'date',
            'due_date' => 'date',
            'reminded_due_soon_at' => 'datetime',
            'reminded_overdue_at' => 'datetime',
        ];
    }

    /**
     * Tanggal kalender hari ini di gudang.
     *
     * Server menyimpan UTC. Tanpa ini, antara pukul 00:00 dan 07:00 WIB
     * sistem masih menganggap hari kemarin, dan tagihan yang jatuh tempo hari
     * ini belum terbaca lewat sampai siang.
     */
    public static function hariIni(): CarbonImmutable
    {
        return CarbonImmutable::now(config('wms.timezone', 'Asia/Jakarta'))->startOfDay();
    }

    /* ------------------------------------------------------------ Relasi */

    /** Pesanan induk invoice. */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /** Pesanan yang menumpang di invoice yang sama (anak gabungan). */
    public function mergedOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'so_merged_into_id', 'sales_order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(BillingPayment::class, 'billing_payment_id');
    }

    /* ------------------------------------------------------------ Scope */

    public function scopeBelumLunas(Builder $query): Builder
    {
        return $query->whereNull('billing_payment_id');
    }

    public function scopeLunas(Builder $query): Builder
    {
        return $query->whereNotNull('billing_payment_id');
    }

    public function scopeLewatJatuhTempo(Builder $query): Builder
    {
        return $query->belumLunas()->whereDate('due_date', '<', self::hariIni()->toDateString());
    }

    /** Belum lunas, belum lewat, tetapi jatuh tempo dalam $hari ke depan (hari ini termasuk). */
    public function scopeJatuhTempoDalam(Builder $query, int $hari): Builder
    {
        $hariIni = self::hariIni();

        return $query->belumLunas()
            ->whereDate('due_date', '>=', $hariIni->toDateString())
            ->whereDate('due_date', '<=', $hariIni->addDays($hari)->toDateString());
    }

    /* --------------------------------------------------------- Keadaan */

    public function sudahLunas(): bool
    {
        return $this->billing_payment_id !== null;
    }

    /** Berapa hari lewat jatuh tempo; 0 bila belum lewat atau sudah lunas. */
    public function hariLewat(): int
    {
        if ($this->sudahLunas()) {
            return 0;
        }

        $jatuhTempo = CarbonImmutable::parse($this->due_date->toDateString(), config('wms.timezone', 'Asia/Jakarta'));

        return max(0, (int) $jatuhTempo->diffInDays(self::hariIni(), false));
    }

    /** Sisa hari menuju jatuh tempo; negatif bila sudah lewat. */
    public function sisaHari(): int
    {
        $jatuhTempo = CarbonImmutable::parse($this->due_date->toDateString(), config('wms.timezone', 'Asia/Jakarta'));

        return (int) self::hariIni()->diffInDays($jatuhTempo, false);
    }

    /**
     * Penanda piutang per customer, untuk banyak customer sekaligus.
     *
     * DUA TINGKAT, bukan satu. PRD awal menandai "Menunggak" untuk setiap
     * tagihan yang belum lunas — termasuk yang jatuh temponya masih sebulan
     * lagi — sehingga hampir semua customer tempo selalu bertanda merah, dan
     * penanda yang selalu menyala berhenti dibaca. Merah hanya untuk yang
     * benar-benar lewat jatuh tempo.
     *
     * Satu query untuk seluruh daftar: halaman Master Customer menampilkan
     * 15 baris, dan 15 query terpisah hanya untuk sebuah badge tidak sepadan.
     *
     * @param  array<int, int>  $customerIds
     * @return array<int, array{berjalan:int, menunggak:int, lewat_terlama:int, jatuh_tempo_terlama:?string}>
     */
    public static function penandaCustomer(array $customerIds): array
    {
        $customerIds = array_values(array_unique(array_filter($customerIds)));

        if ($customerIds === []) {
            return [];
        }

        $hariIni = self::hariIni()->toDateString();

        return self::query()
            ->belumLunas()
            ->whereIn('customer_id', $customerIds)
            ->selectRaw('customer_id')
            ->selectRaw('COUNT(*) AS berjalan')
            ->selectRaw('COUNT(*) FILTER (WHERE due_date < ?) AS menunggak', [$hariIni])
            ->selectRaw('MIN(due_date) AS jatuh_tempo_terlama')
            ->groupBy('customer_id')
            ->get()
            ->mapWithKeys(function ($baris) use ($hariIni) {
                $terlama = $baris->jatuh_tempo_terlama ? substr((string) $baris->jatuh_tempo_terlama, 0, 10) : null;

                return [(int) $baris->customer_id => [
                    'berjalan' => (int) $baris->berjalan,
                    'menunggak' => (int) $baris->menunggak,
                    'jatuh_tempo_terlama' => $terlama,
                    'lewat_terlama' => $terlama !== null && $terlama < $hariIni
                        ? (int) CarbonImmutable::parse($terlama)->diffInDays(CarbonImmutable::parse($hariIni))
                        : 0,
                ]];
            })
            ->all();
    }
}
