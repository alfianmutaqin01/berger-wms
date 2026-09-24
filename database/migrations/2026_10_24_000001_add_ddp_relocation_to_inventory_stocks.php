<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemindahan stok DDP ke rak DDP — permintaan pemilik produk, 24 Oktober 2026.
 *
 * MASALAHNYA. Sistem sudah menandai stok kedaluwarsa menjadi DDP setiap malam,
 * tetapi barangnya tetap berdiri di rak FG. Tidak ada satu pun layar yang bisa
 * menyebut "hari ini ada 3 batch yang harus dipindah ke rak DDP", sehingga
 * barang tidak layak jual bercampur dengan barang bagus di rak yang sama —
 * persis keadaan yang dihindari dengan menyediakan rak DDP di gudang.
 *
 * KENAPA HANYA TIGA KOLOM, BUKAN TABEL TUGAS SENDIRI. Daftar pekerjaannya
 * tidak perlu disimpan: "stok berstatus DDP yang lokasinya bukan rak DDP"
 * sudah merupakan daftar itu, dan ia selalu benar tanpa ada yang menandai
 * selesai. Barisnya hilang sendiri begitu barangnya ada di rak DDP. Yang
 * tidak bisa disimpulkan dari data lain hanya dua hal, dan itulah isi kolom
 * di bawah:
 *
 *   1. SUDAH DISERAHKAN KE OPERATOR ATAU BELUM. Logistik memeriksa dulu
 *      daftarnya, baru menyerahkannya; sebelum itu barisnya belum menjadi
 *      pekerjaan siapa pun.
 *   2. SUDAH PERNAH DIKABARKAN ATAU BELUM. Tanpa ini, pengabar berkala akan
 *      mengulang notifikasi yang sama setiap kali ia berjalan.
 *
 * TIDAK ADA KOLOM RAK TUJUAN. Rak DDP mana yang dipakai bukan keputusan yang
 * perlu diambil per baris: Logistik menandai rak DDP sekali saja di Master
 * Lokasi, dan saat memindahkan, operator hanya ditawari rak-rak bertanda itu.
 * Menetapkannya per baris berarti mengulang keputusan yang sama ratusan kali
 * untuk jawaban yang selalu sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->timestamp('ddp_assigned_at')->nullable()->after('ddp_reason');

            $table->foreignId('ddp_assigned_by')->nullable()->after('ddp_assigned_at')
                // nullOnDelete: akun Logistik yang dihapus tidak boleh ikut
                // menghapus baris stoknya. Tugasnya tetap berdiri, hanya
                // kehilangan nama penyerahnya.
                ->constrained('users')->nullOnDelete();

            $table->timestamp('ddp_notified_at')->nullable()->after('ddp_assigned_by');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ddp_assigned_by');
            $table->dropColumn(['ddp_assigned_at', 'ddp_notified_at']);
        });
    }
};
