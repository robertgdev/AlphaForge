<?php

use App\AlphaForge\Console\Commands\DataUpdateCommand;
use App\AlphaForge\Data\Service\BinaryStorage;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Data\Service\DataAvailabilityService;
use App\AlphaForge\Services\AggregateDataService;
use App\AlphaForge\Services\DataAutoGenerator;
use App\AlphaForge\Services\MarketDataFileService;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/alphaforge_autogen_test_' . uniqid();
    mkdir($this->tempDir, 0775, true);

    $binaryStorage = new BinaryStorage;
    $fileService = new MarketDataFileService($this->tempDir);

    $this->binaryStorage = $binaryStorage;
    $this->fileService = $fileService;

    config(['alphaforge.storage.market_data_path' => $this->tempDir]);

    $this->app->instance(MarketDataFileService::class, $fileService);
    $this->app->instance(BinaryStorageInterface::class, $binaryStorage);
    $this->app->instance(DataAvailabilityService::class, new DataAvailabilityService($this->tempDir, $binaryStorage));

    $this->exchange = 'binance';
    $this->market = 'BTC/USDT';
    $this->timeframe = '1h';
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

function createMinOhlcvForAutoGen(BinaryStorage $storage, MarketDataFileService $fileService, string $exchange, string $market, string $timeframe = '1m', int $numRecords = 60): void
{
    $path = $fileService->generateFilePath($exchange, $market, $timeframe, 'ohlcv');
    $storage->createFile($path, $market, $timeframe);

    $baseTime = time() + 7200;
    $records = [];
    for ($i = 0; $i < $numRecords; $i++) {
        $records[] = [
            'timestamp' => $baseTime + ($i * 60),
            'open' => 100.0 + $i * 0.1,
            'high' => 101.0 + $i * 0.1,
            'low' => 99.0 + $i * 0.1,
            'close' => 100.5 + $i * 0.1,
            'volume' => 1000.0,
        ];
    }

    $storage->appendRecords($path, $records);
    $storage->updateRecordCount($path, count($records));
}

describe('DataUpdateCommand --auto-generate', function () {
    it('includes --auto-generate in command signature', function () {
        $ref = new ReflectionClass(DataUpdateCommand::class);
        $props = $ref->getDefaultProperties();

        expect($props['signature'])->toContain('--auto-generate');
    });

    it('errors without auto-generate when file is missing', function () {
        $this->artisan('alphaforge:data:update', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => $this->timeframe,
        ])
            ->expectsOutputToContain('No market data file found')
            ->assertFailed();
    });

    it('suggests --auto-generate when file is missing and flag is not set', function () {
        $this->artisan('alphaforge:data:update', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => $this->timeframe,
        ])
            ->expectsOutputToContain('Or use --auto-generate')
            ->assertFailed();
    });

    it('auto-generates OHLCV from lower timeframe when --auto-generate is set', function () {
        createMinOhlcvForAutoGen($this->binaryStorage, $this->fileService, $this->exchange, $this->market, '1m', 60);

        $targetPath = $this->fileService->generateFilePath($this->exchange, $this->market, '1h', 'ohlcv');
        expect(file_exists($targetPath))->toBeFalse('1h OHLCV should not exist before test');

        $this->artisan('alphaforge:data:update', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => '1h',
            '--auto-generate' => true,
        ])
            ->expectsOutputToContain('Auto-generate enabled')
            ->assertSuccessful();

        expect(file_exists($targetPath))->toBeTrue('1h OHLCV should be auto-generated');
    });

    it('errors with --auto-generate when no source data exists', function () {
        $targetPath = $this->fileService->generateFilePath($this->exchange, $this->market, '1h', 'ohlcv');
        expect(file_exists($targetPath))->toBeFalse();

        $this->artisan('alphaforge:data:update', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => '1h',
            '--auto-generate' => true,
        ])
            ->expectsOutputToContain('Download source data')
            ->assertFailed();
    });

    it('skips auto-generation when OHLCV file already exists', function () {
        createMinOhlcvForAutoGen($this->binaryStorage, $this->fileService, $this->exchange, $this->market, '1h', 10);

        // File already exists, so it should just say "already up to date"
        // without attempting auto-generation
        $this->artisan('alphaforge:data:update', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => '1h',
            '--auto-generate' => true,
        ])
            ->doesntExpectOutputToContain('Auto-generate enabled')
            ->assertSuccessful();
    });
});
