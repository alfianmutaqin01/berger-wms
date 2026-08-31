<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lokasi bin penyimpanan di gudang.
 *
 * Kode berpola [Rak]-[Level]-[Sel] — "B-01-01" = Rak B, Level 1, Sel 1.
 * Ketiga komponen disimpan terpisah agar pengurutan benar secara angka:
 * mengurutkan lewat string kode menaruh "B-01-10" sebelum "B-01-02".
 */
class Location extends Model
{
    use HasFactory, SoftDeletes;

    /** Seluruh rak punya 5 level, dari 01 sampai 05. */
    public const MAX_LEVEL = 5;

    /*
    | Zona pergerakan barang. Dipakai strategi put-away: barang cepat laku
    | ditempatkan di zona terdekat dengan jalur keluar agar picking singkat.
    |
    | Catatan: ekspor ERP menulis "Midle Moving Area" (salah eja). Nilai di
    | sini memakai ejaan yang benar; importer bertugas menormalkannya agar
    | tidak muncul dua zona yang sebenarnya sama.
    */
    public const ZONE_FAST = 'Fast Moving Area';

    public const ZONE_SLOW = 'Slow Moving Area';

    public const ZONE_MIDDLE = 'Middle Moving Area';

    public const ZONES = [self::ZONE_FAST, self::ZONE_SLOW, self::ZONE_MIDDLE];

    protected $fillable = [
        'warehouse_id',
        'code',
        'rack',
        'level',
        'cell',
        'zone',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'cell' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Kode lokasi
    |--------------------------------------------------------------------------
    */

    /** Menyusun kode dari komponennya: ("B", 1, 1) -> "B-01-01". */
    public static function buildCode(string $rack, int $level, int $cell): string
    {
        return sprintf('%s-%02d-%02d', strtoupper(trim($rack)), $level, $cell);
    }

    /**
     * Membongkar kode menjadi komponennya.
     *
     * Rak boleh satu atau dua huruf (B .. ZD), level & sel wajib angka.
     *
     * @return array{rack: string, level: int, cell: int}|null
     */
    public static function parseCode(?string $code): ?array
    {
        if (blank($code) || ! preg_match('/^([A-Z]{1,2})-(\d{1,2})-(\d{1,3})$/i', trim($code), $m)) {
            return null;
        }

        return [
            'rack' => strtoupper($m[1]),
            'level' => (int) $m[2],
            'cell' => (int) $m[3],
        ];
    }

    /**
     * Menormalkan nama zona dari sumber luar.
     *
     * Ekspor ERP memakai ejaan "Midle Moving Area"; tanpa normalisasi, akan
     * muncul dua zona berbeda yang sebenarnya sama.
     */
    public static function normalizeZone(?string $zone): ?string
    {
        $zone = trim((string) $zone);

        if ($zone === '') {
            return null;
        }

        foreach (self::ZONES as $known) {
            if (strcasecmp($zone, $known) === 0) {
                return $known;
            }
        }

        return match (true) {
            str_contains(strtolower($zone), 'fast') => self::ZONE_FAST,
            str_contains(strtolower($zone), 'slow') => self::ZONE_SLOW,
            // Menangkap "Midle" maupun "Middle".
            str_contains(strtolower($zone), 'midle'),
            str_contains(strtolower($zone), 'middle') => self::ZONE_MIDDLE,
            default => $zone,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Urutan alami di lantai gudang: rak, lalu level, lalu sel. */
    public function scopeInStorageOrder(Builder $query): Builder
    {
        return $query->orderBy('rack')->orderBy('level')->orderBy('cell');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('code', 'ILIKE', $like)->orWhere('zone', 'ILIKE', $like);
        });
    }

    /** Label ringkas untuk tampilan: "Rak B · Level 1 · Sel 1". */
    public function getPositionLabelAttribute(): string
    {
        return sprintf('Rak %s · Level %d · Sel %d', $this->rack, $this->level, $this->cell);
    }
}
