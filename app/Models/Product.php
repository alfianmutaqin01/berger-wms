<?php

namespace App\Models;

use App\Support\PalletCapacity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Master SKU produk.
 *
 * Model ini TIDAK memuat jumlah stok. Stok tinggal di `inventory_stocks`
 * (dibangun pada Fase 4), terpecah per gudang/lokasi/batch/kedaluwarsa supaya
 * FIFO dan aturan expiry bisa berjalan. Untuk menampilkan angka stok, jumlahkan
 * dari sana — jangan pernah menambahkan kolom stok di tabel ini.
 */
class Product extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Awalan SKU pada ekspor ERP Berger.
     *
     * Seluruh data contoh memakai "ID1-F" (ID1 = entitas Indonesia, F =
     * finished goods). Dipakai hanya untuk MEMBENTUK SKU saat produk dibuat
     * lewat form; SKU hasil impor tetap disimpan apa adanya, sehingga bila
     * suatu saat ERP memakai awalan lain, datanya tidak ikut rusak.
     */
    public const SKU_PREFIX = 'ID1-F';

    protected $fillable = [
        'sku',
        'name',
        'description',
        'product_code',
        'shade_code',
        'pack_code',
        'category_id',
        'uom',
        'pack_unit',
        'unit_volume',
        'net_weight',
        'gross_weight',
        'max_qty_per_pallet',
        'shelf_life_months',
        'stock_threshold_low',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'unit_volume' => 'decimal:3',
            'net_weight' => 'decimal:3',
            'gross_weight' => 'decimal:3',
            'max_qty_per_pallet' => 'integer',
            'shelf_life_months' => 'integer',
            'stock_threshold_low' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relasi
    |--------------------------------------------------------------------------
    */

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /*
    |--------------------------------------------------------------------------
    | SKU & kapasitas palet
    |--------------------------------------------------------------------------
    */

    /** Membentuk SKU dari tiga komponen kode, contoh: ID1-F + 0011 + 3202 + 203. */
    public static function buildSku(string $productCode, string $shadeCode, string $packCode): string
    {
        return self::SKU_PREFIX.strtoupper(trim($productCode).trim($shadeCode).trim($packCode));
    }

    /**
     * Ukuran kemasan yang menentukan kapasitas palet.
     *
     * `pack_unit` menyatakan kolom mana yang berlaku, karena satu produk bisa
     * punya volume DAN berat sekaligus (mis. 20 L dengan bobot kotor 27 Kg) —
     * tanpa penanda ini, sistem bisa salah memilih aturan 20 L (27 pcs) atau
     * 20 Kg (36 pcs).
     */
    public function packSize(): int|float|null
    {
        return match ($this->pack_unit) {
            PalletCapacity::UNIT_LITER => $this->unit_volume === null ? null : (float) $this->unit_volume,
            PalletCapacity::UNIT_KILOGRAM => $this->net_weight === null ? null : (float) $this->net_weight,
            default => null,
        };
    }

    /** Kapasitas palet menurut aturan gudang; NULL bila ukurannya tidak terdaftar. */
    public function resolvePalletCapacity(): ?int
    {
        return PalletCapacity::resolve($this->pack_unit, $this->packSize());
    }

    /**
     * Produk yang kapasitas paletnya belum diketahui.
     *
     * Ditandai di layar agar Manager melengkapinya, karena aturan pemecahan
     * palet otomatis (PRD §7.1) tidak bisa jalan tanpa angka ini.
     */
    public function needsPalletCapacity(): bool
    {
        return $this->max_qty_per_pallet === null;
    }

    /*
    |--------------------------------------------------------------------------
    | Scope & accessor
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Pencarian bebas pada SKU dan nama produk (ILIKE = tidak peka huruf besar/kecil). */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('sku', 'ILIKE', $like)
                ->orWhere('name', 'ILIKE', $like)
                ->orWhere('product_code', 'ILIKE', $like)
                ->orWhere('shade_code', 'ILIKE', $like);
        });
    }

    /** Label ukuran untuk tampilan, contoh: "2.5 L" atau "20 Kg". */
    public function getPackLabelAttribute(): string
    {
        $size = $this->packSize();

        if ($size === null) {
            return '-';
        }

        return rtrim(rtrim(number_format($size, 2, '.', ''), '0'), '.').' '.
            ($this->pack_unit === PalletCapacity::UNIT_LITER ? 'L' : 'Kg');
    }
}
