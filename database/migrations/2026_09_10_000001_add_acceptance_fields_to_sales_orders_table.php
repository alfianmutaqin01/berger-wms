<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6 tahap 1 — penerimaan pesanan oleh Logistik.
 *
 * NOMOR SO WAJIB UNIK (keputusan pemilik produk). Nomor SO didapat dari
 * sistem BC saat pesanan dimasukkan ke sana; nomor yang terulang berarti
 * Logistik BELUM benar-benar memasukkannya dan sedang memakai ulang nomor
 * pesanan lain. Ditegakkan di DATABASE, bukan hanya di FormRequest: dua
 * Logistik yang menekan Terima pada detik yang sama sama-sama lolos
 * pemeriksaan "sudah dipakai belum" sebelum salah satunya menyimpan.
 *
 * Indeksnya PARSIAL (WHERE deleted_at IS NULL) supaya pesanan yang
 * dihapus-lunak tidak selamanya mengunci nomornya. NULL sendiri tidak
 * pernah bentrok di Postgres, jadi pesanan draft/menunggu yang belum punya
 * nomor SO tidak saling menghalangi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            // Catatan Logistik saat menerima — terutama alasan bila qty yang
            // disetujui melebihi stok yang tercatat sistem ("barang sudah di
            // gudang, belum di-putaway"). Dipisah dari rejection_reason
            // karena keduanya bisa terisi pada pesanan yang berbeda dan
            // menggabungkannya membuat riwayat ambigu.
            $table->text('approval_note')->nullable()->after('rejection_reason');
        });

        DB::statement('CREATE UNIQUE INDEX sales_orders_bc_so_number_unique
            ON sales_orders (bc_so_number) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sales_orders_bc_so_number_unique');

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('approval_note');
        });
    }
};
