<?php

use App\Stochastix\Conversion\RenkoConverter;
use App\Stochastix\Data\Exception\StorageException;
use App\Stochastix\Data\Service\BinaryStorage;
use App\Stochastix\Data\Service\BinaryStorageInterface;
use App\Stochastix\Services\MarketDataFileService;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/stochastix_renko_test_'.uniqid();
    mkdir($this->tempDir, 0775, true);

    $this->binaryStorage = new BinaryStorage;
    $this->fileService = new MarketDataFileService($this->tempDir);
    $this->converter = new RenkoConverter(
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
function createTestOhlcvFile(BinaryStorageInterface $storage, string $path, array $records): void
{
    $storage->createFile($path, 'TEST/USDT', '1h');
    $storage->appendRecords($path, $records);
    $storage->updateRecordCount($path, count($records));
}

it('can generate correct renko file path', function () {
    $path = $this->converter->generateRenkoFilePath('binance', 'BTC/USDT', '1h', 100);

    expect($path)->toEndWith('/binance/BTC_USDT/1h/renko_100.stchx');
});

it('can generate correct renko file path with decimal brick size', function () {
    $path = $this->converter->generateRenkoFilePath('binance', 'BTC/USDT', '1h', 0.001);

    expect($path)->toEndWith('/binance/BTC_USDT/1h/renko_0_001.stchx');
});

it('throws exception when ohlcv file does not exist', function () {
    expect(fn () => $this->converter->convert('binance', 'BTC/USDT', '1h', 100))
        ->toThrow(StorageException::class, 'OHLC file not found');
});

it('throws exception when ohlcv file has no records', function () {
    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
    $this->binaryStorage->createFile($ohlcvPath, 'BTC/USDT', '1h');
    // Don't add any records, so numRecords stays 0

    expect(fn () => $this->converter->convert('binance', 'BTC/USDT', '1h', 100))
        ->toThrow(StorageException::class, 'OHLC file contains no records');
});

it('can convert simple uptrend to renko bricks', function () {
    // Create synthetic data: price moves from 100 to 150 in steps
    // With brick size of 10, we should get 5 up bricks
    $records = [];
    $baseTime = 1700000000;

    // First candle - establishes starting price at 100
    $records[] = [
        'timestamp' => $baseTime,
        'open' => 100,
        'high' => 102,
        'low' => 99,
        'close' => 100,
        'volume' => 1000.0,
    ];

    // Second candle - price moves up to 150 (should generate 5 bricks)
    $records[] = [
        'timestamp' => $baseTime + 3600,
        'open' => 100,
        'high' => 150,
        'low' => 99,
        'close' => 150,
        'volume' => 1000.0,
    ];

    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
    createTestOhlcvFile($this->binaryStorage, $ohlcvPath, $records);

    $renkoPath = $this->converter->convert('binance', 'BTC/USDT', '1h', 10.0);

    expect(file_exists($renkoPath))->toBeTrue();

    // Read and verify the Renko file header
    $header = $this->converter->readRenkoHeader($renkoPath);

    expect($header['magic'])->toBe('STCHXRK1')
        ->and($header['version'])->toBe(1)
        ->and($header['brickSize'])->toBe(10.0)
        ->and($header['numRecords'])->toBe(5);
});

it('can convert price reversal to renko bricks', function () {
    // Create synthetic data: price goes up then reverses down
    $records = [];
    $baseTime = 1700000000;

    // First candle - establishes starting price at 100
    $records[] = [
        'timestamp' => $baseTime,
        'open' => 100,
        'high' => 102,
        'low' => 99,
        'close' => 100,
        'volume' => 1000.0,
    ];

    // Second candle - price moves up to 120 (2 bricks up)
    $records[] = [
        'timestamp' => $baseTime + 3600,
        'open' => 100,
        'high' => 120,
        'low' => 99,
        'close' => 120,
        'volume' => 1000.0,
    ];

    // Third candle - price reverses down to 90 (3 bricks down = reversal)
    $records[] = [
        'timestamp' => $baseTime + 7200,
        'open' => 120,
        'high' => 121,
        'low' => 90,
        'close' => 90,
        'volume' => 1000.0,
    ];

    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
    createTestOhlcvFile($this->binaryStorage, $ohlcvPath, $records);

    $renkoPath = $this->converter->convert('binance', 'BTC/USDT', '1h', 10.0);

    $header = $this->converter->readRenkoHeader($renkoPath);

    // 2 up bricks + 3 down bricks = 5 total
    expect($header['numRecords'])->toBe(5);
});

it('produces correct renko brick values', function () {
    // Simple test: price moves from 100 to 110
    $records = [];
    $baseTime = 1700000000;

    $records[] = [
        'timestamp' => $baseTime,
        'open' => 100,
        'high' => 102,
        'low' => 99,
        'close' => 100,
        'volume' => 1000.0,
    ];

    $records[] = [
        'timestamp' => $baseTime + 3600,
        'open' => 100,
        'high' => 110,
        'low' => 99,
        'close' => 110,
        'volume' => 1000.0,
    ];

    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
    createTestOhlcvFile($this->binaryStorage, $ohlcvPath, $records);

    $renkoPath = $this->converter->convert('binance', 'BTC/USDT', '1h', 10.0);

    // Read the Renko file records
    $handle = fopen($renkoPath, 'rb');
    fseek($handle, 64); // Skip header

    $record = unpack('Jtimestamp/Eopen/Ehigh/Elow/Eclose/Evolume', fread($handle, 48));

    expect($record['open'])->toBe(100.0)
        ->and($record['close'])->toBe(110.0)
        ->and($record['high'])->toBe(110.0)
        ->and($record['low'])->toBe(100.0);

    fclose($handle);
});

it('can detect existing renko file', function () {
    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');

    $records = [
        [
            'timestamp' => 1700000000,
            'open' => 100,
            'high' => 102,
            'low' => 99,
            'close' => 100,
            'volume' => 1000.0,
        ],
    ];

    createTestOhlcvFile($this->binaryStorage, $ohlcvPath, $records);

    expect($this->converter->renkoFileExists('binance', 'BTC/USDT', '1h', 10.0))->toBeFalse();

    $this->converter->convert('binance', 'BTC/USDT', '1h', 10.0);

    expect($this->converter->renkoFileExists('binance', 'BTC/USDT', '1h', 10.0))->toBeTrue();
});

it('calls progress callback during conversion', function () {
    $records = [];
    $baseTime = 1700000000;

    // Create 500 records to ensure progress callback is called
    for ($i = 0; $i < 500; $i++) {
        $records[] = [
            'timestamp' => $baseTime + ($i * 3600),
            'open' => 100 + $i,
            'high' => 101 + $i,
            'low' => 99 + $i,
            'close' => 100 + $i,
            'volume' => 1000.0,
        ];
    }

    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
    createTestOhlcvFile($this->binaryStorage, $ohlcvPath, $records);

    $progressCalls = [];

    $this->converter->convert(
        'binance',
        'BTC/USDT',
        '1h',
        1.0,
        function (int $current, int $total) use (&$progressCalls) {
            $progressCalls[] = ['current' => $current, 'total' => $total];
        }
    );

    expect(count($progressCalls))->toBeGreaterThan(0)
        ->and($progressCalls[0]['total'])->toBe(500)
        ->and(end($progressCalls)['current'])->toBe(500);
});

it('can read and write renko header correctly', function () {
    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');

    $records = [
        [
            'timestamp' => 1700000000,
            'open' => 100,
            'high' => 102,
            'low' => 99,
            'close' => 100,
            'volume' => 1000.0,
        ],
    ];

    createTestOhlcvFile($this->binaryStorage, $ohlcvPath, $records);

    $renkoPath = $this->converter->convert('binance', 'BTC/USDT', '1h', 25.5);

    $header = $this->converter->readRenkoHeader($renkoPath);

    expect($header['magic'])->toBe('STCHXRK1')
        ->and($header['version'])->toBe(1)
        ->and($header['headerLength'])->toBe(64)
        ->and($header['recordLength'])->toBe(48)
        ->and($header['brickSize'])->toBe(25.5)
        ->and($header['symbol'])->toBe('TEST/USDT');
});

it('throws exception for invalid renko file', function () {
    $invalidPath = $this->tempDir.'/invalid.stchx';
    file_put_contents($invalidPath, str_repeat('X', 64));

    expect(fn () => $this->converter->readRenkoHeader($invalidPath))
        ->toThrow(StorageException::class, 'Invalid magic number');
});

it('handles multiple brick sizes for same data', function () {
    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');

    $records = [];
    $baseTime = 1700000000;

    for ($i = 0; $i < 100; $i++) {
        $records[] = [
            'timestamp' => $baseTime + ($i * 3600),
            'open' => 100 + $i * 0.5,
            'high' => 101 + $i * 0.5,
            'low' => 99 + $i * 0.5,
            'close' => 100 + $i * 0.5,
            'volume' => 1000.0,
        ];
    }

    createTestOhlcvFile($this->binaryStorage, $ohlcvPath, $records);

    // Convert with different brick sizes
    $path1 = $this->converter->convert('binance', 'BTC/USDT', '1h', 1.0);
    $header1 = $this->converter->readRenkoHeader($path1);

    $path2 = $this->converter->convert('binance', 'BTC/USDT', '1h', 5.0);
    $header2 = $this->converter->readRenkoHeader($path2);

    // Smaller brick size should produce more bricks
    expect($header1['numRecords'])->toBeGreaterThan($header2['numRecords']);
});
