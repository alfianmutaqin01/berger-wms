<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat penolakan pesanan — supaya pesanan yang ditolak bisa DIPERBAIKI,
 * bukan diulang dari nol.
 *
 * KEADAAN SEBELUM INI
 * -------------------
 * Penolakan adalah jalan buntu. Pesanan 50 baris yang ditolak karena satu
 * item keliru memaksa Sales mengetik ulang seluruhnya sebagai pesanan baru —
 * dan pesanan barunya tidak punya hubungan apa pun dengan yang ditolak,
 * sehingga Logistik tidak pernah tahu ini pengajuan kedua atas hal yang sama.
 *
 * DUA HAL YANG HARUS BERJALAN BERSAMA, DAN ITULAH SEBABNYA TABEL INI ADA
 * ---------------------------------------------------------------------
 *   1. Pesanan yang ditolak boleh diperbaiki lalu diajukan lagi. Begitu
 *      diajukan lagi, kolom penolakan di `sales_orders` DIBERSIHKAN — pesanan
 *      itu sedang menunggu, bukan sedang ditolak, dan keadaan sekarangnya
 *      harus jujur.
 *   2. Fakta bahwa ia pernah ditolak TIDAK BOLEH ikut hilang. Permintaan
 *      pemilik produk: catatan itu melekat "sampai akhir", termasuk sesudah
 *      pesanannya akhirnya diterima dan selesai.
 *
 * Keduanya hanya bisa hidup bersama kalau riwayatnya dipisahkan dari keadaan
 * sekarang — pola yang sama dengan sales_order_cancellations, dan karena
 * alasan yang sama persis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_rejections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();

            $table->text('reason');

            // Pengajuan KEBERAPA yang ditolak. Disimpan, bukan dihitung dari
            // jumlah baris saat ditampilkan: barisnya bisa saja terhapus
            // bersama pesanannya, dan nomor pengajuan yang bergeser sendiri
            // membuat "ditolak pada pengajuan ke-2" berubah arti belakangan.
            $table->unsignedSmallInteger('attempt_no');

            // Cuplikan pengajuan yang ditolak, sebelum kolomnya ditimpa
            // pengajuan berikutnya.
            $table->timestamp('submitted_at')->nullable();

            $table->timestamp('rejected_at');
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('sales_order_id');
        });

        /*
         * Menarik ke belakang penolakan yang sudah telanjur ada.
         *
         * Tanpa ini, pesanan yang sedang berstatus ditolak hari ini kehilangan
         * jejaknya begitu Sales memperbaikinya — persis masalah yang tabel ini
         * ada untuk mencegah, hanya sekali saja pada baris lama.
         */
        DB::statement("
            INSERT INTO sales_order_rejections
                (sales_order_id, reason, attempt_no, submitted_at, rejected_at, rejected_by, created_at, updated_at)
            SELECT o.id,
                   COALESCE(o.rejection_reason, 'Alasan tidak tercatat (data sebelum riwayat penolakan dipisahkan).'),
                   1, o.submitted_at, o.rejected_at, o.rejected_by, o.rejected_at, NOW()
            FROM sales_orders o
            WHERE o.rejected_at IS NOT NULL AND o.deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_rejections');
    }
};
