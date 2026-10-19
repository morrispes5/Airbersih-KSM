<?php

namespace App\Providers;

use App\Models\User;
use App\Support\DatabaseGuard;
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
        app()->setLocale('id');
        putenv('PGSSLROOTCERT='.config('airbersih.ca_path'));
        Gate::define('finance', fn (User $u) => $u->active && in_array($u->role, ['superadmin', 'keuangan'], true));
        Gate::define('admin', fn (User $u) => $u->active && $u->role === 'superadmin');
        if (config('airbersih.mode') === 'operational' && app()->runningInConsole() && ($_SERVER['argv'][1] ?? '') === 'key:generate') {
            throw new \RuntimeException('Kunci enkripsi operasional tidak boleh diganti otomatis karena diperlukan untuk pemulihan backup.');
        }
        if (app()->runningInConsole() && in_array($_SERVER['argv'][1] ?? '', ['package:discover', 'key:generate', 'config:clear', 'view:clear', 'route:list', 'list', 'about', 'test'], true)) {
            return;
        }
        DatabaseGuard::check();
    }
}
