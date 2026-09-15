<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengiriman & konfirmasi supir — PRD §6.5 F-OUT-04 #5-10, F-OUT-05.
 *
 * DATA SUPIR DIKETIK TIAP KALI, TIDAK ADA MASTER SUPIR (keputusan pemilik
 * produk): supir berganti setiap hari dan sebagian besar berasal dari
 * perusahaan jasa lain, sehingga "data induk supir" hanya akan melahirkan
 * ratusan baris yang tak terawat.
 *
 * Yang dilindungi karena itu bukan datanya, melainkan NOMORNYA: nomor
 * disimpan sudah ternormalisasi, dan nomor yang pernah dipakai bisa
 * disarankan kembali saat mengetik. Daftar itu tumbuh sendiri dari kolom ini
 * — tidak ada yang perlu merawatnya.
 *
 * STATUS PESAN DIPISAH DARI STATUS BARANG. Kalau WhatsApp gagal terkirim,
 * truk TIDAK menunggu (keputusan pemilik produk). Menjadikan keberhasilan
 * kirim pesan sebagai syarat berangkat berarti gangguan di penyedia pihak
 * ketiga bisa menghentikan pengiriman seluruh gudang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->string('driver_name', 100)->nullable()->after('shipment_date');

            // Bentuk simpan: hanya angka berawalan 62 (lihat App\Support\PhoneNumber).
            // Disimpan ternormalisasi supaya saran ketik dan pengiriman pesan
            // membaca bentuk yang sama — nomor yang sama ditulis dua cara
            // adalah dua baris berbeda di daftar saran, dan sarannya jadi
            // penuh duplikat yang tidak menolong siapa pun.
            $table->string('driver_phone', 20)->nullable()->after('driver_name');
            $table->string('vehicle_plate', 20)->nullable()->after('driver_phone');

            $table->timestamp('shipped_at')->nullable();
            $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();

            // Tautan konfirmasi untuk supir: TANPA LOGIN, jadi tokennya
            // sendiri yang menjadi kunci. Panjang dan acak, dan disimpan
            // bukan disusun dari id — tautan yang bisa ditebak dari nomor
            // urut berarti siapa pun bisa mengonfirmasi kiriman orang lain.
            $table->string('epod_token', 64)->nullable()->unique();

            $table->timestamp('delivered_at')->nullable();

            // Nama orang yang menerima di tempat customer, diisi supir.
            $table->string('received_by_name', 100)->nullable();

            // pending : belum dicoba
            // manual  : disiapkan untuk dikirim Logistik lewat WhatsApp-nya
            //           sendiri (mode tanpa penyedia)
            // sent    : penyedia menyatakan terkirim
            // failed  : gagal, dan HARUS terlihat — kegagalan yang diam
            //           adalah supir yang menunggu tautan yang tidak pernah
            //           datang
            $table->string('notify_status', 20)->default('pending');
            $table->unsignedSmallInteger('notify_attempts')->default(0);
            $table->timestamp('notified_at')->nullable();
            $table->text('notify_error')->nullable();

            $table->index('driver_phone');
        });

        DB::statement("
            ALTER TABLE delivery_notes
            ADD CONSTRAINT delivery_notes_notify_status_check
            CHECK (notify_status IN ('pending', 'manual', 'sent', 'failed'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE delivery_notes DROP CONSTRAINT IF EXISTS delivery_notes_notify_status_check');

        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipped_by');
            $table->dropColumn([
                'driver_name', 'driver_phone', 'vehicle_plate',
                'shipped_at', 'epod_token', 'delivered_at', 'received_by_name',
                'notify_status', 'notify_attempts', 'notified_at', 'notify_error',
            ]);
        });
    }
};
