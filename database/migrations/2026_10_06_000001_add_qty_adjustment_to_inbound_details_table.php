<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penyesuaian qty oleh Tim Produksi setelah Operator menghitung fisik.
 *
 * MASALAHNYA
 * ----------
 * Operator boleh mengoreksi Qty Aktual saat put-away (PRD §6.3 F-INB-02), dan
 * selisihnya ditandai untuk verifikasi Logistik. Tetapi Tim Produksi — yang
 * mengetik angka aslinya — tidak pernah diberi tahu sama sekali. Dokumen yang
 * mereka unggah salah, barangnya sudah naik rak, dan mereka baru tahu kalau
 * kebetulan membuka riwayat dan membandingkan sendiri kolom demi kolom.
 *
 * KENAPA ANGKA SEMULA DISIMPAN, BUKAN DITIMPA BEGITU SAJA
 * ------------------------------------------------------
 * Tombol "Sesuaikan" mengubah pallet_qty menjadi hasil hitung fisik. Kalau
 * angka semula ikut hilang, selisihnya juga hilang — dan selisih itulah satu-
 * satunya hal yang harus diperiksa Logistik sebelum stok diaktifkan. Tim
 * Produksi jadi bisa menghapus sendiri bukti kesalahannya, sebelum orang yang
 * bertugas memeriksanya sempat melihat.
 *
 * Karena itu pallet_qty_original menyimpan angka sebelum disesuaikan, dan
 * seluruh perhitungan "berselisih" membandingkan qty_actual dengan
 * COALESCE(pallet_qty_original, pallet_qty) — bukan dengan pallet_qty. Layar
 * verifikasi Logistik tetap menyala merah persis seperti sebelum disesuaikan.
 *
 * KEEMPAT KOLOM HIDUP-MATI BERSAMA
 * --------------------------------
 * Angka semula tanpa nama pelakunya adalah koreksi yang tidak bisa ditanyakan
 * kepada siapa pun. Nama pelaku tanpa alasan adalah baris yang tidak menjawab
 * "kenapa". CHECK di bawah memastikan tidak ada satu pun dari kombinasi itu
 * bisa tersimpan setengah — sekalipun ada kode baru yang lupa mengisinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_details', function (Blueprint $table) {
            $table->integer('pallet_qty_original')->nullable()->after('pallet_qty');
            $table->foreignId('qty_adjusted_by')->nullable()->after('qty_actual')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('qty_adjusted_at')->nullable()->after('qty_adjusted_by');
            $table->string('qty_adjust_reason', 500)->nullable()->after('qty_adjusted_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE inbound_details
            ADD CONSTRAINT inbound_details_penyesuaian_lengkap CHECK (
                (pallet_qty_original IS NULL
                    AND qty_adjusted_by IS NULL
                    AND qty_adjusted_at IS NULL
                    AND qty_adjust_reason IS NULL)
                OR
                (pallet_qty_original IS NOT NULL
                    AND qty_adjusted_by IS NOT NULL
                    AND qty_adjusted_at IS NOT NULL
                    AND qty_adjust_reason IS NOT NULL)
            )
        SQL);

        // Dipakai pemeriksaan duplikat: tiap berkas produksi yang diunggah
        // menanyakan "RMO + batch ini sudah pernah masuk belum" untuk SETIAP
        // barisnya. Tanpa indeks ini, berkas berisi 40 baris berarti 40 kali
        // memindai seluruh tabel palet.
        Schema::table('inbound_details', function (Blueprint $table) {
            $table->index(['production_order_no', 'batch_no'], 'inbound_details_rmo_batch_index');
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inbound_details DROP CONSTRAINT IF EXISTS inbound_details_penyesuaian_lengkap');

        Schema::table('inbound_details', function (Blueprint $table) {
            $table->dropIndex('inbound_details_rmo_batch_index');
            $table->dropConstrainedForeignId('qty_adjusted_by');
            $table->dropColumn(['pallet_qty_original', 'qty_adjusted_at', 'qty_adjust_reason']);
        });
    }
};
