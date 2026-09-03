<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Messaging\CloudApiWhatsAppSender;
use App\Support\Messaging\LogWhatsAppSender;
use App\Support\Messaging\ManualWhatsAppSender;
use App\Support\Messaging\WhatsAppSender;
use App\Support\Permission;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
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

            return match (true) {
                ($config['driver'] ?? 'manual') === 'log' => new LogWhatsAppSender,
                ($config['driver'] ?? 'manual') === 'cloud' && $lengkap => new CloudApiWhatsAppSender(
                    phoneNumberId: $config['phone_number_id'],
                    token: $config['token'],
                    template: $config['template'] ?? 'konfirmasi_pengiriman',
                    language: $config['language'] ?? 'id',
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
        Paginator::useBootstrapFive();
        $this->registerPermissionGates();
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
