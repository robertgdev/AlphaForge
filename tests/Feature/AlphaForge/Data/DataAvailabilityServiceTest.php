<?php

use App\AlphaForge\Data\Service\BinaryStorage;
use App\AlphaForge\Data\Service\DataAvailabilityService;
use App\AlphaForge\Services\MarketDataFileService;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/alphaforge_availability_test_' . uniqid();
    mkdir($this->tempDir, 0775, true);

    $this->binaryStorage = new BinaryStorage;
    $this->fileService = new MarketDataFileService($this->tempDir);
    $this->service = new DataAvailabilityService($this->tempDir, $this->binaryStorage);
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

function createOhlcvAndHeikenAshi(BinaryStorage $binaryStorage, MarketDataFileService $fileService, string $tempDir, string $exchange, string $market, string $timeframe): void
{
    $ohlcvPath = $fileService->generateFilePath($exchange, $market, $timeframe, 'ohlcv');
    $binaryStorage->createFile($ohlcvPath, $market, $timeframe);
    $records = [
        ['timestamp' => 1700000000, 'open' => 100.0, 'high' => 110.0, 'low' => 90.0, 'close' => 105.0, 'volume' => 1000.0],
        ['timestamp' => 1700003600, 'open' => 105.0, 'high' => 115.0, 'low' => 100.0, 'close' => 110.0, 'volume' => 1500.0],
    ];
    $binaryStorage->appendRecords($ohlcvPath, $records);
    $binaryStorage->updateRecordCount($ohlcvPath, count($records));

    $sanitizedSymbol = str_replace('/', '_', strtoupper($market));
    $dir = rtrim($tempDir, '/') . '/' . strtolower($exchange) . '/' . $sanitizedSymbol . '/' . $timeframe;

    $haPath = $dir . '/heikenashi.stchx';
    $binaryStorage->createFile($haPath, $market, $timeframe, BinaryStorage::DATA_TYPE_HEIKEN_ASHI);

    $haRecords = [
        ['timestamp' => 1700000000, 'open' => 102.5, 'high' => 110.0, 'low' => 90.0, 'close' => 101.25, 'volume' => 1000.0],
        ['timestamp' => 1700003600, 'open' => 101.875, 'high' => 115.0, 'low' => 100.0, 'close' => 107.5, 'volume' => 1500.0],
    ];
    $binaryStorage->appendRecords($haPath, $haRecords);
    $binaryStorage->updateRecordCount($haPath, count($haRecords));
}

function createOhlcvAndRenko(BinaryStorage $binaryStorage, MarketDataFileService $fileService, string $tempDir, string $exchange, string $market, string $timeframe, float $brickSize): void
{
    $ohlcvPath = $fileService->generateFilePath($exchange, $market, $timeframe, 'ohlcv');
    $binaryStorage->createFile($ohlcvPath, $market, $timeframe);
    $records = [
        ['timestamp' => 1700000000, 'open' => 100.0, 'high' => 110.0, 'low' => 90.0, 'close' => 105.0, 'volume' => 1000.0],
        ['timestamp' => 1700003600, 'open' => 105.0, 'high' => 150.0, 'low' => 100.0, 'close' => 150.0, 'volume' => 2000.0],
    ];
    $binaryStorage->appendRecords($ohlcvPath, $records);
    $binaryStorage->updateRecordCount($ohlcvPath, count($records));

    $sanitizedSymbol = str_replace('/', '_', strtoupper($market));
    $dir = rtrim($tempDir, '/') . '/' . strtolower($exchange) . '/' . $sanitizedSymbol . '/' . $timeframe;

    $brickSizeStr = floor($brickSize) === $brickSize ? (string) (int) $brickSize : str_replace('.', '_', (string) $brickSize);
    $renkoPath = $dir . '/renko_' . $brickSizeStr . '.stchx';
    $binaryStorage->createFile($renkoPath, $market, $timeframe, BinaryStorage::DATA_TYPE_RENKO, $brickSize);

    $renkoRecords = [
        ['timestamp' => 1700003600, 'open' => 100.0, 'high' => 110.0, 'low' => 100.0, 'close' => 110.0, 'volume' => 2000.0],
    ];
    $binaryStorage->appendRecords($renkoPath, $renkoRecords);
    $binaryStorage->updateRecordCount($renkoPath, count($renkoRecords));
}

describe('DataAvailabilityService', function () {
    describe('findDependencies', function () {
        it('returns empty array when directory does not exist', function () {
            $result = $this->service->findDependencies('nonexistent', 'BTC/USDT', '1h');

            expect($result)->toBeEmpty();
        });

        it('returns empty array when no derived files exist', function () {
            $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
            $this->binaryStorage->createFile($ohlcvPath, 'BTC/USDT', '1h');

            $records = [
                ['timestamp' => 1700000000, 'open' => 100.0, 'high' => 110.0, 'low' => 90.0, 'close' => 105.0, 'volume' => 1000.0],
            ];
            $this->binaryStorage->appendRecords($ohlcvPath, $records);
            $this->binaryStorage->updateRecordCount($ohlcvPath, 1);

            $result = $this->service->findDependencies('binance', 'BTC/USDT', '1h');

            expect($result)->toBeEmpty();
        });

        it('excludes ohlcv file from dependencies', function () {
            $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
            $this->binaryStorage->createFile($ohlcvPath, 'BTC/USDT', '1h');
            $records = [
                ['timestamp' => 1700000000, 'open' => 100.0, 'high' => 110.0, 'low' => 90.0, 'close' => 105.0, 'volume' => 1000.0],
            ];
            $this->binaryStorage->appendRecords($ohlcvPath, $records);
            $this->binaryStorage->updateRecordCount($ohlcvPath, 1);

            $result = $this->service->findDependencies('binance', 'BTC/USDT', '1h');

            $types = array_column($result, 'type');
            expect($types)->not->toContain('ohlcv');
        });

        it('finds heiken-ashi dependency', function () {
            createOhlcvAndHeikenAshi($this->binaryStorage, $this->fileService, $this->tempDir, 'binance', 'BTC/USDT', '1h');

            $result = $this->service->findDependencies('binance', 'BTC/USDT', '1h');

            expect($result)->toHaveCount(1)
                ->and($result[0]['type'])->toBe('heikenashi')
                ->and($result[0]['dataType'])->toBe(BinaryStorage::DATA_TYPE_HEIKEN_ASHI)
                ->and($result[0]['filePath'])->toEndWith('heikenashi.stchx');
        });

        it('finds renko dependency with correct brick size', function () {
            createOhlcvAndRenko($this->binaryStorage, $this->fileService, $this->tempDir, 'binance', 'BTC/USDT', '1h', 100.0);

            $result = $this->service->findDependencies('binance', 'BTC/USDT', '1h');

            expect($result)->toHaveCount(1)
                ->and($result[0]['dataType'])->toBe(BinaryStorage::DATA_TYPE_RENKO)
                ->and($result[0]['brickSize'])->toBe(100.0);
        });

        it('finds multiple renko dependencies with different brick sizes', function () {
            createOhlcvAndRenko($this->binaryStorage, $this->fileService, $this->tempDir, 'binance', 'BTC/USDT', '1h', 50.0);
            createOhlcvAndRenko($this->binaryStorage, $this->fileService, $this->tempDir, 'binance', 'BTC/USDT', '1h', 100.0);

            $result = $this->service->findDependencies('binance', 'BTC/USDT', '1h');

            expect($result)->toHaveCount(2);

            $brickSizes = array_column($result, 'brickSize');
            expect($brickSizes)->toContain(50.0)
                ->and($brickSizes)->toContain(100.0);
        });

        it('finds mixed dependency types', function () {
            createOhlcvAndHeikenAshi($this->binaryStorage, $this->fileService, $this->tempDir, 'binance', 'BTC/USDT', '1h');
            createOhlcvAndRenko($this->binaryStorage, $this->fileService, $this->tempDir, 'binance', 'BTC/USDT', '1h', 100.0);

            $result = $this->service->findDependencies('binance', 'BTC/USDT', '1h');

            expect($result)->toHaveCount(2);

            $dataTypes = array_column($result, 'dataType');
            expect($dataTypes)->toContain(BinaryStorage::DATA_TYPE_HEIKEN_ASHI)
                ->and($dataTypes)->toContain(BinaryStorage::DATA_TYPE_RENKO);
        });

        it('skips files with zero records', function () {
            $dir = $this->tempDir . '/binance/BTC_USDT/1h';
            mkdir($dir, 0775, true);

            $haPath = $dir . '/heikenashi.stchx';
            $this->binaryStorage->createFile($haPath, 'BTC/USDT', '1h', BinaryStorage::DATA_TYPE_HEIKEN_ASHI);

            $result = $this->service->findDependencies('binance', 'BTC/USDT', '1h');

            expect($result)->toBeEmpty();
        });

        it('skips non-stchx files', function () {
            $dir = $this->tempDir . '/binance/BTC_USDT/1h';
            mkdir($dir, 0775, true);

            file_put_contents($dir . '/readme.txt', 'ignore me');
            file_put_contents($dir . '/data.json', '{}');

            $ohlcvPath = $dir . '/ohlcv.stchx';
            $this->binaryStorage->createFile($ohlcvPath, 'BTC/USDT', '1h');
            $records = [
                ['timestamp' => 1700000000, 'open' => 100.0, 'high' => 110.0, 'low' => 90.0, 'close' => 105.0, 'volume' => 1000.0],
            ];
            $this->binaryStorage->appendRecords($ohlcvPath, $records);
            $this->binaryStorage->updateRecordCount($ohlcvPath, 1);

            $result = $this->service->findDependencies('binance', 'BTC/USDT', '1h');

            expect($result)->toBeEmpty();
        });

        it('isolates dependencies by exchange', function () {
            createOhlcvAndHeikenAshi($this->binaryStorage, $this->fileService, $this->tempDir, 'binance', 'BTC/USDT', '1h');
            createOhlcvAndRenko($this->binaryStorage, $this->fileService, $this->tempDir, 'kraken', 'BTC/USDT', '1h', 50.0);

            $result = $this->service->findDependencies('binance', 'BTC/USDT', '1h');

            expect($result)->toHaveCount(1)
                ->and($result[0]['dataType'])->toBe(BinaryStorage::DATA_TYPE_HEIKEN_ASHI);
        });

        it('isolates dependencies by timeframe', function () {
            createOhlcvAndHeikenAshi($this->binaryStorage, $this->fileService, $this->tempDir, 'binance', 'BTC/USDT', '1h');
            createOhlcvAndRenko($this->binaryStorage, $this->fileService, $this->tempDir, 'binance', 'BTC/USDT', '4h', 100.0);

            $result = $this->service->findDependencies('binance', 'BTC/USDT', '1h');

            expect($result)->toHaveCount(1)
                ->and($result[0]['dataType'])->toBe(BinaryStorage::DATA_TYPE_HEIKEN_ASHI);
        });

        it('isolates dependencies by market', function () {
            createOhlcvAndHeikenAshi($this->binaryStorage, $this->fileService, $this->tempDir, 'binance', 'BTC/USDT', '1h');
            createOhlcvAndRenko($this->binaryStorage, $this->fileService, $this->tempDir, 'binance', 'ETH/USDT', '1h', 10.0);

            $result = $this->service->findDependencies('binance', 'BTC/USDT', '1h');

            expect($result)->toHaveCount(1)
                ->and($result[0]['dataType'])->toBe(BinaryStorage::DATA_TYPE_HEIKEN_ASHI);
        });
    });
});
