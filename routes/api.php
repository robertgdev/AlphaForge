<?php

use App\AlphaForge\Http\Controllers\Api\BacktestController;
use App\Http\Controllers\AlphaForge\Data\DownloadController;
use App\Http\Controllers\AlphaForge\Data\ExchangesController;
use App\Http\Controllers\AlphaForge\Data\InspectController;
use App\Http\Controllers\AlphaForge\Data\SymbolsController;
use Illuminate\Support\Facades\Route;

// AlphaForge API routes
Route::prefix('alphaforge')->middleware(['auth:sanctum'])->group(function () {

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
