<?php

use App\AlphaForge\Data\Service\BinaryStorage;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Data\Service\DataAvailabilityService;
use App\AlphaForge\Services\MarketDataFileService;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/alphaforge_data_invocation_test_'.uniqid();
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

describe('alphaforge:data:delete', function () {
    it('reports failure when file does not exist', function () {
        $this->artisan('alphaforge:data:delete', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => $this->timeframe,
        ])
            ->expectsOutputToContain('No market data file found')
            ->assertFailed();
    });

    it('deletes file when --force is used', function () {
        $filePath = $this->fileService->generateFilePath($this->exchange, $this->market, $this->timeframe);
        $this->binaryStorage->createFile($filePath, $this->market, $this->timeframe);

        expect(file_exists($filePath))->toBeTrue();

        $this->artisan('alphaforge:data:delete', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => $this->timeframe,
            '--force' => true,
        ])
            ->expectsOutputToContain('deleted successfully')
            ->assertSuccessful();

        expect(file_exists($filePath))->toBeFalse();
    });
});

describe('alphaforge:data:info', function () {
    it('reports failure when file does not exist', function () {
        $this->artisan('alphaforge:data:info', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => $this->timeframe,
        ])
            ->expectsOutputToContain('No market data found')
            ->assertFailed();
    });

    it('displays file information for existing data', function () {
        $filePath = $this->fileService->generateFilePath($this->exchange, $this->market, $this->timeframe);
        $this->binaryStorage->createFile($filePath, $this->market, $this->timeframe);

        $records = [
            [
                'timestamp' => time(),
                'open' => 100.0,
                'high' => 110.0,
                'low' => 90.0,
                'close' => 105.0,
                'volume' => 1000.0,
            ],
        ];
        $this->binaryStorage->appendRecords($filePath, $records);
        $this->binaryStorage->updateRecordCount($filePath, count($records));

        $this->artisan('alphaforge:data:info', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => $this->timeframe,
        ])
            ->expectsOutputToContain('Market Data Information')
            ->expectsOutputToContain('File Statistics')
            ->assertSuccessful();
    });
});

describe('alphaforge:data:list', function () {
    it('shows empty state when no data exists', function () {
        $this->artisan('alphaforge:data:list')
            ->expectsOutputToContain('No market data files found')
            ->assertSuccessful();
    });

    it('lists available market data files', function () {
        $filePath = $this->fileService->generateFilePath($this->exchange, $this->market, $this->timeframe);
        $this->binaryStorage->createFile($filePath, $this->market, $this->timeframe);

        $records = [
            [
                'timestamp' => time(),
                'open' => 100.0,
                'high' => 110.0,
                'low' => 90.0,
                'close' => 105.0,
                'volume' => 1000.0,
            ],
        ];
        $this->binaryStorage->appendRecords($filePath, $records);
        $this->binaryStorage->updateRecordCount($filePath, count($records));

        $this->artisan('alphaforge:data:list')
            ->expectsOutputToContain('Available Market Data Files')
            ->expectsOutputToContain($this->exchange)
            ->assertSuccessful();
    });
});

describe('alphaforge:data:update', function () {
    it('reports failure when source file does not exist', function () {
        $this->artisan('alphaforge:data:update', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => $this->timeframe,
        ])
            ->expectsOutputToContain('No market data file found')
            ->assertFailed();
    });

    it('reports already up to date when end date is before last record', function () {
        $filePath = $this->fileService->generateFilePath($this->exchange, $this->market, $this->timeframe);
        $this->binaryStorage->createFile($filePath, $this->market, $this->timeframe);

        $futureTime = time() + 86400 * 2;
        $records = [
            [
                'timestamp' => $futureTime,
                'open' => 100.0,
                'high' => 110.0,
                'low' => 90.0,
                'close' => 105.0,
                'volume' => 1000.0,
            ],
        ];
        $this->binaryStorage->appendRecords($filePath, $records);
        $this->binaryStorage->updateRecordCount($filePath, count($records));

        $this->artisan('alphaforge:data:update', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => $this->timeframe,
        ])
            ->expectsOutputToContain('already up to date')
            ->assertSuccessful();
    });
});

describe('alphaforge:data:export', function () {
    it('reports not yet implemented', function () {
        $this->artisan('alphaforge:data:export', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => $this->timeframe,
        ])
            ->expectsOutputToContain('not yet implemented')
            ->assertFailed();
    });
});

describe('alphaforge:data:import', function () {
    it('reports failure for invalid start date', function () {
        $this->artisan('alphaforge:data:import', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => $this->timeframe,
            'startdate' => 'not-a-date',
        ])
            ->expectsOutputToContain('Invalid start date format')
            ->assertFailed();
    });

    it('reports failure when start date is after end date', function () {
        $this->artisan('alphaforge:data:import', [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => $this->timeframe,
            'startdate' => '2025-01-01',
            'enddate' => '2024-01-01',
        ])
            ->expectsOutputToContain('Start date must be before end date')
            ->assertFailed();
    });
});
