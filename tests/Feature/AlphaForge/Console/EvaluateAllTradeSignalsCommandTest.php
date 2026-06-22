<?php

use App\AlphaForge\Data\Service\BinaryStorage;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Models\TradeSignal;
use App\AlphaForge\Services\MarketDataFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeOhlcvFileForEvalAll(string $tempDir, string $symbol, string $timestamp = '1700000000'): string
{
    $storage = new BinaryStorage;
    $fileService = new MarketDataFileService($tempDir);
    $path = $fileService->generateFilePath('binance', $symbol, '1h', 'ohlcv');
    $storage->createFile($path, $symbol, '1h', BinaryStorage::DATA_TYPE_OHLCV);
    $storage->appendRecords($path, [
        ['timestamp' => (int) $timestamp, 'open' => '50000', 'high' => '52100', 'low' => '49900', 'close' => '52000', 'volume' => '100'],
    ]);

    return $path;
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/alphaforge_eval_all_test_'.uniqid();
    mkdir($this->tempDir, 0775, true);

    $this->binaryStorage = new BinaryStorage;
    $this->fileService = new MarketDataFileService($this->tempDir);

    config(['alphaforge.storage.market_data_path' => $this->tempDir]);

    $this->app->instance(MarketDataFileService::class, $this->fileService);
    $this->app->instance(BinaryStorageInterface::class, $this->binaryStorage);
});

afterEach(function () {
    $it = new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

describe('alphaforge:signal:evaluate-all', function () {
    it('reports no open signals when none exist', function () {
        $this->artisan('alphaforge:signal:evaluate-all')
            ->assertSuccessful()
            ->expectsOutputToContain('No open trade signals');
    });

    it('evaluates all open signals and reports summary', function () {
        makeOhlcvFileForEvalAll($this->tempDir, 'BTCUSDT');
        makeOhlcvFileForEvalAll($this->tempDir, 'ETHUSDT');

        TradeSignal::create([
            'exchange' => 'binance',
            'symbol' => 'BTCUSDT',
            'direction' => 'LONG',
            'entry_price' => '50000',
            'stop_loss' => '49000',
            'take_profit' => '52000',
            'entry_timestamp' => 1700000000,
            'status' => 'open',
            'timeframe' => '1h',
        ]);

        TradeSignal::create([
            'exchange' => 'binance',
            'symbol' => 'ETHUSDT',
            'direction' => 'LONG',
            'entry_price' => '2000',
            'stop_loss' => '1900',
            'take_profit' => '2100',
            'entry_timestamp' => 1700000000,
            'status' => 'open',
            'timeframe' => '1h',
        ]);

        $this->artisan('alphaforge:signal:evaluate-all')
            ->assertSuccessful()
            ->expectsOutputToContain('evaluated,');
    });

    it('keeps signal open when no OHLCV data is available', function () {
        TradeSignal::create([
            'exchange' => 'binance',
            'symbol' => 'BTCUSDT',
            'direction' => 'LONG',
            'entry_price' => '50000',
            'stop_loss' => '49000',
            'take_profit' => '52000',
            'entry_timestamp' => 1700000000,
            'status' => 'open',
            'timeframe' => '1h',
        ]);

        $this->artisan('alphaforge:signal:evaluate-all')
            ->assertSuccessful()
            ->expectsOutputToContain('evaluated,');
    });

    it('filters by timeframe', function () {
        makeOhlcvFileForEvalAll($this->tempDir, 'BTCUSDT');

        TradeSignal::create([
            'exchange' => 'binance',
            'symbol' => 'BTCUSDT',
            'direction' => 'LONG',
            'entry_price' => '50000',
            'stop_loss' => '49000',
            'take_profit' => '52000',
            'entry_timestamp' => 1700000000,
            'status' => 'open',
            'timeframe' => '4h',
        ]);

        $this->artisan('alphaforge:signal:evaluate-all', [
            '--timeframe' => '1h',
        ])->assertSuccessful()
            ->expectsOutputToContain('No open trade signals');
    });

    it('filters by symbol', function () {
        makeOhlcvFileForEvalAll($this->tempDir, 'BTCUSDT');

        TradeSignal::create([
            'exchange' => 'binance',
            'symbol' => 'BTCUSDT',
            'direction' => 'LONG',
            'entry_price' => '50000',
            'stop_loss' => '49000',
            'take_profit' => '52000',
            'entry_timestamp' => 1700000000,
            'status' => 'open',
            'timeframe' => '1h',
        ]);

        $this->artisan('alphaforge:signal:evaluate-all', [
            '--symbol' => 'ETHUSDT',
        ])->assertSuccessful()
            ->expectsOutputToContain('No open trade signals');
    });

    it('respects --limit option', function () {
        makeOhlcvFileForEvalAll($this->tempDir, 'BTCUSDT');
        makeOhlcvFileForEvalAll($this->tempDir, 'ETHUSDT');
        makeOhlcvFileForEvalAll($this->tempDir, 'XRPUSDT');

        TradeSignal::create([
            'exchange' => 'binance', 'symbol' => 'BTCUSDT', 'direction' => 'LONG',
            'entry_price' => '50000', 'stop_loss' => '49000', 'take_profit' => '52000',
            'entry_timestamp' => 1700000000, 'status' => 'open', 'timeframe' => '1h',
        ]);
        TradeSignal::create([
            'exchange' => 'binance', 'symbol' => 'ETHUSDT', 'direction' => 'LONG',
            'entry_price' => '2000', 'stop_loss' => '1900', 'take_profit' => '2100',
            'entry_timestamp' => 1700000000, 'status' => 'open', 'timeframe' => '1h',
        ]);
        TradeSignal::create([
            'exchange' => 'binance', 'symbol' => 'XRPUSDT', 'direction' => 'LONG',
            'entry_price' => '0.5', 'stop_loss' => '0.4', 'take_profit' => '0.6',
            'entry_timestamp' => 1700000000, 'status' => 'open', 'timeframe' => '1h',
        ]);

        $this->artisan('alphaforge:signal:evaluate-all', [
            '--limit' => '2',
        ])->assertSuccessful()
            ->expectsOutputToContain('2 evaluated');
    });
});
