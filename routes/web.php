<?php

use App\Http\Controllers\LedgerAdjustmentController;
use App\Http\Controllers\LedgerAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LedgerAuthController::class, 'show'])->name('ledger.login.form');
Route::post('/logout', [LedgerAuthController::class, 'logout'])->name('ledger.logout');
Route::post('/login/credentials', [LedgerAuthController::class, 'authenticateWithCredentials'])
    ->middleware('throttle:ledger-credentials')
    ->name('ledger.credentials.login');

Route::prefix('webauthn')->group(function (): void {
    Route::middleware('ledger.auth')->group(function (): void {
        Route::post('/register/options', [LedgerAuthController::class, 'beginRegistration'])
            ->middleware('throttle:ledger-passkey-registration')
            ->name('ledger.passkey.register.options');
        Route::post('/register', [LedgerAuthController::class, 'finishRegistration'])
            ->middleware('throttle:ledger-passkey-registration')
            ->name('ledger.passkey.register.store');
    });
    Route::post('/authenticate/options', [LedgerAuthController::class, 'beginAuthentication'])
        ->middleware('throttle:ledger-passkey-authentication')
        ->name('ledger.passkey.login.options');
    Route::post('/authenticate', [LedgerAuthController::class, 'finishAuthentication'])
        ->middleware('throttle:ledger-passkey-authentication')
        ->name('ledger.passkey.login.verify');
});

Route::middleware('ledger.auth')->group(function () {
    Route::match(['get', 'post'], '/', [LedgerAdjustmentController::class, 'show'])->name('adjustment.form');
    Route::post('/calculate', [LedgerAdjustmentController::class, 'calculate'])->name('adjustment.calculate');
    Route::post('/register', [LedgerAdjustmentController::class, 'register'])->name('adjustment.register');
});
