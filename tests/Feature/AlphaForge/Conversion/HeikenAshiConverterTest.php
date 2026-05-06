<?php

use App\AlphaForge\Conversion\HeikenAshiConverter;
use App\AlphaForge\Data\Exception\StorageException;
use App\AlphaForge\Data\Service\BinaryStorage;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Services\MarketDataFileService;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/stochastix_heikenashi_test_'.uniqid();
    mkdir($this->tempDir, 0775, true);

    $this->binaryStorage = new BinaryStorage;
    $this->fileService = new MarketDataFileService($this->tempDir);
    $this->converter = new HeikenAshiConverter(
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
function createTestOhlcvFileForHeikenAshi(BinaryStorageInterface $storage, string $path, array $records): void
{
    $storage->createFile($path, 'TEST/USDT', '1h');
    $storage->appendRecords($path, $records);
    $storage->updateRecordCount($path, count($records));
}

it('can generate correct heiken-ashi file path', function () {
    $path = $this->converter->generateHeikenAshiFilePath('binance', 'BTC/USDT', '1h');

    expect($path)->toEndWith('/binance/BTC_USDT/1h/heikenashi.stchx');
});

it('throws exception when ohlcv file does not exist', function () {
    expect(fn () => $this->converter->convert('binance', 'BTC/USDT', '1h'))
        ->toThrow(StorageException::class, 'OHLC file not found');
});

it('throws exception when ohlcv file has no records', function () {
    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
    $this->binaryStorage->createFile($ohlcvPath, 'BTC/USDT', '1h');
    // Don't add any records, so numRecords stays 0

    expect(fn () => $this->converter->convert('binance', 'BTC/USDT', '1h'))
        ->toThrow(StorageException::class, 'OHLC file contains no records');
});

it('produces same number of candles as source data', function () {
    $records = [];
    $baseTime = 1700000000;

    for ($i = 0; $i < 100; $i++) {
        $records[] = [
            'timestamp' => $baseTime + ($i * 3600),
            'open' => 100 + $i,
            'high' => 105 + $i,
            'low' => 95 + $i,
            'close' => 102 + $i,
            'volume' => 1000.0,
        ];
    }

    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
    createTestOhlcvFileForHeikenAshi($this->binaryStorage, $ohlcvPath, $records);

    $heikenAshiPath = $this->converter->convert('binance', 'BTC/USDT', '1h');

    $header = $this->converter->readHeikenAshiHeader($heikenAshiPath);

    expect($header['numRecords'])->toBe(100);
});

it('calculates correct heiken-ashi values for first candle', function () {
    // First candle: O=100, H=110, L=90, C=105
    // HA Close = (100 + 110 + 90 + 105) / 4 = 101.25
    // HA Open = (100 + 105) / 2 = 102.5 (first candle uses regular O+C/2)
    // HA High = max(110, 102.5, 101.25) = 110
    // HA Low = min(90, 102.5, 101.25) = 90

    $records = [
        [
            'timestamp' => 1700000000,
            'open' => 100.0,
            'high' => 110.0,
            'low' => 90.0,
            'close' => 105.0,
            'volume' => 1000.0,
        ],
    ];

    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
    createTestOhlcvFileForHeikenAshi($this->binaryStorage, $ohlcvPath, $records);

    $heikenAshiPath = $this->converter->convert('binance', 'BTC/USDT', '1h');

    // Read the first record
    $handle = fopen($heikenAshiPath, 'rb');
    fseek($handle, 64); // Skip header
    $record = unpack('Jtimestamp/Eopen/Ehigh/Elow/Eclose/Evolume', fread($handle, 48));
    fclose($handle);

    expect($record['open'])->toBe(102.5)       // (100 + 105) / 2
        ->and($record['close'])->toBe(101.25)  // (100 + 110 + 90 + 105) / 4
        ->and($record['high'])->toBe(110.0)    // max(110, 102.5, 101.25)
        ->and($record['low'])->toBe(90.0);     // min(90, 102.5, 101.25)
});

it('calculates correct heiken-ashi values for subsequent candles', function () {
    // First candle: O=100, H=110, L=90, C=105
    // HA Close = 101.25, HA Open = 102.5
    //
    // Second candle: O=105, H=115, L=100, C=110
    // HA Close = (105 + 115 + 100 + 110) / 4 = 107.5
    // HA Open = (102.5 + 101.25) / 2 = 101.875
    // HA High = max(115, 101.875, 107.5) = 115
    // HA Low = min(100, 101.875, 107.5) = 100

    $records = [
        [
            'timestamp' => 1700000000,
            'open' => 100.0,
            'high' => 110.0,
            'low' => 90.0,
            'close' => 105.0,
            'volume' => 1000.0,
        ],
        [
            'timestamp' => 1700003600,
            'open' => 105.0,
            'high' => 115.0,
            'low' => 100.0,
            'close' => 110.0,
            'volume' => 1500.0,
        ],
    ];

    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
    createTestOhlcvFileForHeikenAshi($this->binaryStorage, $ohlcvPath, $records);

    $heikenAshiPath = $this->converter->convert('binance', 'BTC/USDT', '1h');

    // Read the second record
    $handle = fopen($heikenAshiPath, 'rb');
    fseek($handle, 64 + 48); // Skip header + first record
    $record = unpack('Jtimestamp/Eopen/Ehigh/Elow/Eclose/Evolume', fread($handle, 48));
    fclose($handle);

    expect($record['open'])->toBe(101.875)     // (102.5 + 101.25) / 2
        ->and($record['close'])->toBe(107.5)   // (105 + 115 + 100 + 110) / 4
        ->and($record['high'])->toBe(115.0)    // max(115, 101.875, 107.5)
        ->and($record['low'])->toBe(100.0);    // min(100, 101.875, 107.5)
});

it('preserves volume from source data', function () {
    $records = [
        [
            'timestamp' => 1700000000,
            'open' => 100.0,
            'high' => 110.0,
            'low' => 90.0,
            'close' => 105.0,
            'volume' => 2500.0,
        ],
    ];

    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
    createTestOhlcvFileForHeikenAshi($this->binaryStorage, $ohlcvPath, $records);

    $heikenAshiPath = $this->converter->convert('binance', 'BTC/USDT', '1h');

    $handle = fopen($heikenAshiPath, 'rb');
    fseek($handle, 64);
    $record = unpack('Jtimestamp/Eopen/Ehigh/Elow/Eclose/Evolume', fread($handle, 48));
    fclose($handle);

    expect($record['volume'])->toBe(2500.0);
});

it('preserves timestamps from source data', function () {
    $records = [
        [
            'timestamp' => 1700000000,
            'open' => 100.0,
            'high' => 110.0,
            'low' => 90.0,
            'close' => 105.0,
            'volume' => 1000.0,
        ],
        [
            'timestamp' => 1700003600,
            'open' => 105.0,
            'high' => 115.0,
            'low' => 100.0,
            'close' => 110.0,
            'volume' => 1500.0,
        ],
    ];

    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
    createTestOhlcvFileForHeikenAshi($this->binaryStorage, $ohlcvPath, $records);

    $heikenAshiPath = $this->converter->convert('binance', 'BTC/USDT', '1h');

    $handle = fopen($heikenAshiPath, 'rb');
    fseek($handle, 64);
    $record1 = unpack('Jtimestamp/Eopen/Ehigh/Elow/Eclose/Evolume', fread($handle, 48));
    $record2 = unpack('Jtimestamp/Eopen/Ehigh/Elow/Eclose/Evolume', fread($handle, 48));
    fclose($handle);

    expect($record1['timestamp'])->toBe(1700000000)
        ->and($record2['timestamp'])->toBe(1700003600);
});

it('can detect existing heiken-ashi file', function () {
    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');

    $records = [
        [
            'timestamp' => 1700000000,
            'open' => 100.0,
            'high' => 110.0,
            'low' => 90.0,
            'close' => 105.0,
            'volume' => 1000.0,
        ],
    ];

    createTestOhlcvFileForHeikenAshi($this->binaryStorage, $ohlcvPath, $records);

    expect($this->converter->heikenAshiFileExists('binance', 'BTC/USDT', '1h'))->toBeFalse();

    $this->converter->convert('binance', 'BTC/USDT', '1h');

    expect($this->converter->heikenAshiFileExists('binance', 'BTC/USDT', '1h'))->toBeTrue();
});

it('calls progress callback during conversion', function () {
    $records = [];
    $baseTime = 1700000000;

    for ($i = 0; $i < 500; $i++) {
        $records[] = [
            'timestamp' => $baseTime + ($i * 3600),
            'open' => 100.0 + $i,
            'high' => 105.0 + $i,
            'low' => 95.0 + $i,
            'close' => 102.0 + $i,
            'volume' => 1000.0,
        ];
    }

    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
    createTestOhlcvFileForHeikenAshi($this->binaryStorage, $ohlcvPath, $records);

    $progressCalls = [];

    $this->converter->convert(
        'binance',
        'BTC/USDT',
        '1h',
        function (int $current, int $total) use (&$progressCalls) {
            $progressCalls[] = ['current' => $current, 'total' => $total];
        }
    );

    expect(count($progressCalls))->toBeGreaterThan(0)
        ->and($progressCalls[0]['total'])->toBe(500)
        ->and(end($progressCalls)['current'])->toBe(500);
});

it('can read and write heiken-ashi header correctly', function () {
    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');

    $records = [
        [
            'timestamp' => 1700000000,
            'open' => 100.0,
            'high' => 110.0,
            'low' => 90.0,
            'close' => 105.0,
            'volume' => 1000.0,
        ],
    ];

    createTestOhlcvFileForHeikenAshi($this->binaryStorage, $ohlcvPath, $records);

    $heikenAshiPath = $this->converter->convert('binance', 'BTC/USDT', '1h');

    $header = $this->converter->readHeikenAshiHeader($heikenAshiPath);

    expect($header['magic'])->toBe('STCHXBF1')
        ->and($header['version'])->toBe(2)
        ->and($header['headerLength'])->toBe(64)
        ->and($header['recordLength'])->toBe(48)
        ->and($header['dataType'])->toBe(2)
        ->and($header['symbol'])->toBe('TEST/USDT');
});

it('throws exception for invalid heiken-ashi file', function () {
    $invalidPath = $this->tempDir.'/invalid.stchx';
    file_put_contents($invalidPath, str_repeat('X', 64));

    expect(fn () => $this->converter->readHeikenAshiHeader($invalidPath))
        ->toThrow(StorageException::class, 'Invalid magic number');
});

it('smooths price data compared to original ohlc', function () {
    // Create volatile price data
    $records = [];
    $baseTime = 1700000000;

    for ($i = 0; $i < 20; $i++) {
        $records[] = [
            'timestamp' => $baseTime + ($i * 3600),
            'open' => 100.0 + ($i % 2 === 0 ? 10 : -10),  // Alternating
            'high' => 100.0 + ($i % 2 === 0 ? 20 : 0),
            'low' => 100.0 + ($i % 2 === 0 ? 0 : -20),
            'close' => 100.0 + ($i % 2 === 0 ? 15 : -15),
            'volume' => 1000.0,
        ];
    }

    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
    createTestOhlcvFileForHeikenAshi($this->binaryStorage, $ohlcvPath, $records);

    $heikenAshiPath = $this->converter->convert('binance', 'BTC/USDT', '1h');

    // Read all Heiken-Ashi records and verify they're smoother
    $handle = fopen($heikenAshiPath, 'rb');
    fseek($handle, 64);

    $haCloses = [];
    for ($i = 0; $i < 20; $i++) {
        $record = unpack('Jtimestamp/Eopen/Ehigh/Elow/Eclose/Evolume', fread($handle, 48));
        $haCloses[] = $record['close'];
    }
    fclose($handle);

    // Calculate variance of HA closes vs original closes
    $originalCloses = [];
    foreach ($records as $r) {
        $originalCloses[] = $r['close'];
    }

    $originalVariance = calculateVariance($originalCloses);
    $haVariance = calculateVariance($haCloses);

    // Heiken-Ashi should have lower variance (smoother)
    expect($haVariance)->toBeLessThan($originalVariance);
});

/**
 * Calculate variance of an array of values.
 */
function calculateVariance(array $values): float
{
    $count = count($values);
    if ($count === 0) {
        return 0.0;
    }

    $mean = array_sum($values) / $count;
    $sumSquaredDiffs = 0.0;

    foreach ($values as $value) {
        $sumSquaredDiffs += ($value - $mean) ** 2;
    }

    return $sumSquaredDiffs / $count;
}

it('handles edge case with identical ohlc values', function () {
    $records = [
        [
            'timestamp' => 1700000000,
            'open' => 100.0,
            'high' => 100.0,
            'low' => 100.0,
            'close' => 100.0,
            'volume' => 1000.0,
        ],
        [
            'timestamp' => 1700003600,
            'open' => 100.0,
            'high' => 100.0,
            'low' => 100.0,
            'close' => 100.0,
            'volume' => 1000.0,
        ],
    ];

    $ohlcvPath = $this->fileService->generateFilePath('binance', 'BTC/USDT', '1h', 'ohlcv');
    createTestOhlcvFileForHeikenAshi($this->binaryStorage, $ohlcvPath, $records);

    $heikenAshiPath = $this->converter->convert('binance', 'BTC/USDT', '1h');

    $handle = fopen($heikenAshiPath, 'rb');
    fseek($handle, 64);
    $record1 = unpack('Jtimestamp/Eopen/Ehigh/Elow/Eclose/Evolume', fread($handle, 48));
    $record2 = unpack('Jtimestamp/Eopen/Ehigh/Elow/Eclose/Evolume', fread($handle, 48));
    fclose($handle);

    // All values should be 100.0
    expect($record1['open'])->toBe(100.0)
        ->and($record1['high'])->toBe(100.0)
        ->and($record1['low'])->toBe(100.0)
        ->and($record1['close'])->toBe(100.0)
        ->and($record2['open'])->toBe(100.0)
        ->and($record2['close'])->toBe(100.0);
});
