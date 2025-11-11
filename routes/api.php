<?php

use App\Http\Controllers\NotionMonthlySumController;
use Illuminate\Support\Facades\Route;

Route::post('/notion_webhook/monthly-sum', [NotionMonthlySumController::class, 'handle']);
