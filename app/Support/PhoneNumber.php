<?php

namespace App\Support;

/**
 * Normalisasi nomor telepon dari ekspor ERP Berger.
 *
 * MENGAPA KELAS INI ADA
 * ---------------------
 * Sebelumnya normalisasi ditulis tiga kali dengan cara yang sama-sama keliru
 * — di CustomerImporter, di StoreCustomerRequest, dan di accessor
 * Customer::phone_label — yaitu `preg_replace('/\D/', '', $nomor)`.
 *
 * Membuang SEMUA karakter bukan angka bekerja untuk satu nomor, tetapi
 * merusak sel yang berisi dua nomor. Sel nyata dari ERP:
 *
 *     6285775005758/6282233024171
 *
 * menjadi satu untai 26 digit "62857750057586282233024171" — bukan nomor
 * telepon siapa pun, dan kebetulan juga melampaui panjang kolom sehingga
 * impor 1.863 baris berhenti di baris ke-1.731.
 *
 * Yang dibedakan di sini: tanda pemisah ANTAR-nomor (garis miring, koma,
 * titik koma, pipa, ganti baris) dan tanda hias DI DALAM satu nomor (spasi,
 * strip, kurung, plus). Yang pertama memisah, yang kedua dibuang. Spasi
 * sengaja TIDAK dianggap pemisah: "62 895 3143 5435" adalah satu nomor yang
 * ditulis berspasi, jauh lebih lazim daripada dua nomor tanpa tanda baca.
 */
final class PhoneNumber
{
    /** Tanda baca yang memisahkan satu nomor dari nomor berikutnya. */
    private const PEMISAH = '~[/,;|\r\n]+~';

    /** Pemisah yang dipakai saat menyimpan, agar bisa dibaca ulang oleh normalize(). */
    private const PERANGKAI = ' / ';

    /**
     * Bentuk simpan: hanya angka, beberapa nomor dirangkai " / ".
     *
     * "6285775005758/6282233024171" -> "6285775005758 / 6282233024171"
     * "62 895 3143 5435"            -> "6289531435435"
     */
    public static function normalize(?string $raw): ?string
    {
        $bagian = self::parts($raw);

        return $bagian === [] ? null : implode(self::PERANGKAI, $bagian);
    }

    /**
     * Bentuk tampil: tiap nomor diberi awalan "+" bila memakai kode negara.
     *
     * Data ERP menyimpan kode negara tanpa tanda plus ("6289531435435"),
     * sehingga di layar ditambahkan sendiri.
     */
    public static function label(?string $raw): string
    {
        $bagian = array_map(
            fn (string $nomor) => str_starts_with($nomor, '62') ? '+'.$nomor : $nomor,
            self::parts($raw)
        );

        return $bagian === [] ? '—' : implode(self::PERANGKAI, $bagian);
    }

    /**
     * Bentuk kirim WhatsApp: SATU nomor, berkode negara, tanpa tanda plus.
     *
     * Berbeda dari normalize(), yang hanya membuang hiasan dan MEMBIARKAN
     * "081234567890" apa adanya karena itulah bentuk yang datang dari ERP.
     * WhatsApp tidak mengenal awalan nol nasional: mengirim ke "0812..." bukan
     * gagal dengan galat, melainkan diterima sebagai nomor negara lain atau
     * nomor yang tidak ada — dan kegagalan seperti itu tidak berbunyi.
     *
     *   "0812 3456 7890"  -> "6281234567890"
     *   "+62 812-3456-7890" -> "6281234567890"
     *   "6281234567890"   -> "6281234567890"
     *
     * Mengembalikan NULL bila selnya memuat lebih dari satu nomor: pesan
     * hanya bisa dikirim ke satu tujuan, dan menebak mana yang dimaksud lebih
     * buruk daripada menolaknya.
     */
    public static function forWhatsApp(?string $raw): ?string
    {
        $bagian = self::parts($raw);

        if (count($bagian) !== 1) {
            return null;
        }

        $nomor = $bagian[0];

        if (str_starts_with($nomor, '0')) {
            return '62'.ltrim($nomor, '0');
        }

        // Nomor yang ditulis tanpa awalan apa pun ("81234567890") tetap
        // dianggap Indonesia: itu bentuk yang lazim diketik orang di sini.
        if (str_starts_with($nomor, '62')) {
            return $nomor;
        }

        return '62'.$nomor;
    }

    /**
     * Memecah sel menjadi daftar nomor berisi angka saja, tanpa kembar.
     *
     * @return list<string>
     */
    public static function parts(?string $raw): array
    {
        if (blank($raw)) {
            return [];
        }

        $bagian = preg_split(self::PEMISAH, $raw) ?: [];

        $bagian = array_map(fn ($nomor) => preg_replace('/\D/', '', (string) $nomor), $bagian);

        return array_values(array_unique(array_filter($bagian, fn ($nomor) => $nomor !== '')));
    }
}
