<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Siapa yang BENAR-BENAR mengetik pesanannya, saat itu bukan Sales-nya.
 *
 * KENAPA KOLOM BARU, BUKAN MENIMPA user_id
 * ----------------------------------------
 * Admin/Manager kini bisa membuat pesanan atas nama seorang Sales. Godaan
 * termudahnya adalah mengisi `user_id` dengan Sales itu lalu selesai — dan
 * hasilnya catatan yang BERBOHONG: setiap layar, setiap laporan, dan setiap
 * penelusuran sengketa akan menyebut Sales membuat pesanan yang tidak pernah
 * ia sentuh.
 *
 * Karena itu keduanya disimpan terpisah dan artinya dijaga tetap tajam:
 *
 *   user_id   = pesanan ini MILIK siapa. Sales yang menanggungnya, yang
 *               melihatnya di daftarnya, yang mengunggah bukti Surat Jalan,
 *               dan yang angkanya masuk laporan Kinerja Sales.
 *   placed_by = siapa yang MENGETIKNYA, kalau bukan Sales itu sendiri.
 *               NULL berarti Sales membuatnya sendiri — keadaan normal, dan
 *               NULL-nya bermakna "tidak ada yang mewakili", bukan "belum
 *               diisi".
 *
 * KENAPA INI PENTING JUSTRU SEKARANG. Pemilik produk memutuskan pembuat
 * pesanan BOLEH menyetujui pesanannya sendiri. Dengan begitu pemisahan
 * pembuat–penyetuju tidak lagi menjaga apa pun, dan yang tersisa sebagai
 * kontrol hanyalah jejak: kolom inilah yang membuat "dibuat dan diloloskan
 * orang yang sama" bisa terbaca sesudahnya. Tanpanya, tidak ada satu pun
 * cara mengetahuinya.
 *
 * ON DELETE SET NULL, sama seperti approved_by dan cancelled_by: akun yang
 * dihapus tidak boleh menyeret pesanannya ikut hilang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('placed_by')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();

            // ALASANNYA MENEMPEL DI PESANAN, bukan cuma di activity_logs.
            // Log aktivitas hanya bisa dibuka Super Admin, sementara yang
            // paling perlu membaca alasan ini adalah Logistik yang sedang
            // memutuskan menerima pesanannya. Menyimpannya di tempat yang
            // tidak bisa mereka buka sama saja dengan tidak menyimpannya.
            $table->string('placed_reason', 500)->nullable()->after('placed_by');
        });

        // Mewakili DIRI SENDIRI tidak berarti apa-apa dan hanya membuat layar
        // menulis "dibuat Budi atas nama Budi". Yang seperti itu ditulis
        // sebagai NULL, dan basis data yang menegakkannya — bukan kesopanan
        // pemanggilnya.
        DB::statement(<<<'SQL'
            ALTER TABLE sales_orders
            ADD CONSTRAINT sales_orders_placed_by_bukan_diri_sendiri
            CHECK (placed_by IS NULL OR placed_by <> user_id)
        SQL);

        // Keduanya hidup dan mati bersama. Pesanan yang punya pewakil tetapi
        // tanpa alasan adalah persis keadaan yang aturan "alasan wajib" ada
        // untuk mencegah — dan kalau hanya PHP yang menjaganya, satu jalur
        // baru yang lupa memanggilnya sudah cukup untuk melewatinya diam-diam.
        DB::statement(<<<'SQL'
            ALTER TABLE sales_orders
            ADD CONSTRAINT sales_orders_placed_lengkap
            CHECK (
                (placed_by IS NULL AND placed_reason IS NULL)
                OR (placed_by IS NOT NULL AND placed_reason IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        foreach ([
            'sales_orders_placed_lengkap',
            'sales_orders_placed_by_bukan_diri_sendiri',
        ] as $constraint) {
            DB::statement("ALTER TABLE sales_orders DROP CONSTRAINT IF EXISTS {$constraint}");
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('placed_reason');
            $table->dropConstrainedForeignId('placed_by');
        });
    }
};
