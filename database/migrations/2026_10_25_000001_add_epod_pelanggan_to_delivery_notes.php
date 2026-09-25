<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konfirmasi sampai oleh PELANGGAN, untuk kiriman yang supirnya berganti.
 *
 * KENAPA PERLU. Tautan ePOD dirancang untuk "orang yang berdiri di tempat
 * tujuan". Pada kiriman antarpulau, orang itu bukan supir yang berangkat dari
 * gudang: ia menurunkan barang di pelabuhan dan pulang. Yang melanjutkan
 * adalah supir lain di seberang, yang namanya belum ada saat barang berangkat
 * dan tidak akan pernah kami ketahui. Mengirim tautan ke supir pertama berarti
 * konfirmasi "barang sampai" ditekan orang yang tidak pernah melihat tokonya.
 *
 * KENAPA DITENTUKAN MANUAL, BUKAN DITEBAK DARI ALAMAT. Yang menentukan bukan
 * jarak atau pulaunya, melainkan APAKAH SUPIRNYA BERGANTI — dan itu ikut cara
 * armadanya dipesan, bukan ikut peta. Karawang ke Lampung juga menyeberang
 * laut, tetapi truknya naik feri dan supir yang sama yang tiba di toko; di
 * situ ePOD biasa justru yang benar. Tebakan otomatis akan keliru persis pada
 * kiriman yang paling mirip aturannya. Territory pelanggan pun tidak bisa
 * dipakai: lima dari empat belas kodenya (OTHERS, MTO, PROJECT, MPC, EXPORT)
 * bukan nama tempat sama sekali.
 *
 * TOKEN TIDAK DIBUAT DI SINI, DAN ITU POKOK RANCANGANNYA. Kolom eta_date
 * hanya menyimpan perkiraan; tautannya baru diterbitkan pada hari itu oleh
 * App\Console\Commands\KirimEpodPelanggan. Tautan ePOD mati dalam 72 jam
 * (audit keamanan pra-go-live) — kalau diterbitkan saat barang berangkat, ia
 * sudah mati dua minggu sebelum kapalnya sandar. Menerbitkannya belakangan
 * juga berarti tautan yang menyebut nama pelanggan dan isi kiriman tidak
 * hidup di chat siapa pun selama barangnya masih di laut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            /*
             * Namanya menyebut AKIBATNYA, bukan sebabnya: seluruh kode
             * bercabang pada "tautannya ke siapa", dan kolom bernama
             * is_interisland akan membuat pembacanya mengira jawabannya ada
             * pada peta. Label di layar yang menerangkan sebabnya kepada
             * Logistik ("supir berganti di perjalanan").
             */
            $table->boolean('epod_to_customer')->default(false)->after('epod_expires_at');

            // TANGGAL, bukan timestamp: yang diketahui Logistik saat memesan
            // kontainer adalah "sekitar tanggal sekian", dan menyimpan jam
            // pada angka yang memang tidak diketahui hanya melahirkan
            // ketelitian palsu.
            $table->date('eta_date')->nullable()->after('epod_to_customer');

            /*
             * Nomor penerima di toko, DIKETIK TIAP KIRIMAN meski master
             * pelanggan punya kolom phone. Alasannya sama dengan nomor supir:
             * toko penerima di seberang pulau sering bukan nomor yang
             * tercatat di kantor pusat pelanggan. Master dipakai untuk
             * mengisi awal, bukan untuk memutuskan.
             *
             * Bentuk simpan ternormalisasi (62…), sama seperti driver_phone.
             */
            $table->string('customer_phone', 20)->nullable()->after('eta_date');

            /*
             * Pengganti plat nomor pada kiriman kontainer. Plat truk yang
             * mengangkut ke pelabuhan tidak menolong siapa pun dua minggu
             * kemudian; yang dicari saat barang dipertanyakan adalah lewat
             * ekspedisi mana dan kontainer nomor berapa.
             */
            $table->string('forwarder_name', 100)->nullable()->after('customer_phone');
            $table->string('container_no', 30)->nullable()->after('forwarder_name');

            // Dicari tiap hari oleh penjadwal: yang sudah berangkat, belum
            // punya tautan, dan tanggal perkiraannya sudah tiba.
            $table->index(['epod_to_customer', 'eta_date']);
        });

        /*
         * Kiriman yang sudah berangkat TIDAK BOLEH kehilangan tujuan
         * tautannya. Tanpa nomor pelanggan atau tanpa tanggal, penjadwal tidak
         * punya apa pun untuk dikerjakan — dan kiriman itu akan diam di status
         * "berangkat" sampai ada yang menanyakannya berminggu-minggu kemudian.
         * Diperiksa di basis data, bukan hanya di formulir, karena inilah satu-
         * satunya hal yang membuat fiturnya berjalan sendiri.
         */
        DB::statement("
            ALTER TABLE delivery_notes
            ADD CONSTRAINT delivery_notes_epod_pelanggan_lengkap
            CHECK (
                epod_to_customer IS FALSE
                OR status <> 'shipped'
                OR (eta_date IS NOT NULL AND customer_phone IS NOT NULL)
            )
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE delivery_notes DROP CONSTRAINT IF EXISTS delivery_notes_epod_pelanggan_lengkap');

        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->dropIndex(['epod_to_customer', 'eta_date']);
            $table->dropColumn([
                'epod_to_customer', 'eta_date', 'customer_phone',
                'forwarder_name', 'container_no',
            ]);
        });
    }
};
