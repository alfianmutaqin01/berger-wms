<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nomor WhatsApp atasan yang disimpan Produksi untuk MRF berikutnya.
 *
 * Bukan master data. Yang menyimpannya orang Produksi sendiri, lewat satu
 * centang di formulir MRF — dan itu permintaan pemilik produk: yang tahu
 * kepada siapa permintaan hari ini dikirim adalah orang yang membuatnya.
 *
 * DIPAKAI BERSAMA SATU GUDANG. Nomor Pak Gandhi dipakai siapa pun di Karawang;
 * menyimpannya per akun berarti nomor yang sama diketik ulang berkali-kali,
 * dan nomor yang diketik ulang adalah nomor yang cepat atau lambat salah satu
 * digitnya.
 */
class MrfApproverContact extends Model
{
    use HasFactory;

    protected $fillable = ['warehouse_id', 'name', 'phone', 'created_by'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Kontak yang boleh dipakai akun di gudang $warehouseId.
     *
     * Kontak lintas gudang (warehouse_id NULL) ikut terbawa: ia disimpan
     * akun Super Admin yang memang tidak terikat satu gudang, dan menyembunyi
     * kannya berarti nomor itu tidak pernah bisa dipakai siapa pun.
     */
    public function scopeUntukGudang(Builder $query, ?int $warehouseId): Builder
    {
        if ($warehouseId === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('warehouse_id', $warehouseId)
            ->orWhereNull('warehouse_id'));
    }

    /** Nomor siap kirim WhatsApp; NULL bila tersimpan dalam bentuk yang tidak wajar. */
    public function nomorWhatsApp(): ?string
    {
        return PhoneNumber::forWhatsApp($this->phone);
    }

    public function getPhoneLabelAttribute(): string
    {
        return PhoneNumber::label($this->phone);
    }
}
