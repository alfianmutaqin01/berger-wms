<?php

namespace App\Support\Import;

use RuntimeException;

/**
 * Ditolaknya SATU BARIS impor, bukan gagalnya seluruh berkas.
 *
 * Dipakai importer untuk menolak baris dari dalam persist() — titik di mana
 * keadaan database yang sebenarnya baru terlihat (mis. qty baru ternyata di
 * bawah jumlah yang sudah dialokasikan untuk pesanan). Pemeriksaan semacam
 * itu tidak selalu bisa dilakukan di mapRow(), yang sengaja tidak menyentuh
 * database.
 *
 * Importer::import() menangkapnya, mencatat pesannya sebagai kegagalan baris
 * itu, lalu MENERUSKAN sisa berkas. Ini bedanya dengan RuntimeException
 * biasa, yang naik sampai ImportController dan menghentikan seluruh impor —
 * meninggalkan data separuh jalan, persis kegagalan yang sudah kita bereskan
 * pada impor pelanggan.
 */
class RowRejected extends RuntimeException {}
