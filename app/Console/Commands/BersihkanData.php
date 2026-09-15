<?php

namespace App\Console\Commands;

use App\Models\LoginAttempt;
use App\Models\UserSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Menyapu sisa-sisa yang tidak dibersihkan alur normal.
 *
 * SATU PERINTAH untuk tiga hal yang tampaknya tidak berhubungan, dan itu
 * disengaja: ketiganya punya sebab yang sama — pekerjaan yang berakhir tanpa
 * ada yang menutup pintunya. Menyebar ketiganya ke tiga jadwal berbeda
 * membuat tidak ada satu tempat pun yang bisa dilihat untuk menjawab
 * "kenapa sampahnya menumpuk".
 *
 * DIJALANKAN TIAP JAM, bukan tengah malam. Penyapu berkas impor sebelumnya
 * berjadwal `daily()` alias pukul 00:00; komputer pengembangan yang dimatikan
 * malam hari tidak pernah menjalankannya, dan berkas 1 September masih ada di
 * sana pada 4 September. Jadwal yang hanya berlaku bila mesin menyala pada
 * satu menit tertentu bukan jadwal.
 */
class BersihkanData extends Command
{
    protected $signature = 'wms:bersihkan
        {--jam-berkas=2 : Umur berkas impor telantar (jam) sebelum dihapus}
        {--hari-login=90 : Umur riwayat percobaan login (hari) sebelum dipangkas}';

    protected $description = 'Menyapu sesi mati, berkas impor telantar, dan riwayat login lama';

    public function handle(): int
    {
        $this->components->info('Membersihkan sisa data…');

        $sesi = $this->sapuSesiMati();
        $berkas = $this->sapuBerkasImpor((int) $this->option('jam-berkas'));
        $login = $this->pangkasRiwayatLogin((int) $this->option('hari-login'));

        $this->components->twoColumnDetail('Sesi mati dihapus', (string) $sesi);
        $this->components->twoColumnDetail('Berkas impor telantar dihapus', (string) $berkas);
        $this->components->twoColumnDetail('Riwayat login dipangkas', (string) $login);

        return self::SUCCESS;
    }

    /**
     * Sesi yang sudah melewati idle timeout 1 jam.
     *
     * TrackUserSession menghapusnya hanya kalau penggunanya KEMBALI dan
     * membuka satu halaman. Yang menutup browser dan tidak pernah kembali
     * meninggalkan barisnya selamanya — 5 dari 6 baris di basis data
     * pengembangan sudah mati, tertua empat hari. Baris mati itu ikut dihitung
     * oleh batas "maksimal 2 perangkat", sehingga pengguna bisa terusir dari
     * perangkatnya sendiri karena dua bangkai sesi.
     *
     * Ambangnya SENGAJA sama dengan idle timeout: menghapus lebih cepat berarti
     * memutus sesi yang menurut aturan masih hidup.
     */
    private function sapuSesiMati(): int
    {
        return UserSession::query()
            ->where('last_activity_at', '<', now()->subHour())
            ->delete();
    }

    /**
     * Berkas pratinjau impor yang tidak pernah dikonfirmasi maupun dibatalkan.
     *
     * Isinya data pelanggan dan stok, jadi umurnya dijaga pendek. Dua jam jauh
     * lebih lama daripada satu sesi pratinjau yang wajar, sehingga tidak
     * mungkin menghapus berkas yang masih ditunggu konfirmasinya.
     */
    private function sapuBerkasImpor(int $jam): int
    {
        $disk = Storage::disk('local');
        $batas = now()->subHours(max(1, $jam))->getTimestamp();
        $dihapus = 0;

        foreach ($disk->files('imports') as $berkas) {
            if ($disk->lastModified($berkas) < $batas) {
                $disk->delete($berkas);
                $dihapus++;
            }
        }

        return $dihapus;
    }

    /**
     * Riwayat percobaan login yang sudah tidak dipakai analisis apa pun.
     *
     * Tabel ini bertambah pada SETIAP percobaan login, berhasil maupun gagal,
     * dan tidak ada yang pernah memangkasnya. Di produksi dengan puluhan
     * pengguna, ia tumbuh selamanya.
     *
     * 90 hari dipilih karena itu jangkauan penelusuran yang masih berguna
     * (pola serangan berulang, akun yang sering terkunci) tanpa menyimpan
     * alamat IP orang lebih lama daripada perlunya.
     */
    private function pangkasRiwayatLogin(int $hari): int
    {
        return LoginAttempt::query()
            ->where('created_at', '<', now()->subDays(max(1, $hari)))
            ->delete();
    }
}
