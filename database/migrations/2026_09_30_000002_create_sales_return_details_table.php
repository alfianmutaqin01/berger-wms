<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Baris penolakan: satu SKU+batch yang ditolak customer.
 *
 * TIGA ANGKA UNTUK SATU BARIS, DAN KETIGANYA HARUS BISA BERBEDA
 * -------------------------------------------------------------
 *   qty_rejected — yang DILAPORKAN Sales dari depan toko.
 *   qty_approved — yang DISETUJUI Logistik setelah mencocokkan Surat Jalan.
 *   qty_good + qty_ddp — yang BENAR-BENAR sampai di rak, dipisah Operator.
 *
 * Menyimpannya sebagai satu kolom saja akan menghapus jejak perbedaannya, dan
 * justru perbedaan itulah yang perlu dilihat: Sales melapor 10, Logistik hanya
 * mengakui 8, yang sampai di rak 7. Ketiganya bisa benar dan bisa juga
 * menandakan tiga masalah berbeda — dan tidak satu pun bisa ditemukan lagi
 * kalau angkanya saling menimpa.
 *
 * BATCH IKUT DICATAT. Barang yang kembali harus kembali sebagai batch yang
 * sama dengan yang berangkat, bukan batch baru: tanggal kedaluwarsanya sudah
 * berjalan sejak diproduksi, dan memberinya batch baru akan membuat barang
 * lama terbaca muda lalu mengantre paling belakang di FIFO.
 *
 * PEMISAHAN BAGUS/DDP ADA DI SINI, BUKAN DI HEADER. Satu palet yang ditolak
 * bisa separuh masih layak jual dan separuh penyok — memaksa satu keputusan
 * untuk seluruh dokumen berarti Operator harus memilih salah satu yang salah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_return_details', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_return_id')->constrained()->cascadeOnDelete();
            // Baris pesanan yang ditolak. Dipakai untuk tahu SKU apa dan
            // berapa yang sebenarnya dikirim ke customer.
            $table->foreignId('sales_order_detail_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('batch_no', 50);
            /*
             * Tanggal produksi batch yang BERANGKAT, disalin saat dilaporkan.
             *
             * Bukan dicari ulang saat barangnya naik rak: umur barang tidak
             * ikut mundur karena ia sempat pulang, dan mengambilnya kembali
             * dari baris stok yang tersisa bisa salah kalau batch yang sama
             * pernah masuk lebih dari sekali. Tanggal inilah yang menentukan
             * kedaluwarsanya dan urutannya di FIFO setelah kembali.
             */
            $table->date('production_date');

            $table->integer('qty_rejected');
            $table->integer('qty_approved')->nullable();

            // Diisi Operator saat menaikkan ke rak.
            $table->integer('qty_good')->nullable();
            $table->integer('qty_ddp')->nullable();
            // Rak tujuan masing-masing. DDP biasanya punya area sendiri, jadi
            // keduanya dipisah — memaksanya satu rak berarti barang penyok
            // bersandingan dengan barang yang siap dijual.
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ddp_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->text('condition_note')->nullable();

            $table->foreignId('putaway_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('putaway_at')->nullable();

            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index(['sales_return_id', 'is_verified']);
            $table->index(['product_id', 'batch_no']);
        });

        // Satu baris pesanan hanya boleh muncul sekali dalam satu laporan.
        // Dua baris untuk SKU yang sama di dokumen yang sama berarti angkanya
        // dijumlahkan dua kali saat verifikasi.
        DB::statement('
            CREATE UNIQUE INDEX sales_return_details_satu_baris_per_laporan
            ON sales_return_details (sales_return_id, sales_order_detail_id, batch_no)
        ');

        DB::statement('
            ALTER TABLE sales_return_details
            ADD CONSTRAINT sales_return_details_qty_masuk_akal
            CHECK (
                qty_rejected > 0
                AND (qty_approved IS NULL OR (qty_approved >= 0 AND qty_approved <= qty_rejected))
                AND (qty_good IS NULL OR qty_good >= 0)
                AND (qty_ddp IS NULL OR qty_ddp >= 0)
            )
        ');

        /*
         * Baris yang SUDAH dinaikkan wajib punya pemisahannya sekaligus.
         *
         * qty_good tanpa qty_ddp (atau sebaliknya) berarti separuh keputusan
         * Operator hilang, dan saat verifikasi tidak ada yang bisa membedakan
         * "nol unit rusak" dari "belum diisi".
         */
        DB::statement('
            ALTER TABLE sales_return_details
            ADD CONSTRAINT sales_return_details_putaway_lengkap
            CHECK (
                putaway_at IS NULL
                OR (qty_good IS NOT NULL AND qty_ddp IS NOT NULL AND putaway_by IS NOT NULL)
            )
        ');

        // Rak wajib ada untuk bagian yang memang berisi. Barang yang naik ke
        // rak tanpa alamat tidak bisa dicari lagi saat picking.
        DB::statement('
            ALTER TABLE sales_return_details
            ADD CONSTRAINT sales_return_details_rak_wajib
            CHECK (
                (COALESCE(qty_good, 0) = 0 OR location_id IS NOT NULL)
                AND (COALESCE(qty_ddp, 0) = 0 OR ddp_location_id IS NOT NULL)
            )
        ');

        DB::statement('
            ALTER TABLE sales_return_details
            ADD CONSTRAINT sales_return_details_verifikasi_lengkap
            CHECK (
                is_verified = false
                OR (verified_at IS NOT NULL AND verified_by IS NOT NULL AND putaway_at IS NOT NULL)
            )
        ');

        /*
         * FK SUSULAN yang sudah lama menunggu.
         *
         * inventory_stocks.sales_return_detail_id dibuat sejak Fase 4 tetapi
         * tidak pernah bisa diberi FK karena tabel tujuannya belum ada
         * (catatan sirkular docs/2 §8). Sekarang tabelnya ada, jadi utangnya
         * dilunasi di sini — tanpa FK, baris stok bisa menunjuk baris retur
         * yang sudah tidak ada dan asal-usulnya hilang diam-diam.
         */
        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->foreign('sales_return_detail_id')
                ->references('id')->on('sales_return_details')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->dropForeign(['sales_return_detail_id']);
        });

        Schema::dropIfExists('sales_return_details');
    }
};
