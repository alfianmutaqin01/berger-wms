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
