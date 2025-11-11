<?php

namespace App\Providers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->ensureSqliteDatabaseFileExists();
        $this->ensureLedgerCredentialsTableExists();
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
