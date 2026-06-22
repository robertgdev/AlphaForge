<?php

use App\AlphaForge\Data\Service\BinaryStorage;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Models\TradeSignal;
use App\AlphaForge\Services\MarketDataFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createOhlcvFile(string $path, string $symbol, string $timeframe, array $records): void
{
    $storage = new BinaryStorage;
    $storage->createFile($path, $symbol, $timeframe, BinaryStorage::DATA_TYPE_OHLCV);
    $storage->appendRecords($path, $records);
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/alphaforge_signal_test_'.uniqid();
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

describe('alphaforge:signal:evaluate', function () {
    it('creates signal and evaluates it as open when no OHLCV data exists', function () {
        $this->artisan('alphaforge:signal:evaluate', [
            'direction' => 'long',
            'exchange' => 'binance',
            'symbol' => 'BTCUSDT',
            'entry-price' => '50000',
            'stop-loss' => '49000',
            'take-profit' => '52000',
            '--entry-timestamp' => '1700000000',
            '--timeframe' => '1h',
        ])->assertSuccessful();

        $signal = TradeSignal::first();
        expect($signal)->not->toBeNull();
        expect($signal->status)->toBe('open');
        expect($signal->symbol)->toBe('BTCUSDT');
        expect($signal->direction)->toBe('LONG');
    });

    it('evaluates signal as winner when TP is hit', function () {
        $symbolMarketData = 'BTCUSDT';
        $path = $this->fileService->generateFilePath('binance', $symbolMarketData, '1h', 'ohlcv');

        createOhlcvFile($path, $symbolMarketData, '1h', [
            ['timestamp' => 1700000000, 'open' => '50000', 'high' => '51000', 'low' => '49900', 'close' => '50500', 'volume' => '100'],
            ['timestamp' => 1700003600, 'open' => '50500', 'high' => '52100', 'low' => '50400', 'close' => '52000', 'volume' => '200'],
        ]);

        $this->artisan('alphaforge:signal:evaluate', [
            'direction' => 'long',
            'exchange' => 'binance',
            'symbol' => 'BTCUSDT',
            'entry-price' => '50000',
            'stop-loss' => '49000',
            'take-profit' => '52000',
            '--entry-timestamp' => '1700000000',
            '--timeframe' => '1h',
        ])->assertSuccessful();

        $signal = TradeSignal::first();
        expect($signal->status)->toBe('winner');
        expect($signal->exit_reason)->toBe('take_profit');
        expect((float) $signal->exit_price)->toBe(52000.0);
    });

    it('evaluates signal as loser when SL is hit', function () {
        $symbolMarketData = 'ETHUSDT';
        $path = $this->fileService->generateFilePath('binance', $symbolMarketData, '1h', 'ohlcv');

        createOhlcvFile($path, $symbolMarketData, '1h', [
            ['timestamp' => 1700000000, 'open' => '2000', 'high' => '2010', 'low' => '1950', 'close' => '1980', 'volume' => '100'],
        ]);

        $this->artisan('alphaforge:signal:evaluate', [
            'direction' => 'long',
            'exchange' => 'binance',
            'symbol' => 'ETHUSDT',
            'entry-price' => '2000',
            'stop-loss' => '1960',
            'take-profit' => '2100',
            '--entry-timestamp' => '1700000000',
            '--timeframe' => '1h',
        ])->assertSuccessful();

        $signal = TradeSignal::first();
        expect($signal->status)->toBe('loser');
        expect($signal->exit_reason)->toBe('stop_loss');
        expect((float) $signal->exit_price)->toBe(1960.0);
    });

    it('handles trailing stop correctly', function () {
        $symbolMarketData = 'BTCUSDT';
        $path = $this->fileService->generateFilePath('binance', $symbolMarketData, '1h', 'ohlcv');

        createOhlcvFile($path, $symbolMarketData, '1h', [
            ['timestamp' => 1700000000, 'open' => '50000', 'high' => '50500', 'low' => '49800', 'close' => '50300', 'volume' => '100'],
            ['timestamp' => 1700003600, 'open' => '50300', 'high' => '50400', 'low' => '47500', 'close' => '47800', 'volume' => '200'],
        ]);

        $this->artisan('alphaforge:signal:evaluate', [
            'direction' => 'long',
            'exchange' => 'binance',
            'symbol' => 'BTCUSDT',
            'entry-price' => '50000',
            'stop-loss' => '1',
            'take-profit' => '999999',
            '--trailing-percent' => '5',
            '--entry-timestamp' => '1700000000',
            '--timeframe' => '1h',
        ])->assertSuccessful();

        $signal = TradeSignal::first();
        expect($signal->status)->toBe('loser');
        expect($signal->exit_reason)->toBe('trailing_stop');
    });

    it('rejects invalid direction', function () {
        $this->artisan('alphaforge:signal:evaluate', [
            'direction' => 'sideways',
            'exchange' => 'binance',
            'symbol' => 'BTCUSDT',
            'entry-price' => '50000',
            'stop-loss' => '49000',
            'take-profit' => '52000',
            '--timeframe' => '1h',
        ])->assertFailed();
    });

    it('rejects invalid timeframe', function () {
        $this->artisan('alphaforge:signal:evaluate', [
            'direction' => 'long',
            'exchange' => 'binance',
            'symbol' => 'BTCUSDT',
            'entry-price' => '50000',
            'stop-loss' => '49000',
            'take-profit' => '52000',
            '--timeframe' => '99z',
        ])->assertFailed();
    });

    it('rejects non-positive entry price', function () {
        $this->artisan('alphaforge:signal:evaluate', [
            'direction' => 'long',
            'exchange' => 'binance',
            'symbol' => 'BTCUSDT',
            'entry-price' => '-500',
            'stop-loss' => '49000',
            'take-profit' => '52000',
            '--timeframe' => '1h',
        ])->assertFailed();
    });
});

describe('alphaforge:signal:evaluate --list-open', function () {
    it('shows no open signals when none exist', function () {
        $this->artisan('alphaforge:signal:evaluate --list-open')
            ->assertSuccessful()
            ->expectsOutputToContain('No open trade signals');
    });

    it('displays open signals in a table', function () {
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

        $this->artisan('alphaforge:signal:evaluate --list-open')
            ->assertSuccessful()
            ->expectsOutputToContain('Open Trade Signals')
            ->expectsOutputToContain('BTCUSDT');
    });
});

describe('alphaforge:signal:evaluate --re-evaluate', function () {
    it('re-evaluates an existing open signal', function () {
        $signal = TradeSignal::create([
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

        $path = $this->fileService->generateFilePath('binance', 'BTCUSDT', '1h', 'ohlcv');
        createOhlcvFile($path, 'BTCUSDT', '1h', [
            ['timestamp' => 1700000000, 'open' => '50000', 'high' => '52100', 'low' => '49900', 'close' => '52000', 'volume' => '100'],
        ]);

        $this->artisan('alphaforge:signal:evaluate', [
            '--re-evaluate' => true,
            '--signal-id' => $signal->id,
        ])->assertSuccessful();

        $signal->refresh();
        expect($signal->status)->toBe('winner');
        expect($signal->exit_reason)->toBe('take_profit');
    });

    it('shows message when signal is already closed', function () {
        $signal = TradeSignal::create([
            'exchange' => 'binance',
            'symbol' => 'BTCUSDT',
            'direction' => 'LONG',
            'entry_price' => '50000',
            'stop_loss' => '49000',
            'take_profit' => '52000',
            'entry_timestamp' => 1700000000,
            'status' => 'winner',
            'exit_price' => '52000',
            'exit_timestamp' => 1700000100,
            'exit_reason' => 'take_profit',
            'profit_loss_pct' => '4',
            'profit_loss_abs' => '2000',
            'timeframe' => '1h',
        ]);

        $this->artisan('alphaforge:signal:evaluate', [
            '--re-evaluate' => true,
            '--signal-id' => $signal->id,
        ])->assertSuccessful()
            ->expectsOutputToContain('already closed');
    });

    it('fails when signal ID is not provided', function () {
        $this->artisan('alphaforge:signal:evaluate', [
            '--re-evaluate' => true,
        ])->assertFailed();
    });

    it('fails when signal ID does not exist', function () {
        $this->artisan('alphaforge:signal:evaluate', [
            '--re-evaluate' => true,
            '--signal-id' => '019a0000-0000-7000-8000-non-existent',
        ])->assertFailed();
    });
});

describe('alphaforge:signal:evaluate SHORT direction', function () {
    it('evaluates SHORT TP hit correctly', function () {
        $path = $this->fileService->generateFilePath('binance', 'BTCUSDT', '1h', 'ohlcv');

        createOhlcvFile($path, 'BTCUSDT', '1h', [
            ['timestamp' => 1700000000, 'open' => '50000', 'high' => '50100', 'low' => '47900', 'close' => '48000', 'volume' => '100'],
        ]);

        $this->artisan('alphaforge:signal:evaluate', [
            'direction' => 'short',
            'exchange' => 'binance',
            'symbol' => 'BTCUSDT',
            'entry-price' => '50000',
            'stop-loss' => '52000',
            'take-profit' => '48000',
            '--entry-timestamp' => '1700000000',
            '--timeframe' => '1h',
        ])->assertSuccessful();

        $signal = TradeSignal::first();
        expect($signal->status)->toBe('winner');
        expect($signal->exit_reason)->toBe('take_profit');
        expect((float) $signal->exit_price)->toBe(48000.0);
    });

    it('evaluates SHORT SL hit correctly', function () {
        $path = $this->fileService->generateFilePath('binance', 'ETHUSDT', '1h', 'ohlcv');

        createOhlcvFile($path, 'ETHUSDT', '1h', [
            ['timestamp' => 1700000000, 'open' => '2000', 'high' => '2110', 'low' => '1990', 'close' => '2100', 'volume' => '100'],
        ]);

        $this->artisan('alphaforge:signal:evaluate', [
            'direction' => 'short',
            'exchange' => 'binance',
            'symbol' => 'ETHUSDT',
            'entry-price' => '2000',
            'stop-loss' => '2100',
            'take-profit' => '1900',
            '--entry-timestamp' => '1700000000',
            '--timeframe' => '1h',
        ])->assertSuccessful();

        $signal = TradeSignal::first();
        expect($signal->status)->toBe('loser');
        expect($signal->exit_reason)->toBe('stop_loss');
        expect((float) $signal->exit_price)->toBe(2100.0);
    });
});
