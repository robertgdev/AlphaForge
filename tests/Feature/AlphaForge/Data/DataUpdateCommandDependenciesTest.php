<?php

use App\AlphaForge\Console\Commands\DataUpdateCommand;
use App\AlphaForge\Conversion\HeikenAshiConverter;
use App\AlphaForge\Conversion\RenkoConverter;
use App\AlphaForge\Data\Service\BinaryStorage;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Data\Service\DataAvailabilityService;
use App\AlphaForge\Services\MarketDataFileService;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/alphaforge_deps_test_'.uniqid();
    mkdir($this->tempDir, 0775, true);

    $this->binaryStorage = new BinaryStorage;
    $this->fileService = new MarketDataFileService($this->tempDir);

    config(['alphaforge.storage.market_data_path' => $this->tempDir]);

    $this->app->instance(MarketDataFileService::class, $this->fileService);
    $this->app->instance(BinaryStorageInterface::class, $this->binaryStorage);
    $this->app->instance(DataAvailabilityService::class, new DataAvailabilityService(
        $this->tempDir,
        $this->binaryStorage
    ));

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

function createUpToDateOhlcvForDeps(BinaryStorage $storage, MarketDataFileService $fileService, string $exchange, string $market, string $timeframe, int $numRecords = 10): void
{
    $ohlcvPath = $fileService->generateFilePath($exchange, $market, $timeframe, 'ohlcv');
    $storage->createFile($ohlcvPath, $market, $timeframe);

    $baseTime = time() + 7200;
    $records = [];
    for ($i = 0; $i < $numRecords; $i++) {
        $records[] = [
            'timestamp' => $baseTime + ($i * 3600),
            'open' => 100.0 + $i * 5,
            'high' => 110.0 + $i * 5,
            'low' => 90.0 + $i * 5,
            'close' => 105.0 + $i * 5,
            'volume' => 1000.0,
        ];
    }

    $storage->appendRecords($ohlcvPath, $records);
    $storage->updateRecordCount($ohlcvPath, count($records));
}

function createHeikenAshiForDeps(BinaryStorage $storage, string $tempDir, string $exchange, string $market, string $timeframe): void
{
    $converter = new HeikenAshiConverter($storage, new MarketDataFileService($tempDir), $tempDir);
    $converter->convert($exchange, $market, $timeframe);
}

function createRenkoForDeps(BinaryStorage $storage, string $tempDir, string $exchange, string $market, string $timeframe, float $brickSize): void
{
    $converter = new RenkoConverter($storage, new MarketDataFileService($tempDir), $tempDir);
    $converter->convert($exchange, $market, $timeframe, $brickSize);
}

describe('DataUpdateCommand --with-dependencies', function () {
    it('shows --with-dependencies in command signature', function () {
        $ref = new ReflectionClass(DataUpdateCommand::class);
        $props = $ref->getDefaultProperties();

        expect($props['signature'])->toContain('--with-dependencies');
    });

    it('reports no dependencies found when no derived files exist', function () {
        createUpToDateOhlcvForDeps($this->binaryStorage, $this->fileService, $this->exchange, $this->market, $this->timeframe);

        $this->artisan('alphaforge:data:update', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => $this->timeframe,
            '--with-dependencies' => true,
        ])
            ->expectsOutputToContain('No dependent data files found')
            ->assertSuccessful();
    });

    it('cascades update to heiken-ashi dependency', function () {
        createUpToDateOhlcvForDeps($this->binaryStorage, $this->fileService, $this->exchange, $this->market, $this->timeframe);
        createHeikenAshiForDeps($this->binaryStorage, $this->tempDir, $this->exchange, $this->market, $this->timeframe);

        $this->artisan('alphaforge:data:update', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => $this->timeframe,
            '--with-dependencies' => true,
        ])
            ->expectsOutputToContain('Updating dependent data files')
            ->expectsOutputToContain('Heiken-Ashi')
            ->assertSuccessful();
    });

    it('cascades update to renko dependency', function () {
        createUpToDateOhlcvForDeps($this->binaryStorage, $this->fileService, $this->exchange, $this->market, $this->timeframe);
        createRenkoForDeps($this->binaryStorage, $this->tempDir, $this->exchange, $this->market, $this->timeframe, 10.0);

        $this->artisan('alphaforge:data:update', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => $this->timeframe,
            '--with-dependencies' => true,
        ])
            ->expectsOutputToContain('Updating dependent data files')
            ->expectsOutputToContain('Renko')
            ->assertSuccessful();
    });

    it('cascades update to multiple dependencies', function () {
        createUpToDateOhlcvForDeps($this->binaryStorage, $this->fileService, $this->exchange, $this->market, $this->timeframe);
        createHeikenAshiForDeps($this->binaryStorage, $this->tempDir, $this->exchange, $this->market, $this->timeframe);
        createRenkoForDeps($this->binaryStorage, $this->tempDir, $this->exchange, $this->market, $this->timeframe, 10.0);

        $this->artisan('alphaforge:data:update', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => $this->timeframe,
            '--with-dependencies' => true,
        ])
            ->expectsOutputToContain('Dependency Update Summary')
            ->assertSuccessful();
    });

    it('does not cascade when --with-dependencies is not set', function () {
        createUpToDateOhlcvForDeps($this->binaryStorage, $this->fileService, $this->exchange, $this->market, $this->timeframe);
        createHeikenAshiForDeps($this->binaryStorage, $this->tempDir, $this->exchange, $this->market, $this->timeframe);

        $this->artisan('alphaforge:data:update', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => $this->timeframe,
        ])
            ->expectsOutputToContain('already up to date')
            ->doesntExpectOutputToContain('Updating dependent data files')
            ->assertSuccessful();
    });

    it('incrementally updates heiken-ashi file when ohlcv has new data', function () {
        $baseTime = time() + 7200;
        $ohlcvPath = $this->fileService->generateFilePath($this->exchange, $this->market, $this->timeframe, 'ohlcv');
        $this->binaryStorage->createFile($ohlcvPath, $this->market, $this->timeframe);
        $initialRecords = [];
        for ($i = 0; $i < 10; $i++) {
            $initialRecords[] = [
                'timestamp' => $baseTime + ($i * 3600),
                'open' => 100.0 + $i * 5,
                'high' => 110.0 + $i * 5,
                'low' => 90.0 + $i * 5,
                'close' => 105.0 + $i * 5,
                'volume' => 1000.0,
            ];
        }
        $this->binaryStorage->appendRecords($ohlcvPath, $initialRecords);
        $this->binaryStorage->updateRecordCount($ohlcvPath, count($initialRecords));

        createHeikenAshiForDeps($this->binaryStorage, $this->tempDir, $this->exchange, $this->market, $this->timeframe);

        $haConverter = new HeikenAshiConverter($this->binaryStorage, $this->fileService, $this->tempDir);
        $haPath = $haConverter->generateHeikenAshiFilePath($this->exchange, $this->market, $this->timeframe);

        $haHeaderBefore = $this->binaryStorage->readHeader($haPath);
        expect($haHeaderBefore['numRecords'])->toBe(10);

        $additionalRecords = [];
        for ($i = 10; $i < 20; $i++) {
            $additionalRecords[] = [
                'timestamp' => $baseTime + ($i * 3600),
                'open' => 100.0 + $i * 5,
                'high' => 110.0 + $i * 5,
                'low' => 90.0 + $i * 5,
                'close' => 105.0 + $i * 5,
                'volume' => 1000.0,
            ];
        }
        $this->binaryStorage->appendRecords($ohlcvPath, $additionalRecords);
        $this->binaryStorage->updateRecordCount($ohlcvPath, 20);

        $this->artisan('alphaforge:data:update', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => $this->timeframe,
            '--with-dependencies' => true,
        ])
            ->assertSuccessful();

        $haHeaderAfter = $this->binaryStorage->readHeader($haPath);
        expect($haHeaderAfter['numRecords'])->toBe(20);
    });

    it('shows dependency summary after cascade', function () {
        createUpToDateOhlcvForDeps($this->binaryStorage, $this->fileService, $this->exchange, $this->market, $this->timeframe);
        createHeikenAshiForDeps($this->binaryStorage, $this->tempDir, $this->exchange, $this->market, $this->timeframe);

        $this->artisan('alphaforge:data:update', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => $this->timeframe,
            '--with-dependencies' => true,
        ])
            ->expectsOutputToContain('Dependency Update Summary')
            ->expectsOutputToContain('Total Dependencies')
            ->assertSuccessful();
    });
});
