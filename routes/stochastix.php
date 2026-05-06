<?php

use App\Http\Controllers\AlphaForge\Data\DataAvailabilityController;
use App\Http\Controllers\AlphaForge\Data\DownloadController;
use App\Http\Controllers\AlphaForge\Data\ExchangesController;
use App\Http\Controllers\AlphaForge\Data\InspectController;
use App\Http\Controllers\AlphaForge\Data\SymbolsController;
use App\AlphaForge\Http\Controllers\Api\BacktestController;
use App\AlphaForge\Http\Controllers\Api\StrategyController;
use Illuminate\Support\Facades\Route;

// Strategy routes
Route::prefix('api/stochastix/strategies')->name('stochastix.strategies.')->group(function () {
    Route::get('/', [StrategyController::class, 'index'])->name('index');
    Route::get('/{alias}', [StrategyController::class, 'show'])->name('show');
});

// Backtest routes
Route::prefix('api/stochastix/backtests')->name('stochastix.backtests.')->group(function () {
    Route::get('/', [BacktestController::class, 'index'])->name('index');
    Route::post('/', [BacktestController::class, 'store'])->name('store');
    Route::get('/{id}', [BacktestController::class, 'show'])->name('show');
    Route::delete('/{id}', [BacktestController::class, 'destroy'])->name('destroy');
    Route::get('/{id}/statistics', [BacktestController::class, 'statistics'])->name('statistics');
});

// Data acquisition routes
Route::prefix('api/stochastix/data')->name('stochastix.data.')->group(function () {
    // Download routes
    Route::post('/download', [DownloadController::class, 'launch'])->name('download.launch');
    Route::delete('/download/{jobId}', [DownloadController::class, 'cancel'])->name('download.cancel');

    // Exchange and symbol routes
    Route::get('/exchanges', [ExchangesController::class, 'index'])->name('exchanges');
    Route::get('/symbols/{exchangeId}', [SymbolsController::class, 'index'])->name('symbols');

    // Data inspection routes
    Route::get('/inspect/{exchangeId}/{symbol}/{timeframe}', [InspectController::class, 'show'])->name('inspect');
});

// Data availability manifest
Route::get('/api/stochastix/data-availability', [DataAvailabilityController::class, 'index'])->name('stochastix.data.availability');

// Broadcasting routes for real-time progress
Route::prefix('api/stochastix')->name('stochastix.')->group(function () {
    Route::post('/broadcasting/auth', function () {
        return response()->json(['auth' => true]);
    })->middleware('auth')->name('broadcasting.auth');
});
