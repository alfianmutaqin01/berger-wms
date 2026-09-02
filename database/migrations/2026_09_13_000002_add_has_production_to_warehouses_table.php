<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menandai gudang mana yang punya lini produksi.
 *
 * Keputusan pemilik produk: PRODUKSI HANYA ADA DI KARAWANG. Pekanbaru dan
 * Surabaya sekadar menyimpan stok; barang sampai ke sana lewat transfer dari
 * Karawang, bukan lewat inbound produksi.
 *
 * MENGAPA KOLOM, BUKAN DAFTAR KODE DI DALAM KODE PROGRAM
 * -------------------------------------------------------
 * `if ($warehouse->code === 'WH-01')` akan tetap benar sampai hari gudang
 * keempat dibuka atau kode gudang diganti — dan pada hari itu ia salah tanpa
 * satu pun test yang gagal. Sifat "punya produksi" adalah fakta tentang
 * gudangnya, jadi tempatnya melekat pada gudang itu.
 *
 * Default FALSE: gudang baru dianggap gudang penyimpanan sampai ada yang
 * menyatakan sebaliknya. Menu produksi yang keliru muncul lebih berbahaya
 * daripada menu yang perlu dinyalakan sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->boolean('has_production')->default(false)->after('territory_mode');
        });

        DB::table('warehouses')->where('code', 'WH-01')->update(['has_production' => true]);
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('has_production');
        });
    }
};
