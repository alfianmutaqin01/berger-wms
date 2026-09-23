<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jenis permintaan "Lain-lain" — permintaan pemilik produk, 23 Oktober 2026.
 *
 * Keempat jenis awal ternyata tidak memuat semua alasan barang keluar dari
 * gudang untuk produksi, dan yang tidak masuk kategori terpaksa dipaksakan ke
 * jenis terdekat. Jenis yang dipaksakan lebih buruk daripada jenis "Lain-lain"
 * yang jujur: laporan per jenis jadi salah, dan alasan sebenarnya hanya
 * tertinggal di kolom Keperluan yang tidak pernah dijumlahkan.
 *
 * Batasannya dibuat ulang, bukan dihapus. Tanpa batasan, salah ketik satu kali
 * di kode mana pun akan melahirkan jenis baru yang tak seorang pun mendaftarkan
 * — dan layar Daftar MRF menyaring per jenis.
 */
return new class extends Migration
{
    private const NAMA = 'material_requisitions_jenis_dikenal';

    public function up(): void
    {
        DB::statement('ALTER TABLE material_requisitions DROP CONSTRAINT IF EXISTS '.self::NAMA);

        DB::statement('
            ALTER TABLE material_requisitions
            ADD CONSTRAINT '.self::NAMA."
            CHECK (request_type IN (
                'reproses_tinting', 'testing_investigation',
                'replacement', 'sample_material', 'lainnya'
            ))
        ");
    }

    public function down(): void
    {
        // Permintaan yang terlanjur memakai jenis baru harus punya tempat
        // sebelum batasan lama dipasang lagi; tanpa ini, turun versi gagal di
        // tengah jalan dan meninggalkan tabel tanpa batasan sama sekali.
        DB::table('material_requisitions')
            ->where('request_type', 'lainnya')
            ->update(['request_type' => 'testing_investigation']);

        DB::statement('ALTER TABLE material_requisitions DROP CONSTRAINT IF EXISTS '.self::NAMA);

        DB::statement('
            ALTER TABLE material_requisitions
            ADD CONSTRAINT '.self::NAMA."
            CHECK (request_type IN (
                'reproses_tinting', 'testing_investigation',
                'replacement', 'sample_material'
            ))
        ");
    }
};
