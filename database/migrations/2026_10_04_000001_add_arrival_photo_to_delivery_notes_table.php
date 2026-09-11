<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Foto bukti barang sampai, difoto supir di lokasi — Fase 12.
 *
 * LUBANG YANG DITUTUP
 * -------------------
 * Sampai sekarang supir bisa menekan "Barang Sudah Sampai" tanpa lampiran
 * apa pun. Pengiriman tercatat sampai hanya berdasarkan pernyataannya, dan
 * tidak ada satu pun bukti bahwa ia benar-benar berada di tempat pelanggan.
 *
 * KENAPA TIDAK DITITIPKAN KE delivery_proofs
 * ------------------------------------------
 * Tabel itu memuat foto Surat Jalan BERTANDA TANGAN yang diunggah Sales dan
 * diverifikasi Logistik; ia yang menentukan pesanan boleh ditutup, dan
 * App\Support\Returns\CustomerRejection membuka formulir penolakan hanya
 * kalau ada barisnya. Menaruh foto supir di sana akan membuat pesanan
 * terlihat sudah berbukti padahal Surat Jalan bertanda tangannya belum ada
 * sama sekali — dan formulir penolakan pelanggan ikut terbuka lebih cepat
 * daripada seharusnya. Dua benda berbeda, dua tempat berbeda.
 *
 * SATU FOTO, BUKAN BANYAK. Supir berdiri di depan gudang pelanggan dengan
 * tangan yang baru selesai menurunkan barang; halaman ini sengaja satu
 * tombol satu tugas, dan kolom di sini mengikuti bentuk itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->string('arrival_photo_path', 255)->nullable()->after('received_by_name');
            $table->string('arrival_photo_mime', 100)->nullable()->after('arrival_photo_path');
            $table->integer('arrival_photo_size')->nullable()->after('arrival_photo_mime');

            // 'camera' = diambil langsung lewat kamera di halaman itu juga.
            // 'file'   = jalur cadangan, saat kamera tidak bisa dibuka.
            //
            // DIBEDAKAN karena keduanya TIDAK sama kuat sebagai bukti: yang
            // 'file' bisa saja foto lama dari galeri. Tanpa kolom ini, dua
            // hal dengan tingkat kepercayaan berbeda tersimpan tak terbedakan
            // dan Logistik menilai keduanya sama.
            $table->string('arrival_photo_source', 10)->nullable()->after('arrival_photo_size');
            $table->timestamp('arrival_photo_taken_at')->nullable()->after('arrival_photo_source');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE delivery_notes
            ADD CONSTRAINT delivery_notes_arrival_photo_source_valid
            CHECK (arrival_photo_source IS NULL OR arrival_photo_source IN ('camera', 'file'))
        SQL);

        // Kelima kolomnya hidup dan mati bersama. Baris dengan path terisi
        // tetapi waktu ambil kosong akan tampil di layar sebagai foto tanpa
        // keterangan kapan — dan justru waktulah yang membuat foto ini
        // bernilai sebagai bukti.
        DB::statement(<<<'SQL'
            ALTER TABLE delivery_notes
            ADD CONSTRAINT delivery_notes_arrival_photo_lengkap
            CHECK (
                (arrival_photo_path IS NULL AND arrival_photo_source IS NULL AND arrival_photo_taken_at IS NULL)
                OR
                (arrival_photo_path IS NOT NULL AND arrival_photo_source IS NOT NULL AND arrival_photo_taken_at IS NOT NULL)
            )
        SQL);

        /*
         * NOT VALID — dan itu justru intinya, bukan kelonggaran.
         *
         * Mulai sekarang tidak ada surat jalan yang boleh berstatus
         * 'delivered' tanpa foto. Tetapi surat jalan yang SUDAH terlanjur
         * dikonfirmasi sebelum aturan ini ada memang tidak punya fotonya, dan
         * tidak akan pernah punya — supirnya sudah pulang berbulan-bulan lalu.
         *
         * Tanpa NOT VALID, migrasi ini gagal di produksi dan seluruh
         * pemasangan berhenti. Dengan NOT VALID, baris lama dibiarkan apa
         * adanya sementara setiap baris baru ditolak basis data — bukan hanya
         * ditolak PHP. Memaksa memvalidasinya berarti memilih antara menolak
         * migrasi atau MENGARANG foto untuk pengiriman lama, dan yang kedua
         * jauh lebih buruk daripada mengakui datanya memang tidak ada.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE delivery_notes
            ADD CONSTRAINT delivery_notes_sampai_wajib_berfoto
            CHECK (status <> 'delivered' OR arrival_photo_path IS NOT NULL)
            NOT VALID
        SQL);
    }

    public function down(): void
    {
        foreach ([
            'delivery_notes_sampai_wajib_berfoto',
            'delivery_notes_arrival_photo_lengkap',
            'delivery_notes_arrival_photo_source_valid',
        ] as $constraint) {
            DB::statement("ALTER TABLE delivery_notes DROP CONSTRAINT IF EXISTS {$constraint}");
        }

        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->dropColumn([
                'arrival_photo_path',
                'arrival_photo_mime',
                'arrival_photo_size',
                'arrival_photo_source',
                'arrival_photo_taken_at',
            ]);
        });
    }
};
