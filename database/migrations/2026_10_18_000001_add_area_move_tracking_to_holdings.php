<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Siapa yang memindahkan material Produksi, dan kapan.
 *
 * Produksi bukan satu orang. Yang menerima material, yang memindahkannya ke
 * lantai kerja, dan yang mencatat pemakaiannya sering tiga orang berbeda —
 * dan pertanyaan yang muncul berbulan-bulan kemudian selalu berbentuk "siapa
 * yang memegang ini terakhir". Pemohon dan pencatat pemakaian sudah tercatat;
 * yang memindahkan belum.
 *
 * Kolom, bukan tabel riwayat: yang ditanyakan adalah keadaan terakhir sebuah
 * baris material, dan tiap perpindahan sudah punya barisnya sendiri di log
 * aktivitas (ActivityLog::MRF_MOVE) untuk yang perlu menelusuri urutannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_material_holdings', function (Blueprint $table) {
            $table->timestamp('area_moved_at')->nullable()->after('production_area');
            $table->foreignId('area_moved_by')->nullable()->after('area_moved_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_material_holdings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_moved_by');
            $table->dropColumn('area_moved_at');
        });
    }
};
