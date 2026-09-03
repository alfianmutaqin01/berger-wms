<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daftar picking — PRD §6.5 F-OUT-03, Fase 6 tahap 3.
 *
 * KENAPA SATU DAFTAR MEMUAT BANYAK PESANAN (keputusan pemilik produk):
 * satu pesanan sering hanya berisi beberapa item, sedangkan satu container
 * yang berangkat memuat pesanan dari banyak toko sekaligus. Logistik yang
 * menentukan pesanan mana saja berangkat bersama, lalu operator mengambil
 * seluruhnya dalam SATU kali jalan. Kalau daftarnya per pesanan, operator
 * bolak-balik ke rak yang sama sebanyak jumlah pesanan.
 *
 * BARISNYA DIBEKUKAN SAAT DAFTAR DIBUAT, bukan dihitung ulang tiap kali
 * layar dibuka. Daftar picking dicetak dan dibawa berjalan; kalau isinya
 * bisa berubah di belakang layar, kertas di tangan operator dan layar di
 * kantor menunjukkan dua hal berbeda — dan yang dipercaya operator adalah
 * kertasnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('picking_lists', function (Blueprint $table) {
            $table->id();

            $table->string('list_number', 30)->unique();

            // Daftar TIDAK PERNAH lintas gudang: orangnya berjalan kaki di
            // satu bangunan. Gudangnya disimpan di sini, bukan disimpulkan
            // dari pesanan di dalamnya, supaya daftar yang kebetulan kosong
            // tetap punya pemilik yang jelas.
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();

            // open      : sudah disusun Logistik, belum diambil operator
            // picking   : seorang operator sedang mengerjakannya
            // completed : seluruh baris ditandai, barang di loading dock
            // cancelled : dibubarkan Logistik, pesanannya kembali bebas
            $table->string('status', 20)->default('open');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Operator yang MEMEGANG tugas ini. Tanpa penanda pemegang, dua
            // operator di gudang yang sama bisa berjalan mengambil daftar
            // yang sama, dan yang kedua baru sadar di rak — saat barangnya
            // sudah tidak ada.
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['warehouse_id', 'status']);
        });

        DB::statement("
            ALTER TABLE picking_lists
            ADD CONSTRAINT picking_lists_status_check
            CHECK (status IN ('open', 'picking', 'completed', 'cancelled'))
        ");

        Schema::create('picking_list_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('picking_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_detail_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // Baris stok yang akan dikurangi saat Siap Loading. Boleh hilang
            // (mis. barisnya habis lalu dibersihkan) — karena itu nullable,
            // dan penyelesaian daftar memeriksanya lagi sebelum mengurangi.
            $table->foreignId('inventory_stock_id')->nullable()->constrained()->nullOnDelete();

            // Rak, batch, dan tanggal produksi DISALIN, bukan hanya ditunjuk.
            // Inilah isi kertas yang dibawa operator; kalau baris stoknya
            // berubah setelah daftar dicetak, yang tercetak harus tetap
            // terbaca apa adanya saat ditelusuri belakangan.
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('batch_no', 50)->nullable();
            $table->date('production_date')->nullable();

            $table->integer('qty_to_pick');

            // NULL = belum disentuh operator. Sengaja dibedakan dari 0, yang
            // berarti "sudah dicek dan ternyata tidak ada satu pun".
            $table->integer('qty_picked')->nullable();

            // pending : belum diambil
            // picked  : diambil sesuai daftar
            // short   : diambil kurang dari daftar, wajib beralasan
            $table->string('status', 20)->default('pending');
            $table->text('discrepancy_reason')->nullable();

            $table->timestamp('picked_at')->nullable();
            $table->foreignId('picked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Urutan berjalan operator: menurut kode rak (F-OUT-03 #3).
            $table->index(['picking_list_id', 'location_id']);
            $table->index('sales_order_detail_id');
        });

        DB::statement("
            ALTER TABLE picking_list_items
            ADD CONSTRAINT picking_list_items_status_check
            CHECK (status IN ('pending', 'picked', 'short'))
        ");

        DB::statement('
            ALTER TABLE picking_list_items
            ADD CONSTRAINT picking_list_items_qty_to_pick_check
            CHECK (qty_to_pick > 0)
        ');

        // Tidak mungkin mengambil LEBIH banyak daripada yang dialokasikan:
        // kelebihannya bukan milik pesanan ini, melainkan milik pesanan lain
        // yang sudah mencadangkannya dari batch yang sama.
        DB::statement('
            ALTER TABLE picking_list_items
            ADD CONSTRAINT picking_list_items_qty_picked_check
            CHECK (qty_picked IS NULL OR (qty_picked >= 0 AND qty_picked <= qty_to_pick))
        ');

        // Selisih WAJIB beralasan, dan alasan hanya boleh ada pada selisih.
        // Ditegakkan di database, bukan hanya di FormRequest: baris selisih
        // tanpa keterangan adalah stok yang hilang tanpa jejak, dan itu
        // persis yang paling sering ditanyakan saat opname.
        DB::statement("
            ALTER TABLE picking_list_items
            ADD CONSTRAINT picking_list_items_discrepancy_check
            CHECK (
                (status = 'short' AND discrepancy_reason IS NOT NULL AND qty_picked IS NOT NULL AND qty_picked < qty_to_pick)
                OR (status <> 'short' AND discrepancy_reason IS NULL)
            )
        ");

        Schema::table('sales_orders', function (Blueprint $table) {
            // Daftar picking yang sedang memuat pesanan ini.
            //
            // Kolom di SINI, bukan tabel pivot: satu pesanan hanya boleh ada
            // di SATU daftar. Pivot membuat "dua daftar memuat pesanan yang
            // sama" bisa terjadi, dan akibatnya barangnya diambil dua kali.
            $table->foreignId('picking_list_id')->nullable()->after('so_merged_into_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('picking_list_id');
        });

        Schema::dropIfExists('picking_list_items');
        Schema::dropIfExists('picking_lists');
    }
};
