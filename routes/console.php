<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Sweep stok kedaluwarsa — PRD §7.2.1 EXPIRY_SWEEP
|--------------------------------------------------------------------------
|
| Pukul 00:05 waktu aplikasi (APP_TIMEZONE=Asia/Jakarta), sesuai PRD.
| Menulis timezone secara eksplisit supaya tidak diam-diam bergeser bila
| suatu saat timezone aplikasi diubah.
|
| withoutOverlapping(): sweep yang berjalan lama tidak boleh ditimpa
| jalannya besok — dua proses menandai baris yang sama akan menghasilkan
| entri ledger ganda untuk satu kejadian.
*/
Schedule::command('stock:sweep-expired')
    ->dailyAt('00:05')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Lepas batch yang jangka waktu karantinanya sudah lewat
|--------------------------------------------------------------------------
|
| Permintaan pemilik produk: karantina berbasis hari, dan begitu lewat waktu
| batch itu OTOMATIS masuk lagi rekomendasi picking — tidak menunggu tindakan
| manual seperti DDP. Diselisihkan 5 menit dari sweep kedaluwarsa supaya
| keduanya tidak berebut baris pada detik yang sama.
*/
Schedule::command('stock:sweep-quarantine')
    ->dailyAt('00:10')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Lepas penanda "Dahulukan Keluar" dari batch yang isinya sudah habis
|--------------------------------------------------------------------------
|
| Permintaan pemilik produk: penandanya berlaku sampai batchnya habis, bukan
| sampai ada yang ingat mematikannya. Diselisihkan lagi 5 menit dari sweep
| karantina — sengaja BELAKANGAN, karena batch yang baru lepas karantina
| pagi itu bisa saja juga sedang bertanda dahulukan.
|
| Keterlambatan sehari tidak berakibat apa pun: batch kosong tidak pernah
| ikut dicalonkan keluar, jadi penanda yang tertinggal padanya tidak
| memengaruhi urutan siapa pun.
*/
Schedule::command('stock:sweep-priority')
    ->dailyAt('00:15')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Bersihkan sisa data: sesi mati, berkas impor telantar, riwayat login lama
|--------------------------------------------------------------------------
|
| TIAP JAM, bukan tengah malam. Versi sebelumnya adalah closure `daily()`
| yang hanya menyapu berkas impor — dan karena `daily()` berarti pukul 00:00,
| komputer pengembangan yang dimatikan malam hari tidak pernah menjalankannya.
| Berkas tanggal 1 September masih tergeletak di sana pada 4 September, berisi
| data pelanggan. Jadwal yang hanya berlaku bila mesin menyala pada satu menit
| tertentu bukanlah jadwal.
|
| Isinya pindah ke App\Console\Commands\BersihkanData supaya bisa dijalankan
| tangan saat dibutuhkan dan bisa diuji — closure di berkas rute tidak bisa
| keduanya.
*/
Schedule::command('wms:bersihkan')
    ->hourly()
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Buang log aktivitas yang umurnya sudah lewat 90 hari
|--------------------------------------------------------------------------
|
| Keputusan pemilik produk: log hilang sendiri setelah 90 hari. Angkanya ada
| di ActivityLog::UMUR_SIMPAN_HARI, bukan di sini — halaman log memakai nilai
| yang sama untuk memberitahu pembacanya sampai kapan riwayatnya tersimpan.
|
| Pukul 00:25, diselisihkan dari tiga sweep stok di atas. Bukan karena berat,
| melainkan supaya pekerjaan yang gagal mudah dikenali dari jamnya saja.
*/
Schedule::command('activity:purge')
    ->dailyAt('00:25')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Pengingat piutang untuk Manager (Fase 8)
|--------------------------------------------------------------------------
|
| Pukul 07:00, BUKAN dini hari seperti sweep di atas: yang dihasilkannya
| lonceng untuk dibaca orang, dan lonceng yang berbunyi pukul 00:30 sudah
| tertimbun notifikasi lain begitu Manager membuka sistem pagi harinya.
|
| Sekaligus membuat tagihan yang terlewat (pesanan tempo yang selesai sebelum
| modul Billing ada, atau yang gagal tercatat), jadi kekurangan seperti itu
| tidak pernah bertahan lebih dari sehari.
*/
Schedule::command('billing:ingatkan')
    ->dailyAt('07:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();
