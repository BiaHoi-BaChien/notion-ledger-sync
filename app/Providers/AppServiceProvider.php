<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->ensureSqliteDatabaseFileExists();
        $this->ensureLedgerCredentialsTableExists();
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

    private function ensureSqliteDatabaseFileExists(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        $database = config('database.connections.sqlite.database');

        if (! is_string($database) || $database === '' || $database === ':memory:') {
            return;
        }

        $database = $this->resolveDatabasePath($database);

        Config::set('database.connections.sqlite.database', $database);

        $directory = \dirname($database);

        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        if (! File::exists($database)) {
            File::put($database, '');
        }
    }

    private function ensureLedgerCredentialsTableExists(): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        if ($this->ledgerCredentialsTableExists()) {
            return;
        }

        Artisan::call('migrate', ['--force' => true]);
    }

    private function ledgerCredentialsTableExists(): bool
    {
        try {
            return Schema::hasTable('ledger_credentials');
        } catch (Throwable) {
            return false;
        }
    }

    private function resolveDatabasePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return base_path($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
