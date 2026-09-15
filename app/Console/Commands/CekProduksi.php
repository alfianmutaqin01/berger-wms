<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Support\Detak;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Pemeriksaan pra-go-live: apakah server ini benar-benar siap dipakai.
 *
 *   docker compose -f docker-compose.prod.yml exec -u www-data php-fpm php artisan wms:cek-produksi
 *
 * KENAPA PERINTAH, BUKAN DAFTAR PERIKSA DI DOKUMEN
 * ------------------------------------------------
 * Hampir semua kesalahan pemasangan di proyek ini bersifat SENYAP: halaman
 * tetap terbuka, dan kerusakannya baru muncul berhari-hari kemudian di tempat
 * lain. APP_DEBUG yang lupa dimatikan membocorkan isi .env di halaman galat.
 * MAIL_MAILER=log membuat email ke Sales "terkirim" ke berkas log. Kunci
 * reCAPTCHA yang kosong menolak login SEMUA orang. Sales tanpa alamat email
 * tidak pernah menerima kabar pesanannya. Dokumen dibaca sekali; perintah ini
 * bisa dijalankan ulang setiap kali .env diubah.
 *
 * GAGAL menghentikan go-live (kode keluar 1). PERINGATAN boleh disetujui
 * dengan sadar — mis. WhatsApp yang memang sengaja masih manual.
 */
class CekProduksi extends Command
{
    protected $signature = 'wms:cek-produksi';

    protected $description = 'Memeriksa konfigurasi dan data sebelum go-live (GAGAL = belum boleh dipakai)';

    /** @var list<array{0:string,1:string,2:string}> */
    private array $hasil = [];

    public function handle(): int
    {
        $this->periksaAplikasi();
        $this->periksaKeamanan();
        $this->periksaLayanan();
        $this->periksaPengiriman();
        $this->periksaPengguna();

        $this->table(['Status', 'Pemeriksaan', 'Keterangan'], array_map(
            fn (array $baris) => [match ($baris[0]) {
                'ok' => '<fg=green>OK</>',
                'awas' => '<fg=yellow>PERINGATAN</>',
                default => '<fg=red>GAGAL</>',
            }, $baris[1], $baris[2]],
            $this->hasil,
        ));

        $gagal = count(array_filter($this->hasil, fn ($b) => $b[0] === 'gagal'));
        $awas = count(array_filter($this->hasil, fn ($b) => $b[0] === 'awas'));

        if ($gagal > 0) {
            $this->components->error("{$gagal} pemeriksaan GAGAL — belum siap go-live.");

            return self::FAILURE;
        }

        $this->components->info($awas > 0
            ? "Siap, dengan {$awas} peringatan yang perlu disetujui dengan sadar."
            : 'Siap go-live.');

        return self::SUCCESS;
    }

    private function catat(bool|string $status, string $nama, string $keterangan): void
    {
        $this->hasil[] = [is_bool($status) ? ($status ? 'ok' : 'gagal') : $status, $nama, $keterangan];
    }

    private function periksaAplikasi(): void
    {
        $this->catat(app()->environment('production'), 'APP_ENV', 'Harus production — sekarang '.app()->environment());
        $this->catat(! config('app.debug'), 'APP_DEBUG', 'Harus false. Bila true, halaman galat membocorkan isi .env.');
        $this->catat(filled(config('app.key')), 'APP_KEY', 'Harus terisi (php artisan key:generate --show).');
        $this->catat(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL', 'Harus https:// — tautan di email dan WhatsApp dibangkitkan dari sini. Sekarang: '.config('app.url'));
        $this->catat(config('app.timezone') === 'Asia/Jakarta', 'Zona waktu', 'Harus Asia/Jakarta — batas pesanan 15:00 dan jatuh tempo dihitung dengan WIB.');

        $level = strtolower((string) config('logging.channels.daily.level', config('logging.channels.single.level', 'debug')));
        $this->catat($level === 'debug' ? 'awas' : 'ok', 'LOG_LEVEL', $level === 'debug'
            ? 'debug menulis sangat banyak di production; sarankan warning.'
            : "Level {$level}.");
    }

    private function periksaKeamanan(): void
    {
        $this->catat((bool) config('session.secure'), 'SESSION_SECURE_COOKIE', 'Harus true — cookie login hanya boleh lewat HTTPS.');

        $recaptcha = filled(config('services.recaptcha.site_key')) && filled(config('services.recaptcha.secret_key'));
        $this->catat($recaptcha, 'reCAPTCHA', $recaptcha
            ? 'Kunci terisi. Pastikan domain terdaftar di konsol reCAPTCHA.'
            : 'Kosong — di production login DITOLAK untuk semua orang.');

        $this->catat(filled(config('database.redis.default.password')), 'REDIS_PASSWORD', 'Harus terisi.');
        $this->catat(! in_array((string) config('database.connections.pgsql.password'), ['', 'secret', 'password'], true), 'DB_PASSWORD', 'Harus sandi acak, bukan sandi contoh.');
    }

    private function periksaLayanan(): void
    {
        try {
            DB::connection()->getPdo();
            $this->catat(true, 'Basis data', 'Tersambung.');

            $migrator = app('migrator');
            $berkas = array_keys($migrator->getMigrationFiles(database_path('migrations')));
            $sudah = $migrator->getRepository()->repositoryExists() ? $migrator->getRepository()->getRan() : [];
            $tertunda = count(array_diff($berkas, $sudah));
            $this->catat($tertunda === 0, 'Migrasi', $tertunda === 0 ? 'Semua sudah dijalankan.' : "{$tertunda} migrasi belum dijalankan (php artisan migrate --force).");

            // Pengguna basis data aplikasi BUKAN superuser: aplikasi yang
            // tembus hanya membawa tabel WMS, bukan seluruh server PostgreSQL
            // (superuser bisa membaca berkas server dan menjalankan perintah
            // sistem lewat COPY ... PROGRAM). Lihat docker/postgres/init.
            $super = (bool) DB::scalar('SELECT rolsuper FROM pg_roles WHERE rolname = current_user');
            $this->catat(! $super, 'Hak pengguna basis data', $super
                ? 'DB_USERNAME adalah superuser PostgreSQL. Pakai pengguna aplikasi terpisah (docs/9 langkah 4).'
                : 'Bukan superuser.');
        } catch (Throwable $e) {
            $this->catat(false, 'Basis data', 'Tidak tersambung: '.$e->getMessage());
        }

        try {
            Redis::ping();
            $this->catat(true, 'Redis', 'Tersambung.');
        } catch (Throwable $e) {
            $this->catat(false, 'Redis', 'Tidak tersambung: '.$e->getMessage());
        }

        foreach (['queue.default' => 'QUEUE_CONNECTION', 'cache.default' => 'CACHE_STORE', 'session.driver' => 'SESSION_DRIVER'] as $kunci => $nama) {
            $nilai = (string) config($kunci);
            $this->catat($nilai === 'redis', $nama, "Harus redis — sekarang {$nilai}.");
        }

        $this->catat(is_writable(storage_path('app')), 'Folder storage', 'Harus bisa ditulis www-data.');
        $this->catat(is_link(public_path('storage')) || is_dir(public_path('storage')), 'Tautan public/storage', 'Foto profil tidak tampil tanpanya (php artisan storage:link).');

        foreach ([Detak::PENJADWAL => 'Penjadwal', Detak::ANTREAN => 'Worker antrean'] as $kunci => $nama) {
            $segar = Detak::segar($kunci);
            $this->catat($segar === null ? 'awas' : $segar, $nama, match ($segar) {
                true => 'Berdetak.',
                false => 'Detaknya basi — container-nya berhenti?',
                null => 'Belum pernah berdetak. Tunggu 5 menit setelah container jalan, lalu periksa lagi.',
            });
        }
    }

    private function periksaPengiriman(): void
    {
        $mailer = (string) config('mail.default');
        $this->catat($mailer === 'smtp', 'MAIL_MAILER', $mailer === 'smtp'
            ? 'smtp.'
            : "{$mailer} — email ke Sales TIDAK benar-benar terkirim.");

        if ($mailer === 'smtp') {
            $this->catat(filled(config('mail.mailers.smtp.password')), 'MAIL_PASSWORD', 'App Password Gmail harus terisi.');
            $this->catat(
                config('mail.from.address') === config('mail.mailers.smtp.username'),
                'MAIL_FROM_ADDRESS',
                'Harus sama dengan MAIL_USERNAME, atau Gmail menimpanya dan email masuk spam.',
            );
        }

        $wa = (string) config('services.whatsapp.driver');
        $this->catat($wa === 'log' ? false : ($wa === 'manual' ? 'awas' : 'ok'), 'WHATSAPP_DRIVER', match ($wa) {
            'log' => 'log — pesan WhatsApp hanya ditulis ke log.',
            'manual' => 'manual — kabar barang sampai ke Sales tidak keluar lewat WhatsApp (tetap lewat lonceng dan email).',
            default => $wa,
        });
    }

    private function periksaPengguna(): void
    {
        try {
            $superAdmin = User::query()->where('is_active', true)
                ->whereHas('role', fn ($q) => $q->where('slug', Role::SUPER_ADMIN))->count();
            $this->catat($superAdmin > 0, 'Super Admin aktif', "{$superAdmin} akun.");

            $salesTanpaEmail = User::query()->where('is_active', true)
                ->whereHas('role', fn ($q) => $q->where('slug', Role::SALES))
                ->get(['full_name', 'email'])
                ->reject(fn (User $u) => filter_var($u->email, FILTER_VALIDATE_EMAIL) !== false)
                ->pluck('full_name');
            $this->catat($salesTanpaEmail->isEmpty() ? 'ok' : 'awas', 'Email Sales', $salesTanpaEmail->isEmpty()
                ? 'Semua Sales aktif punya alamat email yang sah.'
                : 'Tidak akan menerima email kabar pesanan: '.$salesTanpaEmail->implode(', '));

            // Sandi bawaan seeder pengembangan. Dicek satu per satu karena
            // hash bcrypt tidak bisa dicari lewat query — jumlah akun aktif
            // di sistem ini puluhan, jadi beberapa detik masih wajar.
            $sandiBawaan = User::query()->where('is_active', true)->get(['email', 'password'])
                ->filter(fn (User $u) => Hash::check('password', $u->password))
                ->pluck('email');
            $this->catat($sandiBawaan->isEmpty(), 'Sandi bawaan', $sandiBawaan->isEmpty()
                ? 'Tidak ada akun aktif bersandi "password".'
                : 'Akun aktif bersandi "password" — ganti atau nonaktifkan: '.$sandiBawaan->implode(', '));
        } catch (Throwable $e) {
            $this->catat(false, 'Pengguna', 'Tidak bisa dibaca: '.$e->getMessage());
        }
    }
}
