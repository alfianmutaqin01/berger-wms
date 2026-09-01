<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

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
| Sapu berkas pratinjau impor yang telantar
|--------------------------------------------------------------------------
|
| ImportController menyimpan berkas unggahan sementara, lalu menghapusnya
| saat impor dikonfirmasi ATAU dibatalkan. Pengguna yang menutup tab di
| halaman pratinjau tidak melakukan keduanya, sehingga berkasnya tertinggal
| selamanya — sepuluh berkas ~2,7 MB sudah menumpuk di sana dalam dua hari
| pemakaian, semuanya berisi data pelanggan.
|
| Ambang 1 hari jauh lebih lama daripada umur wajar satu sesi pratinjau,
| jadi tidak mungkin menghapus berkas yang masih ditunggu konfirmasinya.
*/
Schedule::call(function () {
    $disk = Storage::disk('local');
    $batas = now()->subDay()->getTimestamp();

    foreach ($disk->files('imports') as $berkas) {
        if ($disk->lastModified($berkas) < $batas) {
            $disk->delete($berkas);
        }
    }
})->daily()->name('sapu-berkas-impor')->withoutOverlapping();
