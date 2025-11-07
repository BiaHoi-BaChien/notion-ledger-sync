<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/home';

    public function boot(): void
    {
        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            $webRoutes = Route::middleware('web');

            $prefix = config('app.url_prefix', '');

            if ($prefix !== '') {
                $webRoutes = $webRoutes->prefix($prefix);
            }

            $webRoutes->group(base_path('routes/web.php'));
        });
    }
}
