<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak perubahan nomor SO — Fase 6 tahap 5.
 *
 * Nomor SO diketik manusia saat menerima pesanan, dan salah ketik satu digit
 * membuat Surat Jalan dari BC tidak pernah menemukan pesanannya. Perbaikannya
 * harus mungkin, TAPI TIDAK BOLEH DIAM-DIAM: nomor SO adalah kunci yang
 * dipakai mencocokkan dokumen resmi. Mengubahnya tanpa jejak sama dengan
 * memindahkan barang ke pesanan lain tanpa ada yang bisa menelusurinya.
 *
 * Karena itu setiap perubahan menyimpan nomor lamanya, siapa yang mengubah,
 * dan LEWAT JALAN MANA:
 *
 *   'pairing' — disalin sistem dari Surat Jalan BC (nomornya tidak diketik
 *               ulang manusia, jadi tidak bisa salah ketik untuk kedua kali)
 *   'manual'  — diketik Logistik, hanya boleh selama pesanan belum berangkat
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('so_number_changes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();

            // Nomor lama boleh kosong secara teori, tapi dalam praktiknya
            // selalu terisi: nomor SO wajib saat pesanan diterima.
            $table->string('old_number', 50)->nullable();
            $table->string('new_number', 50);

            $table->string('source', 20);

            // Terisi kalau perubahannya berasal dari pemasangan Surat Jalan.
            $table->foreignId('delivery_note_id')->nullable()
                ->constrained('delivery_notes')->nullOnDelete();

            $table->text('reason')->nullable();

            $table->foreignId('changed_by')->constrained('users');
            $table->timestamps();

            $table->index('sales_order_id');
        });

        DB::statement("
            ALTER TABLE so_number_changes ADD CONSTRAINT so_number_changes_source_valid
            CHECK (source IN ('pairing', 'manual'))
        ");

        // Mengubah nomor menjadi nomor yang sama bukan perubahan; barisnya
        // hanya akan mengaburkan riwayat.
        DB::statement('
            ALTER TABLE so_number_changes ADD CONSTRAINT so_number_changes_benar_benar_berubah
            CHECK (old_number IS DISTINCT FROM new_number)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('so_number_changes');
    }
};
