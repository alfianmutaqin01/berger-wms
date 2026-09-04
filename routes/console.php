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
