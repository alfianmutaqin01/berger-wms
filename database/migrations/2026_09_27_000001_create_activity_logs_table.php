<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log aktivitas: siapa melakukan apa, kapan — permintaan pemilik produk.
 *
 * BUKAN PENGGANTI stock_movements, DAN SENGAJA TIDAK DILEBUR DENGANNYA.
 * Keduanya menjawab pertanyaan yang berbeda:
 *
 *   stock_movements — "berapa jumlahnya sekarang, dan dari mana angka itu".
 *   Ia buku besar: jumlah qty_change-nya WAJIB setara qty_available, jadi
 *   isinya tidak boleh dimasuki kejadian yang tidak menggeser angka.
 *
 *   activity_logs — "siapa yang melakukan apa". Ia memuat kejadian yang tidak
 *   menyentuh qty sama sekali (memindahkan rak, memasang penanda, membuat
 *   booking), dan memuat KONTEKS yang tidak punya tempat di buku besar:
 *   nilai sebelum & sesudah, alamat IP, alasan yang diketik orangnya.
 *
 * Menumpangkan keduanya berarti salah satu dari dua hal rusak: buku besar
 * kemasukan baris qty_change=0 yang menenggelamkan mutasi sungguhan, atau log
 * aktivitas kehilangan setengah kejadian yang justru paling perlu diawasi.
 *
 * APPEND-ONLY, DITEGAKKAN DI MODEL (App\Models\ActivityLog). Log yang bisa
 * disunting oleh orang yang tercatat di dalamnya bukan log.
 *
 * user_id nullable + nullOnDelete: tindakan sistem (sweep harian) tidak punya
 * pelaku, dan menghapus user TIDAK BOLEH ikut menghapus jejak perbuatannya —
 * karena itu nama & peran pelaku ikut DISALIN sebagai teks di sini, bukan
 * cuma dirujuk lewat foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Salinan tetap. Nama & peran bisa berubah (orang pindah jabatan,
            // akun dihapus); log harus tetap terbaca sebagaimana keadaannya
            // SAAT kejadian, bukan sebagaimana keadaannya sekarang.
            $table->string('user_name', 150)->nullable();
            $table->string('user_role', 50)->nullable();

            // Kata kerja pendek, mis. 'inventory.transfer', 'inventory.adjust'.
            // Sengaja string bebas, bukan enum: menambah tindakan baru tidak
            // boleh butuh migration — kalau butuh, orang akan memilih untuk
            // tidak mencatatnya sama sekali.
            $table->string('action', 60)->index();

            // Kalimat siap baca, dirakit di tempat kejadian. Disimpan jadi,
            // bukan dirakit ulang saat ditampilkan: aturan perakitannya bisa
            // berubah, dan log yang berubah bunyinya setelah kode diperbarui
            // tidak bisa dijadikan pegangan.
            $table->text('description');

            // Sasaran tindakan, mis. InventoryStock #20. Nullable karena tidak
            // semua tindakan punya satu sasaran tunggal.
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // Gudang tempat kejadian — supaya log bisa disaring per wilayah
            // kerja tanpa menebak-nebak dari sasarannya.
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            // Rincian bebas: nilai sebelum/sesudah, alasan, nomor batch.
            $table->jsonb('properties')->nullable();

            $table->ipAddress('ip_address')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // Log dibaca dari yang terbaru, disaring per pelaku/sasaran/gudang.
            $table->index(['created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['warehouse_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
