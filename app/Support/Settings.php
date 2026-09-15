<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Setelan operasional yang boleh diubah Super Admin — Fase 10.
 *
 * SENGAJA SEDIKIT, dan itu keputusan rancangan, bukan kemalasan. Tiap setelan
 * adalah satu keadaan lagi yang harus dipikirkan setiap kali ada yang aneh:
 * "apakah ini bug, atau memang begitu setelannya?". Yang masuk ke sini harus
 * lolos tiga ujian sekaligus:
 *
 *   1. Ini keputusan BISNIS, bukan keputusan teknis.
 *   2. Nilainya memang berubah dari waktu ke waktu.
 *   3. Salah isi TIDAK merusak sistem — cuma membuat perilakunya berbeda.
 *
 * Karena ujian ketiga itulah PENOMORAN DOKUMEN tidak ada di sini. Mengubah
 * prefix di tengah jalan memecah riwayat menjadi dua bentuk yang tidak bisa
 * dicari sekaligus, dan mengubah nomor urut mundur langsung menghasilkan
 * nomor kembar yang membuat pembuatan pesanan berhenti total untuk semua
 * orang. Halaman penomoran tetap ada, tetapi BACA-SAJA.
 *
 * NILAI BAWAAN TINGGAL DI KODE, bukan diisi lewat migrasi. Tabelnya hanya
 * menyimpan yang sudah diubah; setelan baru langsung hidup dengan bawaannya,
 * dan setelan yang dihapus dari daftar ini berhenti terbaca walau barisnya
 * masih ada.
 *
 * DIBACA DI SETIAP PERMINTAAN (jam cutoff muncul di dashboard Sales), jadi
 * di-cache selamanya dan dibuang saat disimpan. Kalau cache-nya bermasalah,
 * yang dikembalikan nilai bawaan — bukan galat: setelan yang gagal dibaca
 * tidak boleh menjatuhkan halaman yang kebetulan memakainya.
 */
class Settings
{
    private const CACHE_KEY = 'wms.settings';

    /* -------------------------------------------------- Kunci yang dikenal */

    public const ORDER_CUTOFF_HOUR = 'order_cutoff_hour';

    public const EXPIRY_WARNING_DAYS = 'expiry_warning_days';

    public const QUARANTINE_SOON_DAYS = 'quarantine_soon_days';

    public const PROOF_MAX_PHOTOS = 'proof_max_photos';

    public const ACTIVITY_RETENTION_DAYS = 'activity_retention_days';

    /**
     * Daftar setelan yang dikenal.
     *
     * SATU SUMBER untuk validasi, tampilan, dan nilai bawaan sekaligus.
     * Menyimpannya di tiga tempat berarti suatu hari formulirnya menerima
     * angka yang ditolak penyimpannya, atau sebaliknya.
     *
     * @return array<string, array{label:string, satuan:string, min:int, max:int, bawaan:int, bantuan:string, peringatan?:string}>
     */
    public static function daftar(): array
    {
        return [
            self::ORDER_CUTOFF_HOUR => [
                'label' => 'Batas jam submit pesanan',
                'satuan' => 'pukul',
                'min' => 1,
                'max' => 23,
                'bawaan' => 15,
                'bantuan' => 'Lewat jam ini tombol Submit dikunci untuk Sales. '
                    .'Simpan Draft TETAP aktif — pesanan sore hari harus punya tempat disimpan.',
            ],
            self::EXPIRY_WARNING_DAYS => [
                'label' => 'Peringatan dini kedaluwarsa',
                'satuan' => 'hari',
                'min' => 7,
                'max' => 365,
                'bawaan' => 90,
                'bantuan' => 'Batch yang kedaluwarsa dalam rentang ini muncul sebagai peringatan '
                    .'di Data Stok dan dashboard.',
            ],
            self::QUARANTINE_SOON_DAYS => [
                'label' => 'Karantina hampir lepas',
                'satuan' => 'hari',
                'min' => 1,
                'max' => 60,
                'bawaan' => 7,
                'bantuan' => 'Batch yang masa karantinanya habis dalam rentang ini ditandai di dashboard, '
                    .'supaya tidak mendadak masuk rekomendasi picking tanpa ada yang siap.',
            ],
            self::PROOF_MAX_PHOTOS => [
                'label' => 'Maksimal foto bukti Surat Jalan',
                'satuan' => 'foto',
                'min' => 1,
                'max' => 10,
                'bawaan' => 3,
                'bantuan' => 'Kuota per pesanan. Foto yang sudah DITOLAK Logistik tidak ikut dihitung, '
                    .'jadi Sales yang salah potret berkali-kali tidak terkunci.',
            ],
            self::ACTIVITY_RETENTION_DAYS => [
                'label' => 'Umur simpan log aktivitas',
                'satuan' => 'hari',
                'min' => 30,
                'max' => 730,
                'bawaan' => 90,
                'bantuan' => 'Log yang lebih tua dibuang otomatis tiap malam.',
                'peringatan' => 'MENURUNKAN angka ini MENGHAPUS log yang sudah ada pada pembersihan '
                    .'berikutnya, dan log tidak bisa dikembalikan. Perubahan setelan ini sendiri '
                    .'ikut tercatat di log aktivitas.',
            ],
        ];
    }

    /* ------------------------------------------------------------- Membaca */

    public static function get(string $key): int
    {
        $bawaan = self::daftar()[$key]['bawaan'] ?? 0;

        try {
            $tersimpan = self::semua();
        } catch (Throwable) {
            // Tabelnya belum ada (migrasi baru), atau basis datanya sedang
            // bermasalah. Setelan yang gagal dibaca tidak boleh menjatuhkan
            // halaman yang kebetulan memakainya.
            return $bawaan;
        }

        return isset($tersimpan[$key]) ? (int) $tersimpan[$key] : $bawaan;
    }

    /**
     * Nilai seluruh setelan yang dikenal — yang tersimpan maupun yang bawaan.
     *
     * @return array<string, int>
     */
    public static function nilai(): array
    {
        $hasil = [];

        foreach (array_keys(self::daftar()) as $key) {
            $hasil[$key] = self::get($key);
        }

        return $hasil;
    }

    /* ----------------------------------------------------------- Menyimpan */

    /**
     * @param  array<string, int|string>  $nilai
     * @return array<string, array{lama:int, baru:int}> yang benar-benar berubah
     */
    public static function simpan(array $nilai, ?int $userId): array
    {
        $daftar = self::daftar();
        $berubah = [];

        foreach ($nilai as $key => $baru) {
            if (! isset($daftar[$key])) {
                continue;
            }

            $lama = self::get($key);
            $baru = (int) $baru;

            // Nilai yang tidak berubah tidak ditulis. Kalau tidak, tiap
            // penekanan Simpan menghasilkan satu baris log per setelan dan
            // riwayatnya penuh oleh perubahan yang tidak pernah terjadi.
            if ($baru === $lama) {
                continue;
            }

            SystemSetting::updateOrCreate(
                ['key' => $key],
                ['value' => (string) $baru, 'updated_by' => $userId],
            );

            $berubah[$key] = ['lama' => $lama, 'baru' => $baru];
        }

        if ($berubah !== []) {
            self::lupakan();
        }

        return $berubah;
    }

    public static function lupakan(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /* -------------------------------------------------------------- Dalam */

    /**
     * @return array<string, string>
     */
    private static function semua(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn () => SystemSetting::query()->pluck('value', 'key')->all(),
        );
    }
}
