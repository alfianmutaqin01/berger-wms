<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stocktake — mencocokkan angka sistem dengan barang yang benar-benar ada
 * di rak, sebulan atau tiga bulan sekali.
 *
 * ANGKA SISTEM DIBEKUKAN SAAT SESI DIBUKA, bukan dibaca ulang saat hasilnya
 * disahkan. Menghitung satu gudang makan waktu berjam-jam sampai berhari-hari,
 * dan selama itu barang tetap keluar-masuk. Kalau pembandingnya angka
 * "sekarang", tiap pengiriman yang berangkat di tengah penghitungan akan
 * terbaca sebagai selisih stocktake — padahal ia justru pergerakan yang benar dan
 * sudah tercatat rapi di ledger.
 *
 * KOREKSINYA DITERAPKAN SEBAGAI SELISIH, BUKAN SEBAGAI PENIMPAAN
 * --------------------------------------------------------------
 * Saat disahkan, yang ditambahkan ke stok adalah (fisik - beku), bukan angka
 * fisiknya langsung. Dengan begitu barang yang sah keluar setelah dihitung
 * tidak dihidupkan kembali oleh laporan stocktake. Inilah yang membuat stocktake
 * tidak perlu membekukan seluruh operasi gudang.
 *
 * STOK BARU AKTIF SETELAH LAPORAN DISAHKAN (keputusan pemilik produk). Selama
 * sesi masih berjalan, tidak satu pun angka stok tersentuh — hasil hitungan
 * hanya menumpuk sebagai catatan. Yang mengubah stok adalah satu tindakan
 * yang jelas dan berpemilik: pengesahan laporan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_takes', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 30)->unique();

            $table->foreignId('warehouse_id')->constrained();

            // 'warehouse' | 'zone' | 'rack' — lihat App\Models\StockTake.
            $table->string('scope_type', 20);
            // Nama zona atau kode deret; NULL untuk cakupan satu gudang penuh.
            $table->string('scope_value', 50)->nullable();

            // 'counting' | 'finalized' | 'cancelled'
            $table->string('status', 20)->default('counting');

            $table->text('note')->nullable();

            $table->timestamp('opened_at');
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['warehouse_id', 'status']);
        });

        DB::statement("ALTER TABLE stock_takes ADD CONSTRAINT stock_takes_status_valid
            CHECK (status IN ('counting', 'finalized', 'cancelled'))");

        DB::statement("ALTER TABLE stock_takes ADD CONSTRAINT stock_takes_scope_valid
            CHECK (
                (scope_type = 'warehouse' AND scope_value IS NULL)
                OR (scope_type IN ('zone', 'rack') AND scope_value IS NOT NULL)
            )");

        // Sesi yang sudah disahkan WAJIB punya jejak siapa dan kapan. Laporan
        // stocktake tanpa penanggung jawab tidak bisa dipakai menjawab apa pun.
        DB::statement("ALTER TABLE stock_takes ADD CONSTRAINT stock_takes_finalisasi_lengkap
            CHECK (
                status <> 'finalized'
                OR (finalized_at IS NOT NULL AND finalized_by IS NOT NULL)
            )");

        /*
         * SATU SESI BERJALAN PER GUDANG.
         *
         * Dua sesi terbuka bersamaan berarti dua angka beku untuk baris stok
         * yang sama, dan pengesahan yang kedua akan menerapkan selisih yang
         * sudah diterapkan sesi pertama — stok bergeser dua kali karena satu
         * penghitungan. Ditegakkan indeks unik parsial, bukan hanya di
         * aplikasi: dua orang yang menekan "Buka Sesi" pada saat yang sama
         * sama-sama lolos pemeriksaan aplikasi.
         */
        DB::statement("CREATE UNIQUE INDEX stock_takes_satu_sesi_berjalan
            ON stock_takes (warehouse_id) WHERE status = 'counting'");

        Schema::create('stock_take_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stock_take_id')->constrained()->cascadeOnDelete();

            // Baris stok yang dihitung. Boleh hilang (mis. batch habis lalu
            // barisnya dibersihkan); laporannya tetap berdiri karena seluruh
            // keterangannya sudah disalin ke kolom di bawah.
            $table->foreignId('inventory_stock_id')->nullable()
                ->constrained('inventory_stocks')->nullOnDelete();

            $table->foreignId('location_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->string('batch_no', 50)->nullable();

            // Angka sistem yang DIBEKUKAN saat sesi dibuka: qty_available
            // ditambah qty_allocated. Yang dihitung orang di rak adalah barang
            // fisiknya, dan barang yang sudah dicadangkan untuk pesanan tetap
            // berdiri di sana sampai operator mengambilnya.
            $table->unsignedInteger('qty_system');

            // NULL berarti BELUM DIHITUNG — berbeda dari nol yang berarti
            // "sudah dicek, raknya memang kosong". Membedakan keduanya adalah
            // seluruh gunanya kolom ini boleh null.
            $table->unsignedInteger('qty_physical')->nullable();

            $table->text('count_note')->nullable();

            $table->timestamp('counted_at')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();

            // Diisi saat pengesahan: berapa yang benar-benar diterapkan ke
            // stok, dan jadi berapa hasilnya. Disimpan supaya laporan yang
            // dicetak ulang setahun kemudian menunjukkan angka yang sama.
            $table->integer('applied_delta')->nullable();
            $table->unsignedInteger('qty_after')->nullable();

            $table->timestamps();

            $table->index(['stock_take_id', 'location_id']);
            $table->unique(['stock_take_id', 'inventory_stock_id']);
        });

        DB::statement('ALTER TABLE stock_take_items ADD CONSTRAINT stock_take_items_hitungan_lengkap
            CHECK (
                (qty_physical IS NULL AND counted_at IS NULL)
                OR (qty_physical IS NOT NULL AND counted_at IS NOT NULL)
            )');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_take_items');
        DB::statement('DROP INDEX IF EXISTS stock_takes_satu_sesi_berjalan');
        Schema::dropIfExists('stock_takes');
    }
};
