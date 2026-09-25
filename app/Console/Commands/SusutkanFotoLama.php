<?php

namespace App\Console\Commands;

use App\Models\DeliveryNote;
use App\Models\DeliveryProof;
use App\Support\Imaji\SusutkanFoto;
use Illuminate\Console\Command;

/**
 * Menyusutkan foto yang sudah telanjur tersimpan penuh ukuran.
 *
 * SEKALI JALAN, bukan terjadwal. Foto baru sudah disusutkan saat diunggah;
 * perintah ini hanya untuk yang masuk sebelum penyusutan ada. Menjadwalkannya
 * tiap hari hanya membaca ulang ribuan berkas yang sudah kecil.
 *
 * DIJALANKAN SAAT SEPI. Setiap foto didekode penuh ke memori, dan seribu foto
 * berarti seribu kali pekerjaan itu. Di jam kerja ia bersaing dengan permintaan
 * halaman yang sedang dipakai orang.
 */
class SusutkanFotoLama extends Command
{
    protected $signature = 'wms:susutkan-foto
        {--dry-run : Hanya menghitung, tidak mengubah satu berkas pun}
        {--batas=0 : Berhenti setelah sekian berkas; 0 berarti semuanya}';

    protected $description = 'Menyusutkan foto bukti lama yang tersimpan penuh ukuran';

    public function __construct(private readonly SusutkanFoto $penyusut)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $uji = (bool) $this->option('dry-run');
        $batas = (int) $this->option('batas');

        if ($uji) {
            $this->components->warn('Mode uji — tidak ada berkas yang diubah.');
        }

        $sebelum = 0;
        $sesudah = 0;
        $diubah = 0;
        $dilewati = 0;
        $terproses = 0;

        /*
         * Dua sumber foto, satu perintah. Keduanya diperlakukan sama karena
         * masalahnya sama: berkas penuh ukuran di disk yang sama.
         */
        $sumber = [
            'Bukti Surat Jalan' => DeliveryProof::query()
                ->whereNotNull('path')
                ->select(['id', 'path', 'size', 'mime'])
                ->lazyById(100),
            'Foto barang sampai' => DeliveryNote::query()
                ->whereNotNull('arrival_photo_path')
                ->select(['id', 'arrival_photo_path', 'arrival_photo_size', 'arrival_photo_mime'])
                ->lazyById(100),
        ];

        foreach ($sumber as $judul => $baris) {
            $this->components->info($judul);

            foreach ($baris as $satu) {
                if ($batas > 0 && $terproses >= $batas) {
                    break 2;
                }

                $terproses++;

                $bukti = $satu instanceof DeliveryProof;
                $path = $bukti ? $satu->path : $satu->arrival_photo_path;
                $lama = (int) ($bukti ? $satu->size : $satu->arrival_photo_size);
                $mime = ($bukti ? $satu->mime : $satu->arrival_photo_mime) ?: 'image/jpeg';

                $sebelum += $lama;

                if ($uji) {
                    $sesudah += $lama;
                    $dilewati++;

                    continue;
                }

                $baru = $this->penyusut->susutkanTersimpan($path, 'local', $mime);

                if ($baru === null) {
                    $sesudah += $lama;
                    $dilewati++;

                    continue;
                }

                // Kolom ukuran ikut dibetulkan. Angka lama yang dibiarkan
                // membuat laporan pemakaian tempat berbohong justru setelah
                // pekerjaan ini selesai.
                $bukti
                    ? $satu->forceFill(['size' => $baru])->save()
                    : $satu->forceFill(['arrival_photo_size' => $baru])->save();

                $sesudah += $baru;
                $diubah++;
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('Berkas diperiksa', (string) $terproses);
        $this->components->twoColumnDetail('Disusutkan', (string) $diubah);
        $this->components->twoColumnDetail('Dilewati', (string) $dilewati);
        $this->components->twoColumnDetail('Sebelum', $this->mb($sebelum));
        $this->components->twoColumnDetail('Sesudah', $this->mb($sesudah));
        $this->components->twoColumnDetail(
            'Hemat',
            $this->mb($sebelum - $sesudah).($sebelum > 0
                ? ' ('.number_format(($sebelum - $sesudah) / $sebelum * 100, 1).'%)'
                : ''),
        );

        return self::SUCCESS;
    }

    private function mb(int $byte): string
    {
        return number_format($byte / 1048576, 1).' MB';
    }
}
