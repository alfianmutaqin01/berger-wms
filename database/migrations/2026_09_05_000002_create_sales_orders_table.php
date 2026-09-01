<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Header pesanan penjualan — docs/2 §3.5, docs/1 §6.5.
 *
 * BEDA DARI docs/2, semuanya atas keputusan pemilik produk:
 *
 *   1. STATUS 'draft' DITAMBAHKAN dan submitted_at jadi NULLABLE.
 *      docs/1 F-OUT-01 #7 dan docs/4 §3.3.2 sama-sama mewajibkan tombol
 *      "Simpan Draft", dan §3.3.2 menegaskan tombol itu tetap aktif setelah
 *      pukul 15:00. Tanpa draft, aturan cutoff §7.5 tidak punya jalan keluar:
 *      lewat jam 15:00 Sales tidak bisa menyimpan apa pun. submitted_at
 *      adalah TITIK AWAL SLA (§7.6), jadi draft memang belum boleh punya.
 *
 *   2. payment_term_id FK, bukan ENUM. Tabel payment_terms sudah ada sejak
 *      Fase 2 dan migrasinya sendiri menyatakan dibuat agar "dropdown pada
 *      form Sales tinggal membacanya". Kolom days di tabel itu langsung
 *      dipakai Billing (Fase 8) tanpa memetakan ulang string ke angka.
 *
 *   3. DUA METODE PEMESANAN (permintaan pemilik produk, di luar dokumen):
 *      - Metode dokumen: Sales mengunggah PO customer dan mengisi nomor PO
 *        milik customer; rincian item dibiarkan kosong, diisi Logistik saat
 *        approval (Fase 6) sambil membaca dokumen itu.
 *      - Metode rincian: Sales mengisi sendiri item dan qty-nya.
 *      Nomor internal SELALU digenerate di kedua metode — nomor PO customer
 *      disimpan terpisah supaya dua customer yang kebetulan memakai nomor
 *      sama tidak saling menolak.
 *
 *   4. bc_so_number disiapkan di sini walau baru diisi Fase 6, supaya
 *      strukturnya tidak perlu diubah lagi saat layar approval dibangun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();

            // Identitas internal: PO{YYYYMMDD}{urut}, urut reset tiap bulan.
            $table->string('order_number', 30)->unique();

            // Nomor PO milik customer (metode dokumen). SENGAJA TIDAK unique:
            // dua customer berbeda boleh memakai penomoran yang sama.
            $table->string('customer_po_number', 50)->nullable();

            // Nomor SO dari sistem BC, diisi Logistik saat menerima pesanan.
            $table->string('bc_so_number', 50)->nullable();

            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_term_id')->constrained()->restrictOnDelete();

            $table->string('status', 30)->default('draft');

            // Metode pemesanan: 'manual' (Sales mengisi rincian) atau
            // 'document' (rincian menyusul dari dokumen yang diunggah).
            $table->string('order_source', 20)->default('manual');

            // Dokumen PO customer. Satu berkas per pesanan.
            $table->string('document_path', 500)->nullable();
            $table->string('document_name', 255)->nullable();
            $table->unsignedInteger('document_size')->nullable();
            $table->string('document_mime', 100)->nullable();

            $table->timestamp('submitted_at')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('picking_completed_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Durasi SLA (§7.6) dalam jam, dihitung saat order complete.
            $table->decimal('sla_hours', 8, 2)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Daftar "pesanan saya" milik Sales, urut terbaru.
            $table->index(['user_id', 'status']);
            // Antrean approval Logistik, difilter per gudang (F-OUT-02 #1).
            $table->index(['warehouse_id', 'status']);
            $table->index('customer_id');
            $table->index('submitted_at');
        });

        /*
         * Metode dokumen WAJIB punya berkasnya. Aturan ini ditegakkan di
         * database, bukan hanya di FormRequest: pesanan bermetode dokumen
         * tanpa lampiran berarti Logistik tidak punya apa pun untuk dibaca
         * saat mengisi rincian item, dan pesanan itu mustahil diproses.
         */
        DB::statement("ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_document_requires_file
            CHECK (order_source <> 'document' OR document_path IS NOT NULL)");

        /*
         * Pesanan yang sudah lepas dari draft WAJIB punya submitted_at —
         * itulah titik awal SLA. Tanpa penjaga ini, satu baris yang lolos
         * tanpa submitted_at membuat perhitungan SLA diam-diam salah.
         */
        DB::statement("ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_submitted_at_required
            CHECK (status = 'draft' OR submitted_at IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_orders');
    }
};
