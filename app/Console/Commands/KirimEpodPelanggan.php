<?php

namespace App\Console\Commands;

use App\Jobs\SendDeliveryNotification;
use App\Models\DeliveryNote;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Menerbitkan tautan konfirmasi untuk kiriman luar pulau, pada hari yang
 * diperkirakan barangnya sampai.
 *
 * KENAPA TOKENNYA BARU DIBUAT DI SINI. Tautan ePOD berlaku 72 jam sejak
 * diterbitkan (audit keamanan pra-go-live: tautannya menyebut nama pelanggan
 * serta isi kiriman dan tinggal di chat WhatsApp orang luar). Kiriman
 * antarpulau berada di perjalanan berminggu-minggu — tautan yang dibuat saat
 * barang berangkat sudah mati jauh sebelum kapalnya sandar. Menerbitkannya
 * pada hari perkiraan sampai menyelesaikan dua hal sekaligus: masa berlakunya
 * utuh saat dibutuhkan, dan selama barangnya di laut tidak ada satu pun
 * tautan hidup yang bisa diteruskan ke siapa saja.
 *
 * DIBACA DARI KEADAAN, BUKAN DARI KEJADIAN. Perintah ini tidak menerima
 * daftar apa pun; ia bertanya "kiriman mana yang sudah berangkat, memakai
 * konfirmasi pelanggan, tanggal perkiraannya sudah tiba, dan belum punya
 * tautan". Kalau server mati tiga hari, kiriman yang tanggalnya lewat tetap
 * terjaring pada jalan berikutnya — tidak ada satu pun yang hilang karena
 * penjadwal tidak sempat berjalan pada menit yang tepat.
 *
 * MENYERAH SETELAH BATAS TERTENTU BUKAN TUGASNYA. Kiriman yang tanggalnya
 * sudah lewat berhari-hari tetap dikirimi tautan, sebab keterlambatan kapal
 * justru hal yang paling lazim di sini. Yang menutup kiriman tak terjawab
 * adalah Logistik lewat penandaan sampai manual, seperti sebelumnya.
 */
class KirimEpodPelanggan extends Command
{
    protected $signature = 'epod:kirim-pelanggan {--dry-run : Tampilkan saja, jangan terbitkan tautan}';

    protected $description = 'Menerbitkan dan mengirim tautan konfirmasi ke pelanggan untuk kiriman luar pulau yang diperkirakan sampai hari ini';

    public function handle(): int
    {
        $kering = (bool) $this->option('dry-run');

        $kiriman = DeliveryNote::query()
            ->with('customer:id,name')
            ->where('status', DeliveryNote::STATUS_SHIPPED)
            ->where('epod_to_customer', true)
            ->whereNull('epod_token')
            ->whereNotNull('customer_phone')
            ->whereDate('eta_date', '<=', now()->toDateString())
            ->orderBy('eta_date')
            ->get();

        if ($kiriman->isEmpty()) {
            $this->info('Tidak ada kiriman luar pulau yang perlu dikirimi tautan hari ini.');

            return self::SUCCESS;
        }

        foreach ($kiriman as $note) {
            $terlambat = (int) $note->eta_date->diffInDays(now()->startOfDay());

            $this->line(sprintf(
                '%s — %s%s',
                $note->document_no,
                $note->customer?->name ?? 'pelanggan tidak dikenal',
                $terlambat > 0 ? sprintf(' (perkiraan sampai lewat %d hari)', $terlambat) : '',
            ));

            if ($kering) {
                continue;
            }

            /*
             * Token dan masa berlakunya disimpan LEBIH DULU, sebelum job
             * pengirim diantrekan. Job membaca dokumen dari basis data, dan
             * mengantrekannya sebelum tokennya tersimpan berarti job bisa
             * berjalan pada dokumen yang tautannya masih kosong — lalu diam
             * tanpa mengirim apa pun, dan perintah ini tidak akan pernah
             * mencobanya lagi karena tokennya kini terisi.
             */
            $note->forceFill([
                'epod_token' => Str::random(48),
                'epod_expires_at' => now()->addHours((int) config('wms.epod.berlaku_jam')),
                'notify_status' => DeliveryNote::NOTIFY_PENDING,
                'notify_attempts' => 0,
                'notify_error' => null,
            ])->save();

            SendDeliveryNotification::dispatch($note->id);
        }

        $this->info(sprintf(
            '%d tautan konfirmasi %s.',
            $kiriman->count(),
            $kering ? 'akan diterbitkan (dry-run)' : 'diterbitkan dan diantrekan ke pelanggan',
        ));

        return self::SUCCESS;
    }
}
