<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dasar persetujuan pengesahan stocktake — permintaan pemilik produk,
 * 23 Oktober 2026.
 *
 * KENAPA BERKASNYA WAJIB
 * ----------------------
 * Pengesahan stocktake adalah satu-satunya tombol di sistem ini yang bisa
 * menghapus ribuan unit stok sekaligus, dan tidak ada tombol untuk menariknya
 * kembali. Sebelum ini, yang tertinggal sesudahnya hanya nama penekan tombol
 * dan waktunya — bukan dasar kenapa selisih sebesar itu boleh diterima.
 * Berita acara yang ditandatangani hidup di kertas, di luar sistem, dan justru
 * ia yang dicari saat audit mempertanyakan selisihnya berbulan-bulan kemudian.
 *
 * KENAPA DI TABEL SESI, BUKAN TABEL SENDIRI
 * -----------------------------------------
 * Satu sesi punya satu dasar persetujuan, dan berkas itu lahir bersamaan
 * dengan pengesahannya — tidak ada daftar yang bertambah, tidak ada status
 * yang berpindah, tidak ada yang diperiksa ulang. Tabel terpisah hanya akan
 * menambah sambungan tanpa menyimpan satu fakta pun yang tidak muat di sini.
 * Bandingkan dengan `delivery_proofs`, yang memang butuh tabel sendiri karena
 * fotonya banyak, punya status, dan bisa ditolak.
 *
 * Kolomnya nullable demi sesi yang sudah telanjur disahkan sebelum aturan ini
 * ada. Yang menegakkan kewajiban adalah `StockTakeRun::finalize()`, bukan
 * skema — dan itu disengaja: aturannya berlaku pada perbuatan mengesahkan,
 * bukan pada baris yang sudah lama tersimpan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_takes', function (Blueprint $table) {
            $table->string('approval_doc_path', 255)->nullable()->after('finalized_by');
            $table->string('approval_doc_name', 255)->nullable()->after('approval_doc_path');
            $table->string('approval_doc_mime', 100)->nullable()->after('approval_doc_name');
            $table->unsignedInteger('approval_doc_size')->nullable()->after('approval_doc_mime');
        });
    }

    public function down(): void
    {
        Schema::table('stock_takes', function (Blueprint $table) {
            $table->dropColumn([
                'approval_doc_path', 'approval_doc_name',
                'approval_doc_mime', 'approval_doc_size',
            ]);
        });
    }
};
