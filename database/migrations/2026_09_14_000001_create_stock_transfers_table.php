<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transfer stok antar gudang — PRD F-INV-05.
 *
 * MENGAPA DUA LANGKAH, BUKAN SATU
 * -------------------------------
 * Karawang ke Pekanbaru butuh berhari-hari di jalan. Kalau stok langsung
 * mendarat begitu tombol Kirim ditekan, Sales Pekanbaru bisa menjual barang
 * yang masih di atas truk — lalu pesanannya tidak bisa dipicking ketika
 * kirimannya terlambat. Karena itu ada keadaan ketiga yang bukan milik gudang
 * mana pun: DALAM PERJALANAN.
 *
 *   dikirim  -> qty keluar dari gudang asal (TRANSFER_OUT), belum di mana pun
 *   diterima -> qty masuk ke gudang tujuan  (TRANSFER_IN)
 *   dibatalkan -> qty dikembalikan ke gudang asal
 *
 * Selisih antara qty_shipped dan qty_received adalah kehilangan di
 * perjalanan. Ia tidak perlu mutasi tersendiri: barangnya sudah dikurangi di
 * asal dan memang tidak pernah ditambahkan di tujuan. Yang wajib ada adalah
 * ALASANNYA, supaya angka yang hilang tidak pernah hilang tanpa keterangan.
 *
 * BATCH DAN TANGGAL PRODUKSI IKUT PINDAH APA ADANYA. Yang di-reset hanya
 * lokasi raknya, karena penomoran rak tiap gudang berbeda. Kalau batch atau
 * tanggal produksinya dibuat ulang, FIFO di gudang tujuan akan menganggap
 * barang lama sebagai barang baru — dan penarikan kembali stok yang mendekati
 * kedaluwarsa ke Karawang jadi mustahil, karena umurnya sudah hilang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();

            $table->string('transfer_number', 30)->unique();

            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();

            // in_transit | received | cancelled
            $table->string('status', 20)->default('in_transit');

            $table->text('notes')->nullable();

            $table->timestamp('shipped_at')->nullable();
            $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            // Dua pola query yang sama seringnya: "apa yang saya kirim" dan
            // "apa yang menuju ke saya". Keduanya menyaring gudang + status.
            $table->index(['from_warehouse_id', 'status']);
            $table->index(['to_warehouse_id', 'status']);
        });

        DB::statement("ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_status_valid
            CHECK (status IN ('in_transit', 'received', 'cancelled'))");

        // Gudang asal dan tujuan tidak boleh sama. Transfer ke diri sendiri
        // bukan transfer — itu pemindahan rak, dan sudah punya layarnya
        // sendiri di Data Stok.
        DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_different_warehouses
            CHECK (from_warehouse_id <> to_warehouse_id)');

        Schema::create('stock_transfer_details', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // Baris stok ASAL, untuk penelusuran. nullOnDelete dan bukan
            // restrict: baris stok asal wajar habis lalu dibersihkan, dan
            // dokumen transfernya harus tetap utuh tanpa itu.
            $table->foreignId('source_stock_id')->nullable()
                ->constrained('inventory_stocks')->nullOnDelete();

            // Disalin, bukan dibaca dari baris stok asal saat dibutuhkan:
            // inilah identitas barang yang benar-benar berangkat, dan ia
            // harus tetap terbaca walau stok asalnya sudah habis.
            $table->string('batch_no', 50);
            $table->date('production_date');
            $table->date('expiry_date');

            // Status ikut pindah. Barang DDP yang ditarik kembali ke Karawang
            // tidak boleh berubah jadi layak jual hanya karena berpindah rak.
            $table->string('status', 20)->default('active');
            $table->string('ddp_reason', 100)->nullable();

            $table->integer('qty_shipped');
            // NULL selama masih di jalan — belum ada yang menghitungnya.
            $table->integer('qty_received')->nullable();

            // Rak di gudang TUJUAN, diisi saat penerimaan.
            $table->foreignId('to_location_id')->nullable()
                ->constrained('locations')->nullOnDelete();

            $table->text('discrepancy_reason')->nullable();

            $table->timestamps();

            $table->index('stock_transfer_id');
            $table->index(['product_id', 'batch_no']);
        });

        DB::statement('ALTER TABLE stock_transfer_details ADD CONSTRAINT stock_transfer_details_qty_shipped_positive
            CHECK (qty_shipped > 0)');

        // Diterima lebih banyak daripada yang dikirim berarti hitungan di
        // gudang asal yang salah, bukan barang yang bertambah di jalan.
        // Itu dikoreksi lewat Penyesuaian Stok, bukan diciptakan diam-diam
        // di sini.
        DB::statement('ALTER TABLE stock_transfer_details ADD CONSTRAINT stock_transfer_details_qty_received_within_shipped
            CHECK (qty_received IS NULL OR (qty_received >= 0 AND qty_received <= qty_shipped))');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_details');
        Schema::dropIfExists('stock_transfers');
    }
};
