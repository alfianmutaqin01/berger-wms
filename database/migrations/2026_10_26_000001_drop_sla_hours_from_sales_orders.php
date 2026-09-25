<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menghapus sla_hours — keputusan pemilik produk, 25 September 2026.
 *
 * KENAPA DIHAPUS. Angka ini tidak pernah diminta. Ia masuk lewat PRD §7.6
 * sebagai "Aturan SLA" dan diwujudkan sejak migrasi sales_orders yang paling
 * awal, tetapi tidak ada seorang pun yang memakainya untuk memutuskan apa pun.
 * Fitur yang tidak diminta dan tidak dibaca lebih baik tidak ada: ia tetap
 * menuntut perawatan, tetap ikut dalam setiap keputusan rancangan, dan tetap
 * bisa keliru — dan yang paling mahal, angka yang salah TETAP dipercaya orang
 * yang kebetulan membacanya.
 *
 * Yang membuatnya ketahuan: begitu pengiriman antarpulau ada, satu kiriman
 * Medan menyumbang ~336 jam ke rata-rata yang bercampur dengan kiriman lokal
 * ~8 jam. Saat mencari cara membereskannya, pertanyaan yang lebih mendasar
 * muncul lebih dulu — untuk apa angkanya.
 *
 * TIDAK ADA DATA YANG HILANG, dan ini yang membuat penghapusan aman. Kolom ini
 * bukan sumber; ia hasil hitungan dari kolom lain yang semuanya TETAP ADA:
 * submitted_at, approved_at, picking_completed_at, shipped_at, delivered_at,
 * completed_at. Kalau suatu hari ukuran waktu memang dibutuhkan, ia bisa
 * dihitung ulang untuk SELURUH riwayat, termasuk pesanan lama — bahkan dengan
 * rumus yang berbeda dari yang dipakai dulu.
 *
 * LINIMASA PESANAN TIDAK IKUT TERSENTUH. Tampilan tahapan di Portal Sales
 * (Dibuat - Diterima - Dikemas - Dikirim - Tiba - Selesai) dibangun dari keenam
 * timestamp di atas, bukan dari kolom ini. Lihat SalesOrderController::linimasa().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('sla_hours');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            // Kolomnya kembali kosong, dan memang tidak bisa lain: nilainya
            // dulu dihitung saat pesanan ditutup. Yang membutuhkannya harus
            // menghitung ulang dari timestamp yang masih utuh.
            $table->decimal('sla_hours', 8, 2)->nullable()->after('completed_by');
        });
    }
};
