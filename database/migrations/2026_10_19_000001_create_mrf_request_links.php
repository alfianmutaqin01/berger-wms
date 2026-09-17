<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tautan permintaan material untuk divisi yang TIDAK punya akun WMS.
 *
 * QC dan R&D meminta material beberapa kali setahun. Membuatkan mereka akun
 * berarti satu peran baru dengan matriks izinnya sendiri, dasbor yang dibuka
 * dua kali setahun, dan kata sandi yang pasti lupa — biaya yang jauh lebih
 * besar daripada masalahnya. Sistem ini sudah punya pola untuk orang tanpa
 * akun: atasan yang menyetujui MRF dan supir yang mengisi ePOD sama-sama
 * bekerja lewat tautan bertoken. Tautan ini berjalan di rel yang sama.
 *
 * ATASANNYA BOLEH DIKUNCI DI TAUTAN. Di formulir MRF biasa, pemohon mengetik
 * sendiri nama dan nomor atasannya — artinya siapa pun yang memegang
 * formulirnya bisa mengetik nomornya sendiri dan menyetujui permintaannya
 * sendiri. Untuk akun internal risikonya tertahan karena orangnya tercatat;
 * untuk tautan publik ia berbahaya. Manager yang mengatur tautan boleh
 * menetapkan atasannya di sini, dan bila ia menetapkannya, pengisi formulir
 * tidak bisa menggantinya. Dikosongkan berarti divisi itu mengisinya sendiri
 * seperti Produksi.
 *
 * TOKENNYA ACAK 64 KARAKTER, bukan disusun dari id: tautan yang bisa ditebak
 * dari nomor urut membuat siapa pun mengajukan permintaan atas nama divisi
 * mana pun. Aturan yang sama dengan tautan persetujuan dan ePOD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mrf_request_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            // Kosong berarti pengisi formulir menyebutkan atasannya sendiri.
            $table->string('approver_name', 100)->nullable();
            $table->string('approver_phone', 25)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Satu tautan hidup per divisi per gudang. Dua tautan untuk divisi
            // yang sama berarti dua alamat yang beredar, dan yang dicabut
            // belum tentu yang sedang dipakai orang.
            $table->unique(['warehouse_id', 'department_id']);
        });

        // Dua divisi yang memang belum ada di daftar, dan justru merekalah
        // yang paling sering meminta lewat jalur ini.
        foreach ([
            ['name' => 'Quality Control', 'slug' => 'qc'],
            ['name' => 'Research & Development', 'slug' => 'rnd'],
        ] as $departemen) {
            DB::table('departments')->insertOrIgnore($departemen + [
                'description' => 'Ditambahkan untuk permintaan material lewat tautan divisi.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('material_requisitions', function (Blueprint $table) {
            // Permintaan lewat tautan tidak punya akun pemohon sama sekali.
            // Nama orangnya diketik di formulir dan disimpan apa adanya;
            // memalsukannya sebagai akun Logistik yang kebetulan membantu
            // membuat dokumen ini berbohong soal siapa yang meminta.
            $table->foreignId('request_link_id')->nullable()->after('requested_by')
                ->constrained('mrf_request_links')->nullOnDelete();
            $table->string('requester_name', 100)->nullable()->after('request_link_id');
            // Nomor pemohonnya sendiri, BUKAN nomor atasannya. Ke sinilah
            // kabar "barang sudah bisa diambil" dikirim: pemohon lewat tautan
            // tidak punya akun, jadi lonceng di dalam WMS tidak akan pernah
            // ia lihat.
            $table->string('requester_phone', 25)->nullable()->after('requester_name');

            // Barang dari jalur tautan selesai saat DIAMBIL, bukan masuk buku
            // pemakaian: divisi lain lazimnya minta satu-dua pcs yang langsung
            // habis, dan baris sekecil itu di daftar sisa berjalan hanya
            // menenggelamkan sisa Produksi yang benar-benar perlu dikejar.
            $table->string('collected_by_name', 100)->nullable()->after('received_by');
        });

        // requested_by jadi boleh kosong. Diubah lewat SQL mentah karena
        // mengubah kolom berelasi lewat Blueprint menuntut doctrine/dbal.
        DB::statement('ALTER TABLE material_requisitions ALTER COLUMN requested_by DROP NOT NULL');
    }

    public function down(): void
    {
        Schema::table('material_requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('request_link_id');
            $table->dropColumn(['requester_name', 'requester_phone', 'collected_by_name']);
        });

        // Dikembalikan NOT NULL hanya bila tidak ada baris tanpa pemohon —
        // kalau ada, membalikkannya akan menggagalkan seluruh migrasi turun.
        if (! DB::table('material_requisitions')->whereNull('requested_by')->exists()) {
            DB::statement('ALTER TABLE material_requisitions ALTER COLUMN requested_by SET NOT NULL');
        }

        Schema::dropIfExists('mrf_request_links');

        DB::table('departments')->whereIn('slug', ['qc', 'rnd'])
            ->whereNotIn('id', fn ($q) => $q->select('department_id')->from('users')->whereNotNull('department_id'))
            ->delete();
    }
};
