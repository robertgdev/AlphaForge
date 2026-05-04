<?php

use App\Http\Controllers\Stochastix\Data\DownloadController;
use App\Http\Controllers\Stochastix\Data\ExchangesController;
use App\Http\Controllers\Stochastix\Data\InspectController;
use App\Http\Controllers\Stochastix\Data\SymbolsController;
use App\Stochastix\Http\Controllers\Api\BacktestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group.
|
*/

// AlphaForge API routes
Route::prefix('stochastix')->middleware(['auth:sanctum'])->group(function () {

    // Backtest routes
    Route::prefix('backtests')->group(function () {
        Route::get('/', [BacktestController::class, 'index']);
        Route::post('/', [BacktestController::class, 'store']);
        Route::get('/{id}', [BacktestController::class, 'show']);
        Route::delete('/{id}', [BacktestController::class, 'destroy']);
        Route::get('/{id}/statistics', [BacktestController::class, 'statistics']);
    });

    // Data acquisition routes
    Route::prefix('data')->group(function () {
        // Download routes
        Route::post('/download', [DownloadController::class, 'launch']);
        Route::delete('/download/{jobId}', [DownloadController::class, 'cancel']);

        // Exchange routes
        Route::get('/exchanges', [ExchangesController::class, 'index']);

        // Symbol routes
        Route::get('/symbols/{exchangeId}', [SymbolsController::class, 'index']);

        // Inspect routes
        Route::get('/inspect/{exchange}/{symbol}/{timeframe}', [InspectController::class, 'show']);
    });
});
