<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda batch "Formula Lama" diganti jadi "Masalah Kualitas" —
 * permintaan pemilik produk.
 *
 * KOLOMNYA DIGANTI NAMA, BUKAN DITAMBAH BARU. Penandanya sudah dipakai di
 * data nyata; menambah kolom baru dan meninggalkan yang lama akan membuat
 * dua sumber kebenaran untuk satu penanda yang sama, dan batch yang sudah
 * ditandai akan kehilangan tandanya di layar. Rename memindahkan seluruh
 * riwayat penandaan apa adanya.
 *
 * ARTINYA BERUBAH, PERILAKUNYA TIDAK. Sama seperti Formula Lama, penanda ini
 * MURNI INFORMASI: batch tetap 'active', tetap ikut FIFO, tetap boleh dijual.
 * Alat untuk benar-benar MENAHAN batch sudah ada dan sengaja tidak diubah —
 * KARANTINA (tahan sementara, lepas sendiri) dan DDP (tahan permanen sampai
 * dikeluarkan Manager). Kalau batch bermasalah tidak boleh keluar gudang,
 * yang dipakai adalah salah satu dari keduanya, bukan penanda ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->renameColumn('is_old_formula', 'has_quality_issue');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->renameColumn('has_quality_issue', 'is_old_formula');
        });
    }
};
