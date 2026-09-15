<?php

namespace App\Providers;

use App\Models\Notification;
use App\Models\User;
use App\Support\Messaging\CloudApiWhatsAppSender;
use App\Support\Messaging\FonnteWhatsAppSender;
use App\Support\Messaging\LogWhatsAppSender;
use App\Support\Messaging\ManualWhatsAppSender;
use App\Support\Messaging\WhatsAppSender;
use App\Support\Permission;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerWhatsAppSender();
    }

    /**
     * Penyedia WhatsApp dipilih dari konfigurasi, bukan dari kode pemanggil.
     *
     * Seluruh sistem meminta WhatsAppSender; yang menentukan penyedianya
     * hanya satu nilai di config/services.php. Berpindah dari mode manual ke
     * Cloud API resmi Meta karena itu tidak menyentuh satu baris pun di alur
     * pengiriman barang.
     *
     * Bila mode 'cloud' dipilih tetapi kredensialnya belum lengkap, sistem
     * TURUN ke mode manual alih-alih melempar galat. Alasannya: kredensial
     * yang belum terisi adalah keadaan yang sangat mungkin terjadi (menunggu
     * verifikasi Meta), dan matinya harus berupa "kirim manual dulu", bukan
     * seluruh halaman Surat Jalan yang meledak.
     */
    private function registerWhatsAppSender(): void
    {
        $this->app->singleton(WhatsAppSender::class, function () {
            $config = config('services.whatsapp');

            $lengkap = filled($config['phone_number_id'] ?? null) && filled($config['token'] ?? null);

            $driver = $config['driver'] ?? 'manual';

            // Aturan yang sama untuk Fonnte: token kosong berarti turun ke
            // manual, bukan meledak.
            return match (true) {
                $driver === 'log' => new LogWhatsAppSender,
                $driver === 'cloud' && $lengkap => new CloudApiWhatsAppSender(
                    phoneNumberId: $config['phone_number_id'],
                    token: $config['token'],
                    templates: $config['templates'] ?? [],
                    language: $config['language'] ?? 'id',
                ),
                $driver === 'fonnte' && filled($config['fonnte_token'] ?? null) => new FonnteWhatsAppSender(
                    token: $config['fonnte_token'],
                ),
                default => new ManualWhatsAppSender,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // N+1 DITANGKAP SAAT DIKEMBANGKAN, BUKAN SAAT LAMBAT DI SERVER.
        // Relasi yang dimuat satu per satu di dalam perulangan (daftar 1.800
        // customer, 300 baris stok) tidak terasa dengan data uji yang sedikit,
        // lalu membuat halaman butuh puluhan detik dengan data sungguhan.
        // Di luar production pemuatan seperti itu dilempar sebagai galat —
        // seluruh suite test ikut menjaganya. Di production dibiarkan: halaman
        // yang lambat masih lebih baik daripada halaman yang mati.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Tautan dan redirect selalu https bila aplikasinya dipasang di https,
        // termasuk yang dibangkitkan dari antrean (tautan WhatsApp supir,
        // email ke Sales) yang tidak punya permintaan HTTP untuk ditiru.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // BATAS POST /login PER IP DI LARAVEL, bukan hanya di nginx. Batas
        // nginx hilang begitu aplikasi dipasang di belakang proxy lain atau
        // dijalankan tanpa nginx; batas ini ikut ke mana pun kodenya pergi.
        // 20/menit: satu IP kantor dipakai seluruh gudang saat ganti shift.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));

        Paginator::useBootstrapFive();
        $this->registerPermissionGates();
        $this->registerNotificationBell();
    }

    /**
     * Mengisi lonceng notifikasi di kedua layout.
     *
     * VIEW COMPOSER, bukan dikirim dari tiap controller. Loncengnya muncul di
     * SETIAP halaman kedua portal; menitipkannya ke controller berarti puluhan
     * tempat yang harus ingat mengirim dua variabel yang sama, dan halaman
     * yang lupa akan meledak saat merender navbar — bukan saat fiturnya
     * dipakai.
     *
     * Dibatasi pada partial navbar-nya saja, supaya query ini tidak ikut jalan
     * pada view yang dirender di luar permintaan HTTP (mis. e-mail atau
     * perintah baris perintah).
     */
    private function registerNotificationBell(): void
    {
        View::composer('partials.navbar-top', function ($view) {
            $userId = Auth::id();

            $view->with([
                'loncengBelumDibaca' => $userId === null ? 0 : Notification::query()
                    ->milik($userId)
                    ->belumDibaca()
                    ->count(),
                'loncengTerbaru' => $userId === null ? collect() : Notification::query()
                    ->milik($userId)
                    ->latest('created_at')
                    ->latest('id')
                    ->limit(Notification::JUMLAH_DI_LONCENG)
                    ->get(),
            ]);
        });
    }

    /**
     * Mendaftarkan satu Gate untuk tiap fitur di App\Support\Permission.
     *
     * Didaftarkan lewat loop, bukan ditulis satu per satu, supaya menambah
     * fitur baru cukup dengan menambah satu baris di matriks — tidak mungkin
     * ada fitur yang punya entri matriks tapi lupa dibuatkan Gate-nya.
     */
    private function registerPermissionGates(): void
    {
        foreach (Permission::features() as $feature) {
            Gate::define($feature, fn (User $user) => Permission::allows($user, $feature));
        }
    }
}
