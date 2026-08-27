<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permission;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
