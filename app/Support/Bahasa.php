<?php

namespace App\Support;

use App\Models\Role;
use Illuminate\Http\Request;

/**
 * Pilihan bahasa tampilan — satu-satunya sumber kebenarannya.
 *
 * APA YANG DITERJEMAHKAN. Hanya yang ditulis sistem: menu, judul, tombol,
 * label status, pesan berhasil/gagal. TIDAK termasuk isi basis data — nama
 * produk, nama pelanggan, SKU, nomor batch, kode rak, dan catatan yang
 * diketik orang tetap apa adanya. Menerjemahkan data berarti mengarang data.
 *
 * KENAPA DI SESSION, BUKAN KOLOM users (keputusan pemilik produk). Pilihannya
 * berlaku sampai logout, lalu kembali ke bahasa Indonesia. Karena
 * AuthController::logout() memanggil session()->invalidate(), pengembaliannya
 * terjadi sendiri — tidak ada kode yang perlu mengingat untuk membersihkannya,
 * dan tidak ada kolom yang bisa tertinggal berisi pilihan orang lain pada
 * komputer bersama.
 *
 * TERKUNCI SELAMA SESSION BERJALAN. Pilihannya dibaca dari session pada SETIAP
 * permintaan (lihat App\Http\Middleware\PilihanBahasa), termasuk permintaan
 * latar seperti pencarian ketik dan penyegaran lonceng. Tanpa itu, satu
 * permintaan yang terlewat sudah cukup membuat sepotong layar kembali ke
 * bahasa Indonesia di tengah halaman berbahasa Inggris.
 *
 * HALAMAN BERTAUTAN TIDAK IKUT. ePOD dan persetujuan MRF selalu bahasa
 * Indonesia — lihat App\Http\Middleware\PaksaBahasaIndonesia.
 */
final class Bahasa
{
    /** Kunci session. Dinamai jelas: session ini dibaca juga saat menelusuri galat. */
    public const KUNCI_SESSION = 'bahasa_tampilan';

    public const INDONESIA = 'id';

    public const INGGRIS = 'en';

    /**
     * Bahasa yang benar-benar ada berkas terjemahannya.
     *
     * Nama bahasanya SENGAJA tidak diterjemahkan: "English" ditulis English
     * dan "Bahasa Indonesia" ditulis Indonesia, supaya orang yang tersesat di
     * bahasa yang tidak ia mengerti tetap bisa menemukan jalan pulang.
     *
     * @var array<string, array{nama:string, singkat:string}>
     */
    public const TERSEDIA = [
        self::INDONESIA => ['nama' => 'Bahasa Indonesia', 'singkat' => 'ID'],
        self::INGGRIS => ['nama' => 'English', 'singkat' => 'EN'],
    ];

    /**
     * Label peran, dikunci pada SLUG bukan pada nama di basis data.
     *
     * Slug bersifat tetap (lihat App\Models\Role); `name` hanya tampilan dan
     * bisa diubah lewat seeder kapan saja. Menerjemahkan berdasarkan nama
     * berarti terjemahannya diam-diam berhenti bekerja pada hari seseorang
     * memperbaiki satu huruf di sana — dan tidak ada yang akan menyadarinya
     * sampai ada pengguna bertanya kenapa lencananya kembali berbahasa
     * Indonesia.
     *
     * @var array<string, string>
     */
    private const LABEL_PERAN = [
        Role::SUPER_ADMIN => 'Super Admin',
        Role::MANAGER => 'Manager',
        Role::LOGISTICS => 'Logistics Team',
        Role::PRODUCTION => 'Production Team',
        Role::WAREHOUSE_OPERATOR => 'Warehouse Operator',
        Role::SALES => 'Sales Team',
    ];

    public static function sah(?string $kode): bool
    {
        return $kode !== null && array_key_exists($kode, self::TERSEDIA);
    }

    /** Bahasa yang sedang dipakai layar. */
    public static function sekarang(): string
    {
        $kode = app()->getLocale();

        return self::sah($kode) ? $kode : self::INDONESIA;
    }

    public static function singkat(): string
    {
        return self::TERSEDIA[self::sekarang()]['singkat'];
    }

    /** Pilihan yang tersimpan di session, atau null bila belum pernah memilih. */
    public static function pilihan(Request $request): ?string
    {
        $kode = $request->session()->get(self::KUNCI_SESSION);

        return is_string($kode) && self::sah($kode) ? $kode : null;
    }

    public static function simpan(Request $request, string $kode): void
    {
        $request->session()->put(self::KUNCI_SESSION, $kode);
    }

    /**
     * Nama peran dalam bahasa yang sedang dipakai.
     *
     * Bukan isi basis data yang diterjemahkan, melainkan slug-nya yang
     * dipetakan ke label. Nama gudang, departemen, produk, dan pelanggan TIDAK
     * ikut: itu data sungguhan, dan Berger Paints Karawang bukan "Berger
     * Paints Karawang Warehouse" dalam bahasa apa pun.
     */
    public static function peran(?Role $peran): string
    {
        if ($peran === null) {
            return '';
        }

        if (self::sekarang() === self::INDONESIA) {
            return $peran->name;
        }

        return self::LABEL_PERAN[$peran->slug] ?? $peran->name;
    }

    /** Dipakai test: memastikan tidak ada peran yang kehilangan labelnya. */
    public static function labelPeran(): array
    {
        return self::LABEL_PERAN;
    }
}
