<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Gudang / dispatch code.
 *
 * `users.warehouse_id` yang bernilai NULL berarti user tersebut tidak dibatasi
 * ke satu gudang (akses lintas gudang), bukan berarti datanya belum diisi.
 */
class Warehouse extends Model
{
    use HasFactory, SoftDeletes;

    /** Melayani SEMUA wilayah; daftar territory diabaikan. */
    public const MODE_ALL = 'all';

    /** Melayani HANYA wilayah yang terdaftar. */
    public const MODE_ONLY = 'only';

    /** Melayani semua wilayah KECUALI yang terdaftar. */
    public const MODE_EXCEPT = 'except';

    protected $fillable = [
        'code',
        'name',
        'address',
        'territory_mode',
        'has_production',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'has_production' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Gudang baru langsung mendapat kedua rak transitnya.
     *
     * Serah terima MRF tidak bisa dijalankan sama sekali tanpa rak transit,
     * dan kalau pembuatannya diserahkan ke pengisian master data, gudang yang
     * baru dibuka akan punya alur MRF yang mati tanpa ada yang tahu sebabnya
     * sampai ada operator berdiri di depan layar tanpa satu pun pilihan rak.
     *
     * Di sini, bukan di migrasi saja: migrasi hanya mengurus gudang yang sudah
     * ada saat ia dijalankan.
     */
    protected static function booted(): void
    {
        static::created(fn (self $gudang) => $gudang->pastikanRakTransit());
    }

    /** Membuat rak transit yang belum ada. Aman dipanggil berulang. */
    public function pastikanRakTransit(): void
    {
        foreach ([
            // Deretnya pendek karena kolom `rack` hanya menampung 5 karakter.
            'TRANSIT-PROD' => ['TR-PR', Location::ZONE_TRANSIT_PRODUKSI],
            'TRANSIT-LOG' => ['TR-LG', Location::ZONE_TRANSIT_LOGISTIK],
        ] as $kode => [$deret, $zona]) {
            Location::query()->firstOrCreate(
                ['warehouse_id' => $this->id, 'code' => $kode],
                ['rack' => $deret, 'level' => 1, 'cell' => 1, 'zone' => $zona, 'is_active' => true],
            );
        }
    }

    /** Gudang yang punya lini produksi — hanya Karawang untuk saat ini. */
    public function scopeWithProduction($query)
    {
        return $query->where('has_production', true);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function territories(): HasMany
    {
        return $this->hasMany(WarehouseTerritory::class);
    }

    /** Kode wilayah yang terdaftar untuk gudang ini, semuanya huruf besar. */
    public function territoryCodes(): array
    {
        $daftar = $this->relationLoaded('territories')
            ? $this->territories->pluck('territory_code')
            : $this->territories()->pluck('territory_code');

        return $daftar->map(fn ($kode) => mb_strtoupper(trim((string) $kode)))->all();
    }

    /**
     * Apakah gudang ini boleh melayani pelanggan di wilayah $territoryCode?
     *
     * Wilayah KOSONG dianggap terlayani, bukan tertolak. Pelanggan tanpa
     * territory_code adalah master data yang belum lengkap; menolaknya di sini
     * membuat kesalahan input muncul di layar Sales sebagai pelanggan yang
     * "hilang" tanpa sebab yang bisa ditebak.
     */
    public function servesTerritory(?string $territoryCode): bool
    {
        if ($this->territory_mode === self::MODE_ALL || blank($territoryCode)) {
            return true;
        }

        $cocok = in_array(mb_strtoupper(trim($territoryCode)), $this->territoryCodes(), true);

        return $this->territory_mode === self::MODE_ONLY ? $cocok : ! $cocok;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Label siap tampil untuk dropdown dan badge, contoh: "WH-01 (Karawang)". */
    public function getDisplayLabelAttribute(): string
    {
        return "{$this->code} ({$this->name})";
    }

    /**
     * Kode CABANG saja, tanpa akhiran jenis barangnya: ID11_1001 -> ID11.
     *
     * DUA KODE INI BUKAN PANJANG-PENDEK BELAKA, dan salah memilihnya bukan
     * soal kerapian:
     *
     *   ID11        cabangnya — Karawang, seluruh barang di dalamnya
     *   ID11_1001   cabang DAN jenis barangnya; "1001" berarti finish good
     *
     * Jangkauan kode pendek karena itu LEBIH LEBAR: ia memuat finish good
     * berikut barang DDP, rusak, dan berbau.
     *
     * KAPAN MEMAKAI YANG MANA ditentukan barangnya, bukan sempitnya layar:
     *
     *   Penjualan       SELALU kode penuh. Yang dikirim ke pelanggan selalu
     *                   finish good — tidak mungkin barang DDP atau rusak —
     *                   jadi menyebutnya "ID11" saja membuang keterangan yang
     *                   justru menjadi janji ke pelanggan.
     *   MRF & pengujian Kode pendek. Reproses dan pengujian kadang mengambil
     *                   barang DDP, kadang barang bagus; mengunci layarnya ke
     *                   "_1001" menyebut jenis yang belum tentu benar.
     *   Audit & jejak   Kode pendek. Layar yang memuat BERBAGAI jenis mutasi
     *                   sekaligus — item ledger, log aktivitas — tidak boleh
     *                   memakai kode penuh: lihat alasannya di bawah.
     *
     * AKHIRANNYA MELEKAT PADA BARIS GUDANGNYA, BUKAN PADA MUTASINYA. Tiap
     * cabang cuma punya satu baris gudang, dan kodenya berakhiran "_1001"
     * apa pun yang bergerak di dalamnya. Jadi menuliskan kode penuh di baris
     * pengeluaran MRF yang barangnya DDP tidak sekadar bertele-tele — ia
     * menyatakan barang itu finish good, dan itu salah. Di layar yang mencampur
     * banyak jenis mutasi, kode cabang adalah satu-satunya yang benar untuk
     * semua barisnya.
     *
     * Jadi jangan "merapikan" layar penjualan menjadi kode pendek — itu
     * menghapus keterangan, bukan memendekkan tulisan. Kode PENUH juga tetap
     * dipakai di tempat yang harus cocok dengan sistem lain: impor, ekspor,
     * dan dokumen.
     */
    public function getKodePendekAttribute(): string
    {
        return strtok((string) $this->code, '_') ?: (string) $this->code;
    }
}
