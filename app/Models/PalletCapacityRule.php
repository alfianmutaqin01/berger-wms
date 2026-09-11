<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu aturan kapasitas palet — "ukuran segini, muat sekian per palet".
 *
 * ANGKANYA TIDAK BISA DITURUNKAN DARI RUMUS. 20 Liter memuat 27 pcs sementara
 * 20 Kg memuat 36 pcs; wadahnya yang berbeda, bukan isinya. Karena itu ini
 * data operasional yang diisi orang gudang, bukan perhitungan.
 *
 * WADAH BOLEH KOSONG. Aturan tanpa `uom` berlaku untuk semua wadah pada ukuran
 * itu; aturan yang menyebut wadah menang atasnya. Dua tingkat ini ada supaya
 * "20 L pail" bisa berbeda dari "20 L tin" tanpa memaksa orang mengetik ulang
 * angka yang sama untuk setiap wadah yang pernah dipakai.
 */
class PalletCapacityRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'pack_unit',
        'pack_size',
        'uom',
        'max_qty_per_pallet',
        'note',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'pack_size' => 'decimal:3',
            'max_qty_per_pallet' => 'integer',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** "20 L PAIL" atau "20 L (semua wadah)" — dipakai di layar dan di log. */
    public function getSebutanAttribute(): string
    {
        return sprintf(
            '%s %s %s',
            rtrim(rtrim(number_format((float) $this->pack_size, 3, '.', ''), '0'), '.'),
            $this->pack_unit,
            $this->uom ?? '(semua wadah)',
        );
    }
}
