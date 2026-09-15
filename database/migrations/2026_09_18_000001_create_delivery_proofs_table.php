<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bukti Surat Jalan bertanda tangan — PRD §6.5 F-OUT-05 & F-OUT-06.
 *
 * Sales memotret Surat Jalan yang sudah ditandatangani pelanggan, Logistik
 * memeriksanya, lalu pesanan dinyatakan selesai.
 *
 * SATU PESANAN BISA PUNYA BANYAK FOTO, dan foto yang DITOLAK TIDAK DIHAPUS.
 * Kalau baris lama ditimpa, jejak "pernah diunggah sesuatu yang salah"
 * hilang bersamanya — padahal justru itu yang perlu ditelusuri ketika
 * pelanggan dan gudang berbeda pendapat soal apa yang diterima.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_proofs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();

            /*
             * Surat Jalan yang dibuktikan. BOLEH kosong: satu pesanan bisa
             * berangkat tanpa SJ terpasang (nomor SO di BC berbeda), dan
             * buktinya tetap harus bisa diunggah.
             */
            $table->foreignId('delivery_note_id')->nullable()
                ->constrained('delivery_notes')->nullOnDelete();

            $table->string('path');
            $table->string('original_name');
            $table->unsignedInteger('size');
            $table->string('mime', 100);

            $table->string('status', 20)->default('pending');
            $table->text('rejection_reason')->nullable();

            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamp('uploaded_at');
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index(['sales_order_id', 'status']);
        });

        DB::statement("
            ALTER TABLE delivery_proofs ADD CONSTRAINT delivery_proofs_status_valid
            CHECK (status IN ('pending', 'verified', 'rejected'))
        ");

        // Penolakan tanpa alasan tidak berguna bagi Sales yang harus
        // mengunggah ulang: dia tidak tahu apa yang salah.
        DB::statement('
            ALTER TABLE delivery_proofs ADD CONSTRAINT delivery_proofs_alasan_wajib
            CHECK (
                (status = \'rejected\' AND rejection_reason IS NOT NULL)
                OR (status <> \'rejected\' AND rejection_reason IS NULL)
            )
        ');

        DB::statement('
            ALTER TABLE delivery_proofs ADD CONSTRAINT delivery_proofs_verifikasi_lengkap
            CHECK (
                (status = \'pending\' AND verified_by IS NULL AND verified_at IS NULL)
                OR (status <> \'pending\' AND verified_by IS NOT NULL AND verified_at IS NOT NULL)
            )
        ');

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('completed_by')->nullable()->after('completed_at')
                ->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('completed_by');
        });

        Schema::dropIfExists('delivery_proofs');
    }
};
