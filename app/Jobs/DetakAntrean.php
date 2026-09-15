<?php

namespace App\Jobs;

use App\Support\Detak;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Bukti ada worker antrean yang hidup. Lihat App\Support\Detak.
 *
 * Sekali coba saja: detak yang gagal tidak perlu diulang, detak berikutnya
 * datang lima menit lagi.
 */
class DetakAntrean implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Detak::catat(Detak::ANTREAN);
    }
}
