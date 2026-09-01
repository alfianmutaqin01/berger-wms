<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dua penanda waktu pengiriman pada pesanan.
 *
 * Diminta pemilik produk supaya stepper di Portal Sales memuat tahap
 * "Dikirim" dan "Tiba" — sebelumnya pesanan melompat dari "Dikemas"
 * langsung ke "Selesai", sehingga Sales tidak tahu barangnya sudah
 * berangkat atau belum saat pelanggan bertanya.
 *
 * KEDUANYA BARU DIISI FASE 6 (Surat Jalan dan konfirmasi driver).
 * Ditambahkan sekarang karena stepper-nya sudah harus menampilkan tahap
 * itu hari ini — tanpa kolomnya, tahap tersebut hanya bisa ditebak dari
 * status, dan status tidak menyimpan KAPAN perpindahannya terjadi.
 *
 * Tidak ditaruh di delivery_notes (Fase 6) karena "kapan pesanan ini
 * berangkat" adalah sifat pesanannya, dan stepper harus tetap bisa
 * digambar walau surat jalannya kelak dicetak ulang atau dibatalkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            // Saat Surat Jalan dicetak dan barang meninggalkan gudang.
            $table->timestamp('shipped_at')->nullable()->after('picking_completed_at');

            // Saat driver menekan tautan konfirmasi "barang sudah sampai".
            $table->timestamp('delivered_at')->nullable()->after('shipped_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['shipped_at', 'delivered_at']);
        });
    }
};
