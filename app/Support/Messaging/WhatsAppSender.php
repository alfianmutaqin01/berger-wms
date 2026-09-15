<?php

namespace App\Support\Messaging;

/**
 * Pengirim pesan WhatsApp — SATU antarmuka, beberapa penyedia.
 *
 * MENGAPA ANTARMUKA, BUKAN LANGSUNG MEMANGGIL PENYEDIA
 * ----------------------------------------------------
 * Pilihan penyedia WhatsApp bukan keputusan teknis melainkan keputusan
 * bisnis yang bisa berubah, dan perubahannya tidak boleh menyentuh alur
 * pengiriman barang sama sekali:
 *
 *   manual : sistem menyiapkan pesan + tautan wa.me, orang yang menekan
 *            kirim. Tanpa langganan, tanpa risiko, bisa dipakai hari ini.
 *   cloud  : WhatsApp Cloud API resmi Meta. Otomatis penuh, berbiaya per
 *            pesan, butuh verifikasi bisnis dan template yang disetujui.
 *   fonnte : gateway pihak ketiga lewat nomor WhatsApp biasa yang ditautkan.
 *            Otomatis dan bisa dipakai hari ini, tetapi melanggar ketentuan
 *            WhatsApp dan nomornya bisa diblokir.
 *   log    : mencatat ke log, dipakai pengembangan dan test.
 *
 * KENAPA MODE MANUAL YANG JADI BAWAAN. Nomor tujuan pesan supir adalah supir
 * pihak ketiga yang BERGANTI SETIAP HARI. Bagi gateway tidak resmi, mengirim
 * ke nomor yang selalu baru tanpa percakapan sebelumnya adalah pola yang
 * paling cepat dianggap spam — dan yang hilang saat nomor diblokir bukan
 * fitur ini, melainkan nomor WhatsApp perusahaan beserta seluruh riwayatnya.
 *
 * YANG TIDAK BISA MANUAL. Kabar barang sampai untuk Sales dipicu supir di
 * halaman publik — tidak ada orang di sisi perusahaan yang bisa menekan
 * tombol wa.me. Dalam mode manual pesan itu tercatat `manual` dan tidak
 * pernah keluar; lonceng web tetap berbunyi sebagai jalur cadangannya.
 */
interface WhatsAppSender
{
    /**
     * @param  string  $phone  Nomor ternormalisasi, hanya angka, berawalan 62.
     */
    public function send(string $phone, PesanWhatsApp $pesan): DispatchResult;
}
