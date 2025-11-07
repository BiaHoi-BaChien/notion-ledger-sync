<?php

use App\Http\Controllers\LedgerAdjustmentController;
use App\Http\Controllers\LedgerAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LedgerAuthController::class, 'show'])->name('ledger.login.form');
Route::post('/logout', [LedgerAuthController::class, 'logout'])->name('ledger.logout');
Route::post('/login/pin', [LedgerAuthController::class, 'authenticateWithPin'])->name('ledger.pin.login');

Route::prefix('webauthn')->group(function (): void {
    Route::post('/register/options', [LedgerAuthController::class, 'beginRegistration'])->name('ledger.passkey.register.options');
    Route::post('/register', [LedgerAuthController::class, 'finishRegistration'])->name('ledger.passkey.register.store');
    Route::post('/authenticate/options', [LedgerAuthController::class, 'beginAuthentication'])->name('ledger.passkey.login.options');
    Route::post('/authenticate', [LedgerAuthController::class, 'finishAuthentication'])->name('ledger.passkey.login.verify');
});

Route::middleware('ledger.auth')->group(function () {
    Route::get('/', [LedgerAdjustmentController::class, 'show'])->name('adjustment.form');
    Route::post('/calculate', [LedgerAdjustmentController::class, 'calculate'])->name('adjustment.calculate');
    Route::post('/register', [LedgerAdjustmentController::class, 'register'])->name('adjustment.register');
});
