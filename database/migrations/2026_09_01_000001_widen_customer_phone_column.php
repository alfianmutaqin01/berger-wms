<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Melebarkan customers.phone dari 25 menjadi 50 karakter.
 *
 * MENGAPA
 * -------
 * Ekspor ERP memuat sel berisi DUA nomor sekaligus, dipisah garis miring:
 *
 *     6285775005758/6282233024171
 *
 * Keduanya nomor asli pelanggan dan keduanya perlu disimpan — memangkas
 * salah satunya adalah kehilangan data. Dalam bentuk simpan yang baru
 * ("6285775005758 / 6282233024171") panjangnya 29 karakter, melampaui batas
 * lama 25 sehingga impor berhenti dengan galat SQLSTATE 22001.
 *
 * 50 memberi ruang untuk tiga nomor. Lebih dari itu tidak lagi mematikan
 * impor: sejak Importer memeriksa panjang terhadap skema, kelebihan panjang
 * dilaporkan sebagai kegagalan baris yang bisa dibaca, bukan galat mentah.
 *
 * Tidak ada risiko pemangkasan saat naik-versi karena kolomnya melebar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE customers ALTER COLUMN phone TYPE VARCHAR(50)');
    }

    public function down(): void
    {
        // Menyempit kembali akan MEMOTONG nomor kedua pada baris yang sudah
        // memakainya, jadi baris itu dikosongkan lebih dulu agar kehilangan
        // datanya terjadi terang-terangan, bukan diam-diam terpotong.
        DB::statement('UPDATE customers SET phone = NULL WHERE LENGTH(phone) > 25');
        DB::statement('ALTER TABLE customers ALTER COLUMN phone TYPE VARCHAR(25)');
    }
};
