<?php

namespace App\Support\Reporting;

use App\Support\Permission;
use InvalidArgumentException;

/**
 * Daftar laporan yang tersedia — SATU tempat, dibaca halaman maupun unduhan.
 *
 * KENAPA REGISTRI, BUKAN DELAPAN METODE YANG BERSERAK
 * ---------------------------------------------------
 * Kartu di layar, judul di berkas Excel, izin penjaganya, dan penjelasan
 * "rentang tanggalnya menghitung apa" semuanya harus sepakat. Kalau tiap
 * potongan itu tinggal di tempat masing-masing, suatu hari kartunya berkata
 * "berdasarkan tanggal kirim" sementara query-nya menyaring tanggal selesai —
 * dan tidak ada yang tahu angkanya salah, karena angkanya tetap keluar.
 *
 * SISTEM INI TIDAK MENYIMPAN HARGA
 * --------------------------------
 * Tidak ada satu pun kolom rupiah di seluruh basis data — tidak di products,
 * tidak di sales_order_details. Karena itu semua laporan di sini berbicara
 * dalam KUANTITAS, dan kata "penjualan" berarti barang yang keluar, bukan
 * omzet. Itu dikatakan di layar, bukan dibiarkan disimpulkan sendiri oleh
 * orang yang membuka berkasnya dan mencari kolom nilai.
 *
 * DUA SIFAT LAPORAN, DAN BEDANYA PENTING
 * --------------------------------------
 *   BERKALA  — menjawab "apa yang terjadi antara tanggal A dan B". Rentang
 *              tanggalnya berlaku, dan hasilnya tidak berubah kalau diunduh
 *              ulang besok.
 *   POTRET   — menjawab "bagaimana keadaannya SEKARANG". Rentang tanggal
 *              tidak berlaku sama sekali, dan hasil hari ini berbeda dengan
 *              hasil besok. Kolom tanggalnya sengaja dimatikan di layar
 *              supaya tidak ada yang mengira sudah menyaring padahal tidak.
 *
 * KENAPA IZINNYA SAMA SEMUA
 * -------------------------
 * Kolom `izin` ada dan benar-benar diperiksa, tetapi kedelapan laporan saat
 * ini memakai REPORTS_VIEW yang sama. Sempat terpikir menjaga laporan stok
 * dengan INVENTORY_VIEW — dan itu akan menjadi pembatasan PALSU: ketiga peran
 * yang bisa membuka halaman ini (Super Admin, Manager, Logistik) sudah
 * memegang INVENTORY_VIEW, jadi tidak seorang pun tersaring olehnya. Pagar
 * yang tidak menghalangi siapa-siapa lebih buruk daripada tidak ada pagar,
 * karena yang membacanya mengira ada yang terjaga.
 *
 * Kolomnya tetap dipertahankan karena mesinnya sungguh menghormatinya: begitu
 * ada laporan yang memang perlu dipersempit, cukup ganti satu nilai di sini
 * dan kartunya hilang dari layar SEKALIGUS unduhannya ditolak 403.
 */
class ReportCatalog
{
    /** Baris yang ditampilkan sebagai pratinjau di layar. */
    public const PRATINJAU = 25;

    /**
     * Batas baris satu berkas unduhan.
     *
     * Bukan angka takhayul: satu lembar Excel memang muat lebih banyak, tetapi
     * menyusunnya di memori PHP tidak. Yang menabrak batas ini diberi tahu
     * terang-terangan di layar agar mempersempit rentangnya — jauh lebih baik
     * daripada berkas yang diam-diam terpotong dan dipakai untuk mengambil
     * keputusan.
     */
    public const MAKS_BARIS = 20000;

    /**
     * @return array<string, array{
     *     nama: string, ringkas: string, ikon: string, warna: string,
     *     izin: string, berkala: bool, dasar: string, bantuan: string
     * }>
     */
    public static function daftar(): array
    {
        return [
            // Kunci 'penjualan-selesai' SENGAJA tidak ikut diganti meski
            // namanya sekarang "Finish Order". Kunci itu hidup di URL yang
            // sudah di-bookmark orang dan di properties activity_logs milik
            // unduhan lama; menggantinya mematikan tautan lama dan memecah
            // riwayat unduhan menjadi dua nama untuk laporan yang sama.
            'penjualan-selesai' => [
                'nama' => 'Finish Order',
                'ringkas' => 'Pesanan yang sudah tuntas, dirinci per baris produk.',
                'ikon' => 'bi-check2-circle',
                'warna' => 'success',
                'izin' => Permission::REPORTS_VIEW,
                'berkala' => true,
                'dasar' => 'tanggal pesanan dinyatakan selesai',
                'bantuan' => 'Hanya pesanan berstatus Selesai dan Selesai (Penagihan). '
                    .'Satu baris per produk, bukan per pesanan, supaya bisa langsung '
                    .'di-pivot per SKU atau per pelanggan.',
            ],

            'pesanan-outstanding' => [
                'nama' => 'Pesanan Outstanding',
                'ringkas' => 'Barang yang sudah dipesan tetapi belum terkirim.',
                'ikon' => 'bi-hourglass-split',
                'warna' => 'warning',
                'izin' => Permission::REPORTS_VIEW,
                'berkala' => false,
                'dasar' => 'keadaan saat ini',
                'bantuan' => 'POTRET, bukan riwayat: isinya sisa yang MASIH menggantung '
                    .'hari ini. Baris yang sudah dikirim ulang hilang dengan sendirinya. '
                    .'Kolom Umur menghitung sejak pesanannya masuk — itu yang menentukan '
                    .'mana yang harus dikejar lebih dulu.',
            ],

            'produk-terlaris' => [
                'nama' => 'Produk Terlaris',
                'ringkas' => 'Peringkat produk menurut jumlah yang benar-benar keluar.',
                'ikon' => 'bi-trophy',
                'warna' => 'primary',
                'izin' => Permission::REPORTS_VIEW,
                'berkala' => true,
                'dasar' => 'tanggal pesanan dikirim',
                'bantuan' => 'Yang dihitung qty TERKIRIM, bukan qty dipesan. Pesanan yang '
                    .'masuk tetapi barangnya tidak ada bukan penjualan — memasukkannya '
                    .'membuat produk yang justru sering kosong terlihat paling laku.',
            ],

            'pelanggan-teratas' => [
                'nama' => 'Pelanggan Teratas',
                'ringkas' => 'Peringkat pelanggan menurut volume yang diterima.',
                'ikon' => 'bi-people',
                'warna' => 'info',
                'izin' => Permission::REPORTS_VIEW,
                'berkala' => true,
                'dasar' => 'tanggal pesanan dikirim',
                'bantuan' => 'Kolom Outstanding sengaja disandingkan: pelanggan dengan '
                    .'volume besar DAN outstanding besar adalah yang paling sering '
                    .'menelepon, dan yang paling perlu dihubungi lebih dulu.',
            ],

            'kinerja-sales' => [
                'nama' => 'Kinerja Sales',
                'ringkas' => 'Volume dan tingkat pemenuhan pesanan tiap Sales.',
                'ikon' => 'bi-person-badge',
                'warna' => 'secondary',
                'izin' => Permission::REPORTS_VIEW,
                'berkala' => true,
                'dasar' => 'tanggal pesanan diajukan',
                'bantuan' => 'Baca kolom "% Terpenuhi" dengan hati-hati: barang kosong '
                    .'bukan salah Sales. Angka ini menunjukkan pelanggan siapa yang '
                    .'paling sering kecewa, bukan siapa yang bekerja paling buruk.',
            ],

            'pengiriman' => [
                'nama' => 'Pengiriman',
                'ringkas' => 'Surat jalan beserta supir, waktu tempuh, dan bukti fotonya.',
                'ikon' => 'bi-truck',
                'warna' => 'dark',
                'izin' => Permission::REPORTS_VIEW,
                'berkala' => true,
                'dasar' => 'tanggal surat jalan dikirim',
                'bantuan' => 'Kolom "Foto Bukti" bernilai 0 berarti barang tercatat sampai '
                    .'tanpa satu pun lampiran. Itu yang dicari lebih dulu kalau ada '
                    .'pengiriman yang dipersoalkan pelanggan.',
            ],

            'posisi-stok' => [
                'nama' => 'Posisi Stok',
                'ringkas' => 'Sisa stok per batch beserta lokasi dan umur simpannya.',
                'ikon' => 'bi-boxes',
                'warna' => 'primary',
                'izin' => Permission::REPORTS_VIEW,
                'berkala' => false,
                'dasar' => 'keadaan saat ini',
                'bantuan' => 'POTRET keadaan detik ini, bukan riwayat — mengunduhnya lagi '
                    .'besok menghasilkan angka lain. Qty Dialokasi adalah barang yang '
                    .'masih di rak tetapi sudah menjadi jatah pesanan orang lain.',
            ],

            'pergerakan-stok' => [
                'nama' => 'Pergerakan Stok',
                'ringkas' => 'Kartu stok: tiap penambahan dan pengurangan beserta pelakunya.',
                'ikon' => 'bi-arrow-left-right',
                'warna' => 'danger',
                'izin' => Permission::REPORTS_VIEW,
                'berkala' => true,
                'dasar' => 'waktu pergerakan tercatat',
                'bantuan' => 'Ini laporan yang dipakai saat ada selisih dan seseorang '
                    .'bertanya "kok bisa". Kolom Sebelum dan Sesudah membuat tiap baris '
                    .'bisa ditelusuri berurutan tanpa perlu menjumlahkan sendiri.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function ambil(string $key): array
    {
        $daftar = self::daftar();

        if (! isset($daftar[$key])) {
            throw new InvalidArgumentException("Laporan '{$key}' tidak dikenal.");
        }

        return $daftar[$key];
    }

    public static function ada(string $key): bool
    {
        return isset(self::daftar()[$key]);
    }
}
