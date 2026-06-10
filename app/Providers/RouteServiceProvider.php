<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/home';

    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('ledger-credentials', static function (Request $request): array {
            $username = Str::lower(trim((string) $request->input('username', '')));

            return [
                Limit::perMinute(5)->by(
                    'ledger-credentials:username:'.$request->ip().':'.hash('sha256', $username)
                ),
                Limit::perMinute(20)->by('ledger-credentials:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('ledger-passkey-registration', static function (Request $request): Limit {
            return Limit::perMinute(5)->by(self::passkeyRateLimitKey($request));
        });

        RateLimiter::for('ledger-passkey-authentication', static function (Request $request): Limit {
            return Limit::perMinute(10)->by(self::passkeyRateLimitKey($request));
        });

        RateLimiter::for('notion-webhook', static function (Request $request): Limit {
            return Limit::perMinute(10)->by('notion-webhook:'.$request->ip());
        });
    }

    private static function passkeyRateLimitKey(Request $request): string
    {
        $routeName = $request->route()?->getName() ?? 'unknown';

        return 'ledger-passkey:'.$routeName.':'.$request->ip();
    }
}
