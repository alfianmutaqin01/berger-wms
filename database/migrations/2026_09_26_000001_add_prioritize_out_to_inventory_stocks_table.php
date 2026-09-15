<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda batch KETIGA: "Dahulukan Keluar" — permintaan pemilik produk.
 *
 * KEBALIKAN KARANTINA. Karantina berkata "batch ini jangan keluar dulu";
 * penanda ini berkata "batch ini keluar duluan, walau ada yang lebih tua".
 * Kasus nyatanya: B01 dan B05 sama-sama di rak, FIFO akan mengambil B01,
 * tetapi B05-lah yang harus dikirim.
 *
 * SATU-SATUNYA PENANDA YANG MENGUBAH URUTAN ALOKASI. Bandingkan dengan dua
 * penanda batch yang sudah ada:
 *
 *   MASALAH KUALITAS — murni informasi, tidak menyentuh urutan sama sekali.
 *   KARANTINA        — mengeluarkan batch dari pencalonan (lewat `status`).
 *   DAHULUKAN KELUAR — batch tetap dicalonkan, hanya NAIK KE DEPAN antrean.
 *
 * Karena itu ia flag terpisah, bukan status keempat: batchnya tetap 'active'
 * dan tetap harus lolos semua syarat kelayakan jual yang sudah ada. Yang
 * berubah cuma posisinya dalam antrean.
 *
 * ALASAN WAJIB. Melanggar FIFO adalah hal yang akan ditanyakan orang — kenapa
 * batch baru keluar duluan sementara yang lama menua di rak. Tanpa alasan
 * tertulis, enam bulan kemudian tidak ada yang bisa menjawab, dan penanda
 * yang dimaksudkan sementara berubah jadi keadaan permanen tanpa pemilik.
 * Constraint di bawah yang menegakkannya, bukan sekadar validasi form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stocks', function (Blueprint $table) {
            // Batch, bukan baris — sama seperti karantina & quality issue.
            // Satu batch bisa terpisah di beberapa rak; mendahulukan satu rak
            // saja akan membuat sisa batch yang sama tetap mengantre di
            // belakang, dan "B05 keluar duluan" hanya benar separuh.
            $table->boolean('prioritize_out')->default(false)->after('has_quality_issue');
            $table->text('prioritize_reason')->nullable()->after('prioritize_out');
            $table->timestamp('prioritized_at')->nullable()->after('prioritize_reason');
            $table->foreignId('prioritized_by')->nullable()->after('prioritized_at')
                ->constrained('users')->nullOnDelete();

            // Diisi saat penanda dilepas — manual maupun oleh sweep karena
            // batchnya habis. Kolom yang lain SENGAJA tidak dikosongkan: itu
            // jejak "batch ini pernah didahulukan, oleh siapa, alasannya apa",
            // yang hilang kalau ditimpa null.
            $table->timestamp('prioritize_released_at')->nullable()->after('prioritized_by');

            // Dipakai setiap kali batch dicalonkan untuk keluar (tiga jalur:
            // alokasi pesanan, pencadangan booking, pengeluaran saat kirim).
            // Parsial: yang tidak ditandai jumlahnya jauh lebih banyak dan
            // tidak pernah dicari lewat kolom ini.
            $table->index(['product_id', 'warehouse_id', 'prioritize_out']);
        });

        // Penanda menyala WAJIB lengkap alasan & jejak siapa. Begitu dilepas,
        // prioritize_out kembali false dan constraint tidak lagi memeriksanya
        // — kolom historisnya boleh tetap terisi.
        DB::statement('
            ALTER TABLE inventory_stocks ADD CONSTRAINT inventory_stocks_prioritas_lengkap
            CHECK (
                prioritize_out = false
                OR (prioritize_reason IS NOT NULL AND prioritized_by IS NOT NULL
                    AND prioritized_at IS NOT NULL AND prioritize_released_at IS NULL)
            )
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inventory_stocks DROP CONSTRAINT IF EXISTS inventory_stocks_prioritas_lengkap');

        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'warehouse_id', 'prioritize_out']);
            $table->dropConstrainedForeignId('prioritized_by');
            $table->dropColumn([
                'prioritize_out', 'prioritize_reason', 'prioritized_at', 'prioritize_released_at',
            ]);
        });
    }
};
