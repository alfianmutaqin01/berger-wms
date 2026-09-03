<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Surat Jalan — PRD §6.5 F-OUT-04, Fase 6 tahap 4.
 *
 * PERBEDAAN PALING PENTING DARI RANCANGAN AWAL docs/2 §3.5, dan ini mengubah
 * arti seluruh tabel: SURAT JALAN DITERBITKAN SISTEM BC, BUKAN SISTEM INI
 * (keputusan pemilik produk, temuan saat merancang tahap 4).
 *
 * Akibatnya:
 *   - Nomor SJ TIDAK dibangkitkan di sini. Ia disalin dari kolom
 *     "Document No." milik BC, dan karena itu tidak ada urutan dokumen baru
 *     yang perlu diatur Super Admin.
 *   - Qty yang berlaku adalah qty BC, bukan qty hasil picking kami.
 *   - Kolom `printed_at`/`printed_by` pada rancangan lama tidak dipakai:
 *     yang terjadi di sini bukan pencetakan melainkan PENYALINAN.
 *
 * Peran sistem ini adalah MENDUKUNG TRANSPARANSI — mencocokkan apa yang
 * benar-benar diambil dari rak dengan apa yang tertulis di dokumen resmi,
 * lalu menyimpan jejaknya. Ia tidak menerbitkan dokumen apa pun.
 *
 * BERKASNYA DIBUANG, DATANYA DISIMPAN. Logistik mengunggah ekspor SJ dari BC
 * (per hari, atau per container yang mau berangkat); isinya dibaca menjadi
 * baris di sini lalu berkasnya tidak disimpan sama sekali — sama seperti
 * seluruh impor lain di sistem ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();

            // "Document No." dari BC, mis. 206215. UNIK: satu dokumen SJ di BC
            // adalah satu baris di sini, dan mengunggah ekspor yang sama dua
            // kali harus menghasilkan keadaan yang sama, bukan dua salinan.
            $table->string('document_no', 30)->unique();

            // "SO Number" dari BC, mis. SO260903. Inilah jembatan ke pesanan
            // kami: nomor yang sama diketik Logistik saat menerima pesanan
            // (sales_orders.bc_so_number).
            $table->string('bc_so_number', 50);

            // Hasil pencocokan. BOLEH NULL: ekspor harian BC memuat seluruh
            // SJ perusahaan, termasuk yang pesanannya tidak pernah lewat
            // portal ini. Menolak baris semacam itu berarti menolak berkas
            // yang sebenarnya sah; yang benar adalah menyimpannya sebagai
            // "belum berpasangan" supaya terlihat.
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();

            // "Sell-to Customer No." dari BC, mis. IDR13302 — sama persis
            // dengan customers.code. Disimpan APA ADANYA di samping hasil
            // pencocokannya: kalau kodenya tidak dikenal, yang perlu dibaca
            // orang adalah kode aslinya, bukan kekosongan.
            $table->string('customer_code', 30)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();

            // "Location Code" BC, mis. ID1B_1001. Kode gudang versi BC, yang
            // TIDAK sama dengan kode gudang kami (WH-01). Disimpan sebagai
            // keterangan, tidak dipakai memutuskan apa pun — memetakan dua
            // sistem penamaan gudang adalah keputusan tersendiri yang belum
            // diambil, dan menebaknya lebih buruk daripada tidak memakainya.
            $table->string('bc_location_code', 30)->nullable();

            $table->date('shipment_date')->nullable();

            // imported : baru disalin dari BC, barangnya belum berangkat
            // shipped  : barang dinyatakan berangkat lewat sistem ini
            // delivered: supir mengonfirmasi sampai
            $table->string('status', 20)->default('imported');

            $table->timestamp('imported_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('bc_so_number');
            $table->index(['warehouse_id', 'status']);
        });

        DB::statement("
            ALTER TABLE delivery_notes
            ADD CONSTRAINT delivery_notes_status_check
            CHECK (status IN ('imported', 'shipped', 'delivered'))
        ");

        Schema::create('delivery_note_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('delivery_note_id')->constrained()->cascadeOnDelete();

            // SKU disimpan sebagai TEKS di samping hasil pencocokannya, dan
            // keduanya sengaja ada. product_id boleh null bila SKU-nya tidak
            // dikenal Master Produk; yang tertulis di dokumen resmi tetap
            // harus bisa dibaca kembali persis seperti aslinya.
            $table->string('sku', 50);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            // Deskripsi versi BC. DISIMPAN, TAPI TIDAK DIPAKAI menimpa nama
            // produk kami — aturan yang sama dengan penerimaan pesanan di
            // tahap 1: nama versi BC yang berbeda tidak boleh masuk basis
            // data sebagai kebenaran.
            $table->string('description', 255)->nullable();

            $table->integer('qty');
            $table->integer('qty_invoiced')->nullable();
            $table->string('uom_code', 10)->nullable();

            $table->timestamps();

            // Satu SKU sekali saja dalam satu dokumen SJ. Kalau muncul dua
            // kali di berkas, yang benar adalah barisnya diperbarui — bukan
            // dijumlahkan diam-diam, karena ekspor ulang dokumen yang sama
            // akan melipatgandakannya.
            $table->unique(['delivery_note_id', 'sku']);
        });

        DB::statement('
            ALTER TABLE delivery_note_lines
            ADD CONSTRAINT delivery_note_lines_qty_check
            CHECK (qty > 0)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_note_lines');
        Schema::dropIfExists('delivery_notes');
    }
};
