<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PENOLAKAN CUSTOMER — barang yang ditolak saat pengiriman lalu kembali ke rak.
 *
 * KENAPA BUKAN MENUMPANG sales_order_rejections
 * ---------------------------------------------
 * Tabel itu namanya mirip tetapi peristiwanya sama sekali berbeda: ia mencatat
 * LOGISTIK menolak sebuah pesanan saat penerimaan, sebelum barang bergerak
 * sedikit pun. Yang dicatat di sini kebalikannya — barang sudah berangkat,
 * sudah sampai di depan toko, dan CUSTOMER yang menolaknya. Satu tidak
 * menyentuh stok sama sekali, satunya mengembalikan barang fisik ke rak.
 * Menyatukan keduanya karena namanya mirip akan membuat setiap query
 * "berapa kali pesanan ditolak" menjawab dua pertanyaan sekaligus.
 *
 * BENTUKNYA MENYALIN inbound_headers, DAN ITU DISENGAJA
 * -----------------------------------------------------
 * Barang tolakan masuk lewat pintu yang sama dengan barang produksi: dinaikkan
 * Operator, diverifikasi Logistik, baru resmi jadi stok. Karena alurnya sama,
 * nama statusnya pun disamakan (putaway_pending, verification_pending,
 * partial_verified, verified) — orang yang sudah paham layar inbound tidak
 * perlu mempelajari kosakata kedua untuk hal yang sama.
 *
 * DUA TAHAP PERSETUJUAN, DAN KEDUANYA PUNYA ALASAN SENDIRI
 * --------------------------------------------------------
 *   reported -> approved : Logistik menilai KLAIMNYA. Barangnya masih di atas
 *                          truk dan belum dilihat siapa pun; yang diperiksa
 *                          adalah cocok tidaknya dengan Surat Jalan.
 *   verification         : Logistik menilai BARANGNYA. Benarkah sekian unit
 *                          kembali, dan benarkah kondisinya seperti kata
 *                          Operator.
 * Persetujuan pertama secara fisik tidak mungkin menjawab yang kedua, jadi
 * keduanya tidak bisa saling menggantikan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();

            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            // SJ mana yang ditolak. Boleh kosong: nomor SO di sistem BC kadang
            // berbeda dan SJ-nya belum tersambung — laporannya tetap sah.
            $table->foreignId('delivery_note_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            // Gudang TUJUAN KEMBALI, disalin dari pesanannya. Disimpan sendiri
            // supaya WarehouseScope bisa membatasi tanpa join ke pesanan.
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();

            $table->string('status', 25)->default('reported');
            // Alasan customer menolak, apa adanya dari lapangan.
            $table->text('reason');

            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reported_at');

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();

            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index(['warehouse_id', 'status']);
            $table->index(['sales_order_id', 'status']);
        });

        // Status yang sah — ditegakkan database, bukan hanya disepakati kode.
        DB::statement("
            ALTER TABLE sales_returns
            ADD CONSTRAINT sales_returns_status_valid
            CHECK (status IN (
                'reported', 'rejected', 'putaway_pending',
                'verification_pending', 'partial_verified', 'verified'
            ))
        ");

        /*
         * Status yang menuntut jejak siapa-kapan WAJIB punya keduanya.
         *
         * Tanpa ini, dokumen bisa berstatus 'verified' tanpa satu pun nama
         * yang bertanggung jawab atasnya — dan justru di jalur inilah stok
         * bertambah. Baris yang tidak bisa ditanya "siapa yang menyetujui ini"
         * sama saja dengan stok yang muncul entah dari mana.
         */
        DB::statement("
            ALTER TABLE sales_returns
            ADD CONSTRAINT sales_returns_persetujuan_lengkap
            CHECK (
                status IN ('reported', 'rejected')
                OR (approved_at IS NOT NULL AND approved_by IS NOT NULL)
            )
        ");

        DB::statement("
            ALTER TABLE sales_returns
            ADD CONSTRAINT sales_returns_verifikasi_lengkap
            CHECK (
                status <> 'verified'
                OR (verified_at IS NOT NULL AND verified_by IS NOT NULL)
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_returns');
    }
};
