<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu konfirmasi pelunasan oleh Logistik — bisa melunasi beberapa tagihan.
 *
 * Tanpa nominal: yang dicatat hanya kapan, dengan cara apa, dan nomor
 * rujukannya. Uangnya sendiri tidak pernah melewati sistem ini.
 */
class BillingPayment extends Model
{
    public const METHOD_TRANSFER = 'transfer';

    public const METHOD_GIRO = 'giro';

    public const METHOD_TUNAI = 'tunai';

    public const METHOD_LABELS = [
        self::METHOD_TRANSFER => 'Transfer',
        self::METHOD_GIRO => 'Giro',
        self::METHOD_TUNAI => 'Tunai',
    ];

    protected $fillable = [
        'customer_id', 'paid_on', 'method', 'reference', 'notes', 'confirmed_by',
        'voided_at', 'voided_by', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function billings(): HasMany
    {
        return $this->hasMany(CustomerBilling::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function scopeBerlaku(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function getMethodLabelAttribute(): string
    {
        return self::METHOD_LABELS[$this->method] ?? $this->method;
    }
}
