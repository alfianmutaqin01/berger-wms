<?php

namespace App\Support;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Pembatasan data per gudang — SATU-SATUNYA sumber kebenaran.
 *
 * KEADAAN SEBELUM BERKAS INI ADA
 * ------------------------------
 * `users.warehouse_id` sudah ada sejak Fase 1 tetapi tidak pernah membatasi
 * apa pun. Penyaringan gudang di setiap layar hanyalah filter opsional yang
 * dibaca dari URL (`?warehouse_id=`), sehingga siapa pun bisa melihat dan
 * mengubah data gudang lain cukup dengan MENGHAPUS parameter itu. Yang hilang
 * bukan sebagian pembatasan, melainkan seluruhnya.
 *
 * DUA HAL YANG MUDAH TERTUKAR
 * ---------------------------
 *   FILTER  = pilihan pengguna, boleh dikosongkan, mempersempit tampilan.
 *   BATAS   = aturan akses, tidak bisa dikosongkan, mempersempit kewenangan.
 *
 * Berkas ini memegang BATAS. Filter tetap ada, tetapi selalu dijepit ke dalam
 * batas lewat resolveFilter(): memilih gudang lain dari URL tidak lagi
 * melebarkan apa pun, ia hanya menghasilkan daftar kosong bagi yang tidak
 * berhak — dan detailnya tetap 403 lewat assert().
 *
 * MENYARING DAFTAR TIDAK CUKUP. Lubang yang sebenarnya ada di URL detail:
 * membuka /wms/outbound/approval/{id} milik gudang lain HARUS 403. Karena itu
 * setiap titik masuk yang menerima satu objek memanggil assert(), bukan
 * sekadar mengandalkan barisnya tidak muncul di daftar.
 *
 * SIAPA YANG LINTAS GUDANG: user dengan `warehouse_id` NULL — dalam praktiknya
 * Super Admin. NULL di sana berarti "tidak dibatasi", bukan "belum diisi"
 * (lihat catatan di App\Models\Warehouse).
 */
class WarehouseScope
{
    /**
     * Gudang yang menjadi batas user ini; NULL berarti tidak dibatasi.
     */
    public static function boundary(?User $user): ?int
    {
        return $user?->warehouse_id;
    }

    public static function unrestricted(?User $user): bool
    {
        return self::boundary($user) === null;
    }

    /**
     * Persempit query ke gudang user.
     *
     * $column diberikan karena tidak semua tabel menyimpan kolomnya dengan
     * nama yang sama saat di-join (mis. `sales_orders.warehouse_id`).
     */
    public static function apply(BuilderContract $query, ?User $user, string $column = 'warehouse_id'): BuilderContract
    {
        $batas = self::boundary($user);

        return $batas === null ? $query : $query->where($column, $batas);
    }

    /**
     * Gudang yang boleh muncul di dropdown milik user ini.
     *
     * Yang dibatasi hanya melihat gudangnya sendiri. Dropdown berisi satu
     * pilihan memang terasa berlebihan, tetapi menghapusnya berarti setiap
     * view harus tahu kapan dirinya sedang dibatasi; membiarkannya satu baris
     * membuat semua layar tetap seragam.
     *
     * @return Collection<int, Warehouse>
     */
    public static function options(?User $user)
    {
        return Warehouse::query()
            ->when(! self::unrestricted($user), fn ($q) => $q->whereKey(self::boundary($user)))
            ->orderBy('code')
            ->get();
    }

    /**
     * Nilai filter gudang yang BOLEH dipakai, hasil menjepit isian URL.
     *
     * Bagi user yang dibatasi, apa pun isi `?warehouse_id=` diabaikan dan
     * diganti gudangnya sendiri. Filter tidak pernah bisa melebarkan akses.
     */
    public static function resolveFilter(Request $request, ?User $user, string $key = 'warehouse_id'): ?int
    {
        $batas = self::boundary($user);

        if ($batas !== null) {
            return $batas;
        }

        $pilihan = $request->query($key);

        return filled($pilihan) ? (int) $pilihan : null;
    }

    /**
     * Apakah user berwenang atas data milik $warehouseId?
     *
     * Bentuk boolean dari assert(), untuk FormRequest::authorize() — di sana
     * jawabannya harus dikembalikan, bukan dilemparkan. Penting bahwa
     * pemeriksaan ini ada di authorize() dan bukan hanya di controller:
     * validasi berjalan LEBIH DULU daripada controller, sehingga permintaan
     * ke gudang lain dengan isian yang tidak lengkap akan dijawab "isian
     * kurang" alih-alih "bukan wewenang Anda".
     */
    public static function allows(?int $warehouseId, ?User $user): bool
    {
        $batas = self::boundary($user);

        return $batas === null || $warehouseId === null || $warehouseId === $batas;
    }

    /**
     * Hentikan permintaan bila objek $warehouseId di luar kewenangan user.
     *
     * 403, BUKAN 404. Berbeda dengan pesanan milik Sales lain (yang disamarkan
     * jadi 404 supaya nomornya tidak bocor), di sini yang diakses adalah data
     * gudang lain yang keberadaannya memang bukan rahasia — yang salah adalah
     * kewenangannya, dan itu lebih jujur dikatakan apa adanya.
     */
    public static function assert(?int $warehouseId, ?User $user): void
    {
        abort_unless(
            self::allows($warehouseId, $user),
            403,
            'Data ini milik gudang lain. Akun Anda hanya berwenang atas '
                .($user?->warehouse?->display_label ?? 'satu gudang').'.'
        );
    }

    /**
     * Gudang yang WAJIB dipakai saat membuat data baru.
     *
     * Dipakai formulir yang dulu meminta pengguna memilih gudang (mis. Buat
     * Pesanan di Portal Sales). Sales dikunci ke gudang akunnya — keputusan
     * pemilik produk — sehingga pilihannya bukan lagi isian, melainkan fakta
     * yang sudah diketahui sejak login.
     */
    public static function require(?User $user): int
    {
        $batas = self::boundary($user);

        abort_if(
            $batas === null,
            403,
            'Akun Anda belum ditempatkan di satu gudang, sehingga tidak bisa membuat data '
                .'yang harus melekat pada gudang. Hubungi Super Admin.'
        );

        return $batas;
    }
}
