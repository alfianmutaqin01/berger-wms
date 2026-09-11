<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transfer antar gudang menempuh PICKING, bukan langsung berangkat.
 *
 * Permintaan pemilik produk: "alurnya sama dengan pemesanan sales — ketika
 * admin/manager mau transfer antar gudang dan data yang mau ditransfer sudah
 * ada maka akan masuk ke proses picking. Pada menu operator tidak hanya
 * mempicking nomer SO tapi juga mempicking transfer gudang. Saat operator klik
 * loading maka status berubah dalam pengiriman."
 *
 * APA YANG SALAH DENGAN ALUR LAMA
 * -------------------------------
 * Tombol Kirim mengurangi stok saat itu juga dan langsung menyatakan barangnya
 * DALAM PERJALANAN — padahal tidak ada seorang pun yang berjalan ke rak,
 * mengangkat barangnya, dan menaikkannya ke kendaraan. Angka di sistem
 * berangkat lebih dulu daripada barangnya. Kalau di rak ternyata kurang, tidak
 * ada satu langkah pun dalam alur itu yang bisa mengatakannya.
 *
 * KEADAAN BARU: MENUNGGU PICKING
 * ------------------------------
 * Di antara "dibuat" dan "dalam perjalanan" sekarang ada keadaan keempat.
 * Selama itu barangnya MASIH di gudang asal tetapi sudah DICADANGKAN —
 * qty_allocated, persis seperti pesanan pelanggan yang sudah disetujui. Ia
 * tidak bisa dijual dua kali, dan tidak berpura-pura sudah berangkat.
 *
 *   pending    -> dicadangkan di gudang asal, daftar picking-nya menunggu
 *   in_transit -> operator klik Loading; barang benar-benar turun dari rak
 *   received   -> logistik gudang tujuan memasukkannya ke rak mereka
 *   cancelled  -> dibatalkan; cadangannya dilepas (pending) atau barangnya
 *                 dikembalikan ke gudang asal (in_transit)
 *
 * DUA QTY, BUKAN SATU. `qty_requested` adalah yang DIMINTA Admin saat menyusun
 * transfer; `qty_shipped` adalah yang BENAR-BENAR turun dari rak, dan baru
 * terisi setelah operator selesai. Menyatukannya berarti selisih picking —
 * barang yang ternyata tidak ada di rak — tidak punya tempat untuk terbaca.
 *
 * SATU TABEL BARIS PICKING, DUA JENIS PEKERJAAN. picking_list_items sekarang
 * boleh menunjuk baris transfer alih-alih baris pesanan, dan tepat SATU di
 * antaranya wajib terisi. Membuat tabel picking kedua untuk transfer berarti
 * menyalin seluruh mesin picking — klaim tugas, penandaan baris, selisih,
 * penyelesaian — dan dua salinan yang harus diperbaiki bersamaan cepat atau
 * lambat berbeda diam-diam.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ------------------------------------------- Daftar picking transfer */

        Schema::table('stock_transfers', function (Blueprint $table) {
            // Daftar picking yang sedang mengerjakan transfer ini.
            //
            // Kolom di SINI, meniru sales_orders.picking_list_id: satu transfer
            // hanya boleh ada di satu daftar, dan pivot membuat "dua daftar
            // mengerjakan transfer yang sama" mungkin terjadi — akibatnya
            // barangnya diambil dua kali.
            $table->foreignId('picking_list_id')->nullable()->after('status')
                ->constrained()->nullOnDelete();

            $table->timestamp('requested_at')->nullable()->after('notes');
            $table->foreignId('requested_by')->nullable()->after('requested_at')
                ->constrained('users')->nullOnDelete();
        });

        // Transfer lama tidak pernah menempuh picking. Waktu penyusunannya
        // disamakan dengan waktu berangkat — itu memang yang terjadi dulu:
        // menyusun dan memberangkatkan adalah satu ketukan tombol.
        DB::statement('UPDATE stock_transfers SET requested_at = shipped_at, requested_by = shipped_by
            WHERE requested_at IS NULL');

        DB::statement('ALTER TABLE stock_transfers DROP CONSTRAINT IF EXISTS stock_transfers_status_valid');
        DB::statement("ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_status_valid
            CHECK (status IN ('pending', 'in_transit', 'received', 'cancelled'))");

        /* ------------------------------------------------ Dua qty, bukan satu */

        Schema::table('stock_transfer_details', function (Blueprint $table) {
            $table->integer('qty_requested')->nullable()->after('status');
        });

        // Yang diminta dan yang berangkat memang sama persis pada transfer
        // lama — tidak ada langkah picking yang bisa membuatnya berbeda.
        DB::statement('UPDATE stock_transfer_details SET qty_requested = qty_shipped WHERE qty_requested IS NULL');
        DB::statement('ALTER TABLE stock_transfer_details ALTER COLUMN qty_requested SET NOT NULL');

        // qty_shipped NULL = belum dipicking. Sengaja dibedakan dari 0, yang
        // berarti "sudah dicari di rak dan ternyata tidak ada satu pun".
        DB::statement('ALTER TABLE stock_transfer_details ALTER COLUMN qty_shipped DROP NOT NULL');

        DB::statement('ALTER TABLE stock_transfer_details DROP CONSTRAINT IF EXISTS stock_transfer_details_qty_shipped_positive');
        DB::statement('ALTER TABLE stock_transfer_details ADD CONSTRAINT stock_transfer_details_qty_shipped_valid
            CHECK (qty_shipped IS NULL OR qty_shipped >= 0)');

        DB::statement('ALTER TABLE stock_transfer_details ADD CONSTRAINT stock_transfer_details_qty_requested_positive
            CHECK (qty_requested > 0)');

        // Diterima lebih banyak daripada yang berangkat berarti hitungan di
        // gudang asal yang salah, bukan barang yang bertambah di jalan.
        DB::statement('ALTER TABLE stock_transfer_details DROP CONSTRAINT IF EXISTS stock_transfer_details_qty_received_within_shipped');
        DB::statement('ALTER TABLE stock_transfer_details ADD CONSTRAINT stock_transfer_details_qty_received_within_shipped
            CHECK (qty_received IS NULL OR (qty_shipped IS NOT NULL AND qty_received >= 0 AND qty_received <= qty_shipped))');

        /* -------------------------------- Baris picking boleh milik transfer */

        Schema::table('picking_list_items', function (Blueprint $table) {
            $table->foreignId('stock_transfer_detail_id')->nullable()->after('sales_order_detail_id')
                ->constrained('stock_transfer_details')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE picking_list_items ALTER COLUMN sales_order_id DROP NOT NULL');
        DB::statement('ALTER TABLE picking_list_items ALTER COLUMN sales_order_detail_id DROP NOT NULL');

        // TEPAT SATU JENIS PER BARIS. Baris yang menunjuk keduanya akan
        // dikurangi dua kali dari rak; baris yang tidak menunjuk apa pun
        // mengambil barang yang tidak dituntut siapa pun.
        DB::statement('
            ALTER TABLE picking_list_items
            ADD CONSTRAINT picking_list_items_satu_jenis_pekerjaan
            CHECK (
                (sales_order_id IS NOT NULL AND sales_order_detail_id IS NOT NULL AND stock_transfer_detail_id IS NULL)
                OR (sales_order_id IS NULL AND sales_order_detail_id IS NULL AND stock_transfer_detail_id IS NOT NULL)
            )
        ');

        DB::statement('CREATE INDEX picking_list_items_stock_transfer_detail_id_index
            ON picking_list_items (stock_transfer_detail_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS picking_list_items_stock_transfer_detail_id_index');
        DB::statement('ALTER TABLE picking_list_items DROP CONSTRAINT IF EXISTS picking_list_items_satu_jenis_pekerjaan');

        // Baris transfer tidak punya tempat pada skema lama; ia dibuang,
        // bukan dipaksa menunjuk pesanan yang tidak pernah ada.
        DB::statement('DELETE FROM picking_list_items WHERE stock_transfer_detail_id IS NOT NULL');

        Schema::table('picking_list_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_transfer_detail_id');
        });

        DB::statement('ALTER TABLE picking_list_items ALTER COLUMN sales_order_id SET NOT NULL');
        DB::statement('ALTER TABLE picking_list_items ALTER COLUMN sales_order_detail_id SET NOT NULL');

        DB::statement('ALTER TABLE stock_transfer_details DROP CONSTRAINT IF EXISTS stock_transfer_details_qty_requested_positive');
        DB::statement('ALTER TABLE stock_transfer_details DROP CONSTRAINT IF EXISTS stock_transfer_details_qty_shipped_valid');
        DB::statement('ALTER TABLE stock_transfer_details DROP CONSTRAINT IF EXISTS stock_transfer_details_qty_received_within_shipped');

        DB::statement('DELETE FROM stock_transfer_details WHERE qty_shipped IS NULL');
        DB::statement('ALTER TABLE stock_transfer_details ALTER COLUMN qty_shipped SET NOT NULL');

        DB::statement('ALTER TABLE stock_transfer_details ADD CONSTRAINT stock_transfer_details_qty_shipped_positive
            CHECK (qty_shipped > 0)');
        DB::statement('ALTER TABLE stock_transfer_details ADD CONSTRAINT stock_transfer_details_qty_received_within_shipped
            CHECK (qty_received IS NULL OR (qty_received >= 0 AND qty_received <= qty_shipped))');

        Schema::table('stock_transfer_details', function (Blueprint $table) {
            $table->dropColumn('qty_requested');
        });

        DB::statement("DELETE FROM stock_transfers WHERE status = 'pending'");
        DB::statement('ALTER TABLE stock_transfers DROP CONSTRAINT IF EXISTS stock_transfers_status_valid');
        DB::statement("ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_status_valid
            CHECK (status IN ('in_transit', 'received', 'cancelled'))");

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn('requested_at');
            $table->dropConstrainedForeignId('picking_list_id');
        });
    }
};
