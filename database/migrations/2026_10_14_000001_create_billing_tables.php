<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing (Fase 8) — buku pantau piutang, BUKAN pembukuan.
 *
 * TANPA NOMINAL SAMA SEKALI — keputusan pemilik produk. Pembayaran tidak
 * pernah melewati sistem ini dan angkanya hidup di BC; yang dicatat di sini
 * hanya "invoice mana yang belum dibayar, kapan jatuh temponya".
 *
 * MENYIMPANG DARI docs/2 §3.6, dengan persetujuan pemilik produk:
 *
 *   1. SATU TAGIHAN PER INVOICE, bukan per pesanan. Fitur gabung invoice
 *      (sales_orders.so_merged_into_id) membuat beberapa pesanan berbagi satu
 *      nomor SO BC; tagihannya menempel ke pesanan INDUK dan anak-anaknya ikut
 *      lunas bersamanya. Per pesanan berarti satu invoice dikonfirmasi lunas
 *      berkali-kali.
 *
 *   2. JATUH TEMPO DARI TANGGAL BARANG SAMPAI, bukan tanggal complete.
 *      Complete menunggu foto Surat Jalan yang bisa terlambat berhari-hari,
 *      dan keterlambatan itu tidak boleh menggeser jatuh tempo customer.
 *
 *   3. SATU PEMBAYARAN BISA MELUNASI BANYAK TAGIHAN. Customer lazim mentransfer
 *      sekali untuk beberapa invoice; relasinya dibalik (tagihan menunjuk
 *      pembayaran), bukan pembayaran menunjuk satu tagihan.
 *
 *   4. TIDAK ADA KOLOM STATUS. Lunas = billing_payment_id terisi; lewat jatuh
 *      tempo dihitung dari due_date saat dibaca. Kolom 'overdue' yang
 *      disimpan akan basi sejak tengah malam berikutnya kalau ada satu malam
 *      saja penjadwalnya tidak berjalan.
 *
 * PEMBAYARAN DIBATALKAN, BUKAN DIHAPUS. Salah konfirmasi lunas terjadi; yang
 * membatalkannya (Manager) dan alasannya harus tetap terbaca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers');
            $table->date('paid_on');
            $table->string('method', 10);
            // Nomor giro / nomor referensi transfer. Wajib untuk giro: giro
            // yang ditolak bank hanya bisa dilacak lewat nomornya.
            $table->string('reference', 60)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'paid_on']);
        });

        DB::statement("
            ALTER TABLE billing_payments
            ADD CONSTRAINT billing_payments_method_check
            CHECK (method IN ('transfer', 'giro', 'tunai'))
        ");

        DB::statement("
            ALTER TABLE billing_payments
            ADD CONSTRAINT billing_payments_giro_bernomor
            CHECK (method <> 'giro' OR reference IS NOT NULL)
        ");

        DB::statement('
            ALTER TABLE billing_payments
            ADD CONSTRAINT billing_payments_batal_lengkap
            CHECK ((voided_at IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND void_reason IS NOT NULL))
        ');

        Schema::create('customer_billings', function (Blueprint $table) {
            $table->id();
            // Pesanan INDUK invoice (so_merged_into_id NULL). UNIQUE: satu
            // invoice, satu tagihan — penjaga terakhir kalau dua pesanan
            // dalam satu gabungan selesai pada detik yang sama.
            $table->foreignId('sales_order_id')->unique()->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            // Disalin: termin di master bisa diubah, jatuh tempo yang sudah
            // disepakati tidak boleh ikut bergeser.
            $table->unsignedSmallInteger('term_days');
            $table->date('delivered_on');
            $table->date('due_date');
            $table->foreignId('billing_payment_id')->nullable()->constrained('billing_payments')->nullOnDelete();
            // Pengingat ke Manager dikirim SEKALI per tahap; penjadwal yang
            // berjalan dua kali sehari tidak boleh membunyikan lonceng dua kali.
            $table->timestamp('reminded_due_soon_at')->nullable();
            $table->timestamp('reminded_overdue_at')->nullable();
            $table->timestamps();

            $table->index(['billing_payment_id', 'due_date']);
            $table->index(['customer_id', 'billing_payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_billings');
        Schema::dropIfExists('billing_payments');
    }
};
