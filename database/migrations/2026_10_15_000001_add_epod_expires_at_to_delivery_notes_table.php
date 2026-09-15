<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Masa berlaku tautan konfirmasi supir — audit keamanan pra-go-live.
 *
 * Sebelumnya tautan /epod/{token} berlaku SELAMANYA. Tautan itu tinggal di
 * chat WhatsApp supir yang sebagian besar dari perusahaan jasa lain, bisa
 * diteruskan ke siapa saja, dan halaman di baliknya menyebut nama pelanggan
 * serta isi kiriman. Kolom ini membuat tautan mati sendiri; Logistik bisa
 * menerbitkan tautan baru dari halaman Surat Jalan.
 *
 * KOLOM, BUKAN DIHITUNG DARI shipped_at. shipped_at adalah awal argo SLA dan
 * tidak boleh bergeser; tautan yang diterbitkan ulang butuh masa berlaku baru
 * tanpa menyentuh kapan barangnya berangkat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->timestamp('epod_expires_at')->nullable()->after('epod_token');
        });

        // Tautan yang sudah beredar mendapat masa berlaku yang sama seperti
        // tautan baru, dihitung dari saat barangnya berangkat. Hanya yang
        // masih di jalan: yang sudah sampai diukur dari delivered_at, dan
        // menyentuh barisnya memicu ulang constraint sampai_wajib_berfoto pada
        // Surat Jalan lama yang dikonfirmasi sebelum foto diwajibkan.
        DB::table('delivery_notes')
            ->where('status', 'shipped')
            ->whereNotNull('epod_token')
            ->whereNotNull('shipped_at')
            ->update(['epod_expires_at' => DB::raw("shipped_at + interval '72 hours'")]);
    }

    public function down(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->dropColumn('epod_expires_at');
        });
    }
};
