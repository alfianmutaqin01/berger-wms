<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat outstanding — kekurangan yang pernah terjadi pada sebuah pesanan.
 *
 * MENGAPA KOLOM YANG SUDAH ADA TIDAK CUKUP
 * ----------------------------------------
 * `sales_order_details.outstanding_qty` menyimpan KEADAAN SEKARANG, dan ia
 * ditimpa terus-menerus: Shipment menghitungnya ulang setiap Surat Jalan
 * berangkat, dan OrderCanceller menolkannya saat pesanan dibatalkan. Artinya
 * begitu kekurangan itu tertutup — atau pesanannya dibatalkan lalu diterima
 * ulang — tidak ada lagi jejak bahwa ia pernah ada.
 *
 * Padahal justru itu yang ditanyakan di lapangan: "PO ini dulu kurang berapa,
 * dan kapan akhirnya dipenuhi?". Tabel ini mencatat MOMEN-nya, bukan
 * keadaannya, dengan pola yang sama seperti sales_order_cancellations dan
 * stock_movements: append-only, tidak pernah diubah, tidak pernah dihapus.
 *
 * PEMBAGIAN TUGAS YANG SENGAJA DIPISAH
 * ------------------------------------
 *   Berapa kurangnya SEKARANG  -> sales_order_details.outstanding_qty
 *   Pernah kurang berapa, kapan -> tabel ini
 *
 * Halaman Outstanding membaca keduanya: barisnya dari sini, dan status
 * "masih kurang / sudah terpenuhi" dihitung dari kolom hidup itu. Dengan
 * begitu tidak ada dua angka yang bisa berselisih — yang satu riwayat, yang
 * satu kenyataan, dan masing-masing hanya punya satu sumber.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_outstandings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();

            // Baris pesanan boleh hilang: Logistik berwenang membuang item dari
            // kisi saat menerima pesanan (lihat tulisRincian). Riwayatnya tetap
            // berdiri sendiri — kekurangan itu memang pernah terjadi, sekalipun
            // barisnya kemudian dicabut.
            $table->foreignId('sales_order_detail_id')->nullable()
                ->constrained('sales_order_details')->nullOnDelete();

            $table->foreignId('product_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();

            // 'approval' | 'shipment' — lihat App\Models\SalesOrderOutstanding.
            $table->string('cause', 20);

            // Cuplikan angka SAAT ITU. Disalin, bukan dibaca ulang dari
            // pesanannya: qty_ordered pun masih bisa berubah belakangan, dan
            // riwayat yang ikut berubah bukan riwayat.
            $table->unsignedInteger('qty_ordered');
            $table->unsignedInteger('qty_fulfilled');
            $table->unsignedInteger('qty_outstanding');

            $table->text('note')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['sales_order_id', 'product_id']);
            $table->index('warehouse_id');
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE sales_order_outstandings ADD CONSTRAINT sales_order_outstandings_cause_valid
            CHECK (cause IN ('approval', 'shipment'))");

        // Baris bernilai nol tidak punya arti apa pun di sini: yang dicatat
        // adalah KEKURANGAN, dan kekurangan nol bukan peristiwa.
        DB::statement('ALTER TABLE sales_order_outstandings ADD CONSTRAINT sales_order_outstandings_qty_positif
            CHECK (qty_outstanding > 0)');

        /*
         * Menarik ke belakang kekurangan yang sudah telanjur ada.
         *
         * Tanpa ini halaman Outstanding lahir kosong padahal pesanan yang
         * kurang sudah berjalan sejak lama, dan orang akan membacanya sebagai
         * "tidak ada yang kurang" — persis salah paham yang paling mahal.
         * Waktunya diambil dari approved_at, bukan sekarang, supaya baris
         * lama tidak menumpuk di puncak riwayat seolah baru terjadi hari ini.
         */
        DB::statement("
            INSERT INTO sales_order_outstandings
                (sales_order_id, sales_order_detail_id, product_id, warehouse_id,
                 cause, qty_ordered, qty_fulfilled, qty_outstanding, note,
                 recorded_by, created_at, updated_at)
            SELECT d.sales_order_id, d.id, d.product_id, o.warehouse_id,
                   'approval', d.qty_ordered, d.qty_approved, d.outstanding_qty,
                   'Ditarik dari data yang sudah ada saat riwayat outstanding mulai dicatat.',
                   o.approved_by,
                   COALESCE(o.approved_at, o.submitted_at, o.created_at), NOW()
            FROM sales_order_details d
            JOIN sales_orders o ON o.id = d.sales_order_id
            WHERE d.outstanding_qty > 0 AND o.deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_outstandings');
    }
};
