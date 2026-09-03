<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pembatalan pesanan yang sudah diterima + penggabungan invoice.
 *
 * MENGAPA ATURAN LAMA KELIRU
 * --------------------------
 * Indeks unik `bc_so_number` yang dipasang di Fase 6 tahap 1 memperlakukan
 * nomor SO sebagai unik SELAMANYA. Di sistem BC ia hanya unik selama
 * pesanannya masih hidup, dan ada dua cara nomor itu kembali bisa dipakai:
 *
 *   1. PESANANNYA BATAL — customer membatalkan, atau BC tidak menyetujui.
 *      Nomor SO-nya dipakai ulang untuk pesanan berikutnya yang berhasil,
 *      supaya tidak ada nomor yang terbuang.
 *   2. SATU INVOICE, DUA PESANAN — customer menambah pesanan di hari
 *      berikutnya dan minta digabung ke tagihan yang sama, sehingga nomor SO
 *      yang sudah ada dipakai lagi untuk pesanan tambahannya.
 *
 * ATURAN UNIKNYA TIDAK DICABUT, hanya diberi dua pintu keluar. Alasannya
 * masih berlaku: nomor SO yang berulang PADA UMUMNYA berarti Logistik belum
 * benar-benar memasukkan pesanan ke BC. Kalau dibuka begitu saja, kesalahan
 * itu tidak akan pernah ketahuan lagi dari sistem ini.
 *
 *   Pintu 1 — pembatalan MENGOSONGKAN bc_so_number. Nomor itu tidak lagi
 *             dipegang siapa pun, jadi indeksnya melepasnya dengan
 *             sendirinya tanpa perlu pengecualian apa pun.
 *   Pintu 2 — pesanan tambahan menunjuk induknya lewat so_merged_into_id,
 *             dan indeksnya dipersempit menjadi "hanya induk yang memegang
 *             nomor". Syaratnya PELANGGAN HARUS SAMA — itulah yang
 *             membedakan penggabungan yang sah dari kekeliruan input, dan
 *             ditegakkan di aplikasi karena butuh membandingkan dua baris.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            // --- Pembatalan setelah diterima ---
            // Disimpan di pesanan sebagai KEADAAN SEKARANG, dan dibersihkan
            // bila pesanannya diterima lagi. Riwayat lengkapnya ada di tabel
            // sales_order_cancellations yang tidak pernah dibersihkan.
            $table->timestamp('cancelled_at')->nullable()->after('rejected_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('users')->nullOnDelete();
            // 'customer' | 'bc' | 'internal'
            $table->string('cancellation_source', 20)->nullable()->after('cancelled_by');
            $table->text('cancellation_reason')->nullable()->after('cancellation_source');

            // --- Penggabungan invoice ---
            // Pesanan tambahan yang berbagi satu nomor SO dengan induknya.
            // Sengaja DATAR, bukan berantai: anak selalu menunjuk induk yang
            // memegang nomornya, sehingga "siapa pemegang nomor ini" cukup
            // satu langkah dan tidak pernah berputar.
            $table->foreignId('so_merged_into_id')->nullable()->after('bc_so_number')
                ->constrained('sales_orders')->nullOnDelete();
        });

        DB::statement("ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_cancellation_source_valid
            CHECK (cancellation_source IS NULL OR cancellation_source IN ('customer', 'bc', 'internal'))");

        // Pesanan tidak boleh menjadi induk bagi dirinya sendiri.
        DB::statement('ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_merge_not_self
            CHECK (so_merged_into_id IS NULL OR so_merged_into_id <> id)');

        // Indeks unik DIPERSEMPIT: hanya pesanan INDUK yang memegang nomor SO
        // secara eksklusif. Pesanan tambahan (so_merged_into_id terisi)
        // dikecualikan, karena memang sengaja berbagi nomor yang sama.
        DB::statement('DROP INDEX IF EXISTS sales_orders_bc_so_number_unique');
        DB::statement('CREATE UNIQUE INDEX sales_orders_bc_so_number_unique
            ON sales_orders (bc_so_number)
            WHERE deleted_at IS NULL AND so_merged_into_id IS NULL');

        /**
         * Riwayat pembatalan — TIDAK PERNAH dibersihkan.
         *
         * Terpisah dari kolom di sales_orders karena pesanan yang dibatalkan
         * KEMBALI KE ANTREAN dan bisa diterima lagi; begitu itu terjadi,
         * kolom pembatalan di pesanannya dikosongkan. Tanpa tabel ini, fakta
         * bahwa nomor SO tertentu pernah dipakai lalu dilepas akan hilang —
         * padahal justru itu yang perlu ditelusuri ketika BC dan WMS berbeda
         * angka.
         */
        Schema::create('sales_order_cancellations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();

            // Nomor SO yang DILEPASKAN. Disalin ke sini karena kolomnya di
            // pesanan sudah dikosongkan supaya bisa dipakai ulang.
            $table->string('bc_so_number', 50)->nullable();

            $table->string('source', 20);
            $table->text('reason');

            // Berapa unit yang dikembalikan ke stok, untuk penelusuran cepat
            // tanpa harus memutar ulang ledger.
            $table->unsignedInteger('qty_released')->default(0);

            // Cuplikan penerimaan yang dibatalkan — supaya riwayatnya tetap
            // bisa menjawab "diterima kapan, oleh siapa" walau kolom aslinya
            // sudah ditimpa penerimaan berikutnya.
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('sales_order_id');
            $table->index('bc_so_number');
        });

        DB::statement("ALTER TABLE sales_order_cancellations ADD CONSTRAINT sales_order_cancellations_source_valid
            CHECK (source IN ('customer', 'bc', 'internal'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_cancellations');

        DB::statement('DROP INDEX IF EXISTS sales_orders_bc_so_number_unique');
        DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT IF EXISTS sales_orders_cancellation_source_valid');
        DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT IF EXISTS sales_orders_merge_not_self');

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('so_merged_into_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_source', 'cancellation_reason']);
        });

        DB::statement('CREATE UNIQUE INDEX sales_orders_bc_so_number_unique
            ON sales_orders (bc_so_number) WHERE deleted_at IS NULL');
    }
};
