<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Syarat pembayaran + limit kreditnya.
 *
 * Dipilih Sales saat membuat pesanan (bukan melekat pada pelanggan), sehingga
 * satu pelanggan bisa memakai termin berbeda antar pesanan.
 */
class PaymentTerm extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'days',
        'credit_limit',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'credit_limit' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    /** Dibayar di muka (tidak ada tenggang waktu). */
    public function isImmediate(): bool
    {
        return $this->days === 0;
    }
}
