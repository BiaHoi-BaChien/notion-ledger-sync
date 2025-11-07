<?php

use App\Http\Middleware\EnsureLedgerAuthenticated;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: __DIR__.'/../routes/health.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register global and route middleware groups as needed.

        $middleware->alias([
            'ledger.auth' => EnsureLedgerAuthenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Customize exception handling in App\Exceptions\Handler.
    })
    ->create();
