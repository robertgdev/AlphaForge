<?php

use App\AlphaForge\Conversion\AtrRenkoConverter;
use App\AlphaForge\Data\Exception\StorageException;
use App\AlphaForge\Data\Service\BinaryStorage;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Services\MarketDataFileService;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/alphaforge_atr_renko_test_'.uniqid();
    mkdir($this->tempDir, 0775, true);

    $this->binaryStorage = new BinaryStorage;
    $this->fileService = new MarketDataFileService($this->tempDir);
    $this->converter = new AtrRenkoConverter(
        $this->binaryStorage,
        $this->fileService,
        $this->tempDir
    );
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

/**
 * Create a synthetic OHLC file for testing.
 */
function createTestOhlcvFileForAtrRenko(BinaryStorageInterface $storage, string $path, array $records): void
{
    $storage->createFile($path, 'TEST/USDT', '1h');
    $storage->appendRecords($path, $records);
    $storage->updateRecordCount($path, count($records));
}

it('can generate correct atr-renko file path', function () {
    $path = $this->converter->generateAtrRenkoFilePath('binance', 'BTC/USDT', '1h', 14);

    expect($path)->toEndWith('/binance/BTC_USDT/1h/renko_atr_14.stchx');
});

it('throws exception when ohlcv file does not exist', function () {
    expect(fn () => $this->converter->convert('binance', 'BTC/USDT', '1h', 14))
        ->toThrow(StorageException::class, 'OHLC file not found');
});

it('throws exception when ohlcv file has no records', function () {
    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
    $this->binaryStorage->createFile($ohlcvPath, 'BTC/USDT', '1h');

    expect(fn () => $this->converter->convert('binance', 'BTC/USDT', '1h', 14))
        ->toThrow(StorageException::class, 'OHLC file contains no records');
});

it('throws exception when ohlcv records are fewer than atr period', function () {
    $records = [];
    $baseTime = 1700000000;

    for ($i = 0; $i < 5; $i++) {
        $records[] = [
            'timestamp' => $baseTime + ($i * 3600),
            'open' => 100.0,
            'high' => 101.0,
            'low' => 99.0,
            'close' => 100.0,
            'volume' => 1000.0,
        ];
    }

    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
    createTestOhlcvFileForAtrRenko($this->binaryStorage, $ohlcvPath, $records);

    expect(fn () => $this->converter->convert('binance', 'BTC/USDT', '1h', 14))
        ->toThrow(StorageException::class, 'fewer than the ATR period');
});

it('can convert simple uptrend to atr-renko bricks', function () {
    $records = [];
    $baseTime = 1700000000;

    // Generate 30 candles with a steady uptrend
    for ($i = 0; $i < 30; $i++) {
        $records[] = [
            'timestamp' => $baseTime + ($i * 3600),
            'open' => 100 + $i,
            'high' => 102 + $i,
            'low' => 99 + $i,
            'close' => 101 + $i,
            'volume' => 1000.0,
        ];
    }

    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
    createTestOhlcvFileForAtrRenko($this->binaryStorage, $ohlcvPath, $records);

    $atrRenkoPath = $this->converter->convert('binance', 'BTC/USDT', '1h', 14);

    expect(file_exists($atrRenkoPath))->toBeTrue();

    $header = $this->converter->readAtrRenkoHeader($atrRenkoPath);

    expect($header['magic'])->toBe('STCHXBF1')
        ->and($header['version'])->toBe(2)
        ->and($header['dataType'])->toBe(4)
        ->and($header['brickSize'])->toBe(14.0)
        ->and($header['numRecords'])->toBeGreaterThan(0);
});

it('produces correct atr-renko brick values for a known move', function () {
    $records = [];
    $baseTime = 1700000000;

    // 20 candles with low volatility followed by a sharp move
    for ($i = 0; $i < 20; $i++) {
        $records[] = [
            'timestamp' => $baseTime + ($i * 3600),
            'open' => 100.0,
            'high' => 101.0,
            'low' => 99.0,
            'close' => 100.0,
            'volume' => 1000.0,
        ];
    }

    // Sharp move up
    for ($i = 20; $i < 25; $i++) {
        $records[] = [
            'timestamp' => $baseTime + ($i * 3600),
            'open' => 100 + ($i - 19) * 5,
            'high' => 105 + ($i - 19) * 5,
            'low' => 100 + ($i - 19) * 5,
            'close' => 103 + ($i - 19) * 5,
            'volume' => 1000.0,
        ];
    }

    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
    createTestOhlcvFileForAtrRenko($this->binaryStorage, $ohlcvPath, $records);

    $atrRenkoPath = $this->converter->convert('binance', 'BTC/USDT', '1h', 14);

    $header = $this->converter->readAtrRenkoHeader($atrRenkoPath);

    expect($header['numRecords'])->toBeGreaterThan(0);
});

it('can detect existing atr-renko file', function () {
    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');

    $records = [];
    $baseTime = 1700000000;

    for ($i = 0; $i < 20; $i++) {
        $records[] = [
            'timestamp' => $baseTime + ($i * 3600),
            'open' => 100.0,
            'high' => 101.0,
            'low' => 99.0,
            'close' => 100.0,
            'volume' => 1000.0,
        ];
    }

    createTestOhlcvFileForAtrRenko($this->binaryStorage, $ohlcvPath, $records);

    expect($this->converter->atrRenkoFileExists('binance', 'BTC/USDT', '1h', 14))->toBeFalse();

    $this->converter->convert('binance', 'BTC/USDT', '1h', 14);

    expect($this->converter->atrRenkoFileExists('binance', 'BTC/USDT', '1h', 14))->toBeTrue();
});

it('calls progress callback during conversion', function () {
    $records = [];
    $baseTime = 1700000000;

    for ($i = 0; $i < 500; $i++) {
        $records[] = [
            'timestamp' => $baseTime + ($i * 3600),
            'open' => 100 + $i * 0.1,
            'high' => 101 + $i * 0.1,
            'low' => 99 + $i * 0.1,
            'close' => 100 + $i * 0.1,
            'volume' => 1000.0,
        ];
    }

    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
    createTestOhlcvFileForAtrRenko($this->binaryStorage, $ohlcvPath, $records);

    $progressCalls = [];

    $this->converter->convert(
        'binance',
        'BTC/USDT',
        '1h',
        14,
        function (int $current, int $total) use (&$progressCalls) {
            $progressCalls[] = ['current' => $current, 'total' => $total];
        }
    );

    expect(count($progressCalls))->toBeGreaterThan(0)
        ->and($progressCalls[0]['total'])->toBe(500)
        ->and(end($progressCalls)['current'])->toBe(500);
});

it('can read and write atr-renko header correctly', function () {
    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');

    $records = [];
    $baseTime = 1700000000;

    for ($i = 0; $i < 20; $i++) {
        $records[] = [
            'timestamp' => $baseTime + ($i * 3600),
            'open' => 100.0,
            'high' => 101.0,
            'low' => 99.0,
            'close' => 100.0,
            'volume' => 1000.0,
        ];
    }

    createTestOhlcvFileForAtrRenko($this->binaryStorage, $ohlcvPath, $records);

    $atrRenkoPath = $this->converter->convert('binance', 'BTC/USDT', '1h', 14);

    $header = $this->converter->readAtrRenkoHeader($atrRenkoPath);

    expect($header['magic'])->toBe('STCHXBF1')
        ->and($header['version'])->toBe(2)
        ->and($header['headerLength'])->toBe(64)
        ->and($header['recordLength'])->toBe(48)
        ->and($header['dataType'])->toBe(4)
        ->and($header['brickSize'])->toBe(14.0)
        ->and($header['symbol'])->toBe('TEST/USDT');
});

it('throws exception for invalid atr-renko file', function () {
    $invalidPath = $this->tempDir.'/invalid.stchx';
    file_put_contents($invalidPath, str_repeat('X', 64));

    expect(fn () => $this->converter->readAtrRenkoHeader($invalidPath))
        ->toThrow(StorageException::class, 'Invalid magic number');
});
