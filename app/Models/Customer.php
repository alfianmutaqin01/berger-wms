<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Master pelanggan.
 *
 * Sejak PRD v1.1 pelanggan dibuat langsung oleh Manager/Super Admin dan
 * langsung aktif — tidak ada alur pengajuan/persetujuan dari Sales.
 *
 * Status "menunggak" TIDAK disimpan sebagai kolom; nanti dihitung dari
 * `customer_billings` yang belum lunas (Fase 8) agar tidak pernah basi.
 */
class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'ship_to_code',
        'name',
        'phone',
        'contact_name',
        'email',
        'address',
        'address_2',
        'territory_code',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scope & accessor
    |--------------------------------------------------------------------------
    */

    /** Hanya pelanggan aktif yang boleh muncul di form Buat Pesanan (PRD §5.2). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'ILIKE', $like)
                ->orWhere('code', 'ILIKE', $like)
                ->orWhere('ship_to_code', 'ILIKE', $like)
                ->orWhere('email', 'ILIKE', $like)
                ->orWhere('phone', 'ILIKE', $like);
        });
    }

    /**
     * Alamat jalan digabung dengan kelurahan/kota untuk ditampilkan.
     *
     * Sumbernya tetap dua kolom terpisah (mengikuti Address & Address 2 pada
     * ekspor ERP) supaya impor/ekspor tetap setara; penggabungan hanya terjadi
     * saat menampilkan.
     */
    public function getFullAddressAttribute(): string
    {
        return collect([$this->address, $this->address_2])
            ->filter(fn ($part) => filled($part))
            ->map(fn ($part) => trim($part, " \t\n\r\0\x0B,"))
            ->implode(', ');
    }

    /**
     * Nomor telepon dalam format yang enak dibaca, mis. "+6289531435435".
     *
     * Satu pelanggan bisa punya lebih dari satu nomor; aturan pemisahan dan
     * penulisannya ada di App\Support\PhoneNumber.
     */
    public function getPhoneLabelAttribute(): string
    {
        return PhoneNumber::label($this->phone);
    }
}
