<?php

use App\Http\Controllers\LedgerAdjustmentController;
use App\Http\Controllers\LedgerAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LedgerAuthController::class, 'show'])->name('ledger.login.form');
Route::post('/login', [LedgerAuthController::class, 'authenticate'])->name('ledger.login');
Route::post('/logout', [LedgerAuthController::class, 'logout'])->name('ledger.logout');

Route::middleware('ledger.auth')->group(function () {
    Route::get('/', [LedgerAdjustmentController::class, 'show'])->name('adjustment.form');
    Route::post('/calculate', [LedgerAdjustmentController::class, 'calculate'])->name('adjustment.calculate');
    Route::post('/register', [LedgerAdjustmentController::class, 'register'])->name('adjustment.register');
});
