<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengiriman ulang atas kekurangan (outstanding) — permintaan pemilik produk.
 *
 * KEJADIAN YANG MELAHIRKANNYA. Pesanan berangkat sebagian karena stoknya
 * kurang; sisanya tetap menjadi kewajiban perusahaan. Ketika stoknya ada,
 * barang itu harus dikirim menyusul DENGAN NOMOR SO YANG SAMA — pesanannya
 * memang pesanan yang itu juga — tetapi lewat SURAT JALAN BARU, karena Surat
 * Jalan menerangkan satu kali keberangkatan kendaraan, bukan satu pesanan.
 *
 * KENAPA TABEL SENDIRI, BUKAN KOLOM DI sales_orders. Pengiriman ulang bisa
 * terjadi lebih dari sekali: dari 50 yang kurang, 30 menyusul minggu ini dan
 * 20 sisanya bulan depan. Kolom "sudah dikirim ulang" hanya bisa menyimpan
 * yang terakhir, dan yang perlu dijawab justru "sudah berapa kali, oleh siapa,
 * dan berapa tiap kalinya".
 *
 * APPEND-ONLY, ditegakkan di model (pola StockMovement). Ini catatan
 * pemenuhan kewajiban ke pelanggan; yang bisa disunting belakangan tidak bisa
 * dipakai menjawab "kenapa sisa 20 ini belum juga sampai".
 *
 * TIDAK MENYIMPAN NOMOR SURAT JALAN. Baris ini dibuat SEBELUM Surat Jalannya
 * ada — SJ terbit dari sistem BC setelah barangnya dipicking ulang. Menyimpan
 * kolom yang baru terisi belakangan berarti ada dua keadaan "belum ada SJ" dan
 * "SJ-nya memang tidak pernah ada" yang tidak bisa dibedakan. Kaitannya
 * terbaca dari delivery_notes milik pesanan yang sama, terurut waktu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_reshipments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained();

            // Putaran KE BERAPA. Disimpan, bukan dihitung saat ditampilkan:
            // menghitungnya dari jumlah baris membuat nomor putaran berubah
            // sendiri kalau kelak ada baris yang tersaring keluar.
            $table->unsignedSmallInteger('round_no');

            // Yang MASIH kurang saat tombol ditekan, dan yang benar-benar
            // berhasil dicadangkan dari stok saat itu. Keduanya dicatat karena
            // sering BERBEDA: stok bisa hanya cukup untuk sebagian, dan sisanya
            // tetap terutang untuk putaran berikutnya.
            $table->unsignedInteger('qty_outstanding');
            $table->unsignedInteger('qty_allocated');

            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['sales_order_id', 'round_no']);
        });

        DB::statement('
            ALTER TABLE sales_order_reshipments ADD CONSTRAINT sales_order_reshipments_qty_masuk_akal
            CHECK (qty_outstanding > 0 AND qty_allocated <= qty_outstanding)
        ');

        DB::statement('
            ALTER TABLE sales_order_reshipments ADD CONSTRAINT sales_order_reshipments_putaran_positif
            CHECK (round_no > 0)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_reshipments');
    }
};
