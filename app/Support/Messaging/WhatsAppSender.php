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
 *   manual : sistem menyiapkan pesan + tautan wa.me, Logistik yang menekan
 *            kirim. Tanpa langganan, tanpa risiko, bisa dipakai hari ini.
 *   cloud  : WhatsApp Cloud API resmi Meta. Otomatis penuh, berbiaya per
 *            pesan, butuh verifikasi bisnis dan template yang disetujui.
 *   log    : mencatat ke log, dipakai pengembangan dan test.
 *
 * KENAPA MODE MANUAL YANG JADI BAWAAN. Nomor tujuan di sini adalah supir
 * pihak ketiga yang BERGANTI SETIAP HARI. Bagi gateway tidak resmi, mengirim
 * ke nomor yang selalu baru tanpa percakapan sebelumnya adalah pola yang
 * paling cepat dianggap spam — dan yang hilang saat nomor diblokir bukan
 * fitur ini, melainkan nomor WhatsApp perusahaan beserta seluruh riwayatnya.
 * Jalur resmi Meta menghindari itu, tetapi verifikasinya hitungan minggu dan
 * pengiriman barang tidak boleh menunggu.
 */
interface WhatsAppSender
{
    /**
     * @param  string  $phone  Nomor ternormalisasi, hanya angka, berawalan 62.
     */
    public function send(string $phone, string $message): DispatchResult;
}
