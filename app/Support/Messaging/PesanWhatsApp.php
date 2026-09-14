<?php

namespace App\Support\Messaging;

/**
 * Satu pesan WhatsApp, dalam DUA bentuk sekaligus.
 *
 * KENAPA DUA BENTUK. Penyedia yang berbeda menerima pesan dengan cara yang
 * berbeda sama sekali, dan pemanggilnya tidak boleh perlu tahu penyedia mana
 * yang sedang dipakai:
 *
 *   teks              dipakai mode manual (tombol wa.me), mode log, dan
 *                     gateway seperti Fonnte yang menerima teks bebas
 *   template+variabel dipakai WhatsApp Cloud API resmi Meta, yang HANYA
 *                     menerima template yang sudah disetujui untuk pesan yang
 *                     dimulai bisnis
 *
 * KENAPA TEMPLATE IKUT DIBAWA PESANNYA. Dahulu penyedia Meta menyimpan SATU
 * nama template untuk seluruh sistem — template konfirmasi supir. Begitu MRF
 * ikut memakai jalur yang sama, atasan yang dimintai persetujuan akan
 * menerima pesan berbunyi "konfirmasi pengiriman" berisi tautan persetujuan.
 * Jenis pesan menentukan templatenya; penyedia hanya menerjemahkan nama itu
 * ke nama yang disetujui Meta lewat config/services.php.
 *
 * URUTAN VARIABEL ADALAH KONTRAK dengan template yang didaftarkan di Meta:
 * {{1}}, {{2}}, dst. Mengubah urutannya di sini tanpa mengubah templatenya di
 * Meta menghasilkan pesan yang menyebut nama pelanggan di tempat nomor PO —
 * dan Meta tidak akan menolaknya, karena bagi Meta keduanya sama-sama teks.
 */
final readonly class PesanWhatsApp
{
    /** Tautan konfirmasi sampai untuk supir. Variabel: [tautan]. */
    public const TEMPLATE_KONFIRMASI_SUPIR = 'konfirmasi_pengiriman';

    /** Permintaan persetujuan MRF. Variabel: [nama atasan, nomor MRF, pemohon, tautan]. */
    public const TEMPLATE_PERSETUJUAN_MRF = 'persetujuan_mrf';

    /**
     * Kabar barang sampai untuk Sales.
     * Variabel: [nama sales, nomor PO, nama customer, nomor Surat Jalan, tautan].
     */
    public const TEMPLATE_BARANG_SAMPAI = 'barang_sampai_sales';

    /**
     * @param  list<string>  $variabel
     */
    public function __construct(
        public string $teks,
        public string $template,
        public array $variabel = [],
    ) {}
}
