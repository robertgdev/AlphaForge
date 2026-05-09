<?php

namespace Tests\Unit\Analysis\Engine;

use App\Analysis\Config\OpenCrossAnalysisConfig;
use App\Analysis\Engine\OpenCrossProbabilityEngine;
use App\AlphaForge\Data\Service\BinaryStorage;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Services\MarketDataFileService;
use PHPUnit\Framework\TestCase;

final class OpenCrossProbabilityEngineTest extends TestCase
{
    private BinaryStorageInterface $binaryStorage;

    private MarketDataFileService $fileService;

    private string $marketDataPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marketDataPath = sys_get_temp_dir().'/alphaforge_ocpe_test_'.uniqid();
        mkdir($this->marketDataPath, 0775, true);

        $this->binaryStorage = new BinaryStorage;
        $this->fileService = new MarketDataFileService($this->marketDataPath);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->marketDataPath);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($dir);
    }

    private function createTestOhlcvFile(string $exchange, string $symbol, string $timeframe, array $records): string
    {
        $sanitizedSymbol = str_replace('/', '_', strtoupper($symbol));
        $path = sprintf('%s/%s/%s/%s/ohlcv.stchx', $this->marketDataPath, strtolower($exchange), $sanitizedSymbol, $timeframe);

        $this->binaryStorage->createFile($path, $symbol, $timeframe);
        if (! empty($records)) {
            $this->binaryStorage->appendRecords($path, $records);
        }

        return $path;
    }

    public function test_analyze_fails_when_file_not_found(): void
    {
        $config = OpenCrossAnalysisConfig::fromArray([
            'exchange' => 'binance',
            'market' => 'BTC/USDT',
            'timeframe' => '1m',
            'block_minutes' => 15,
            'bucket_size' => 0.001,
        ]);

        $engine = new OpenCrossProbabilityEngine(
            $this->binaryStorage,
            $this->fileService,
            $this->marketDataPath
        );

        $this->expectException(\App\Analysis\Exception\AnalysisException::class);
        $this->expectExceptionMessage('Market data file not found');

        $engine->analyze($config);
    }

    public function test_analyze_fails_with_empty_data(): void
    {
        $config = OpenCrossAnalysisConfig::fromArray([
            'exchange' => 'binance',
            'market' => 'BTC/USDT',
            'timeframe' => '1m',
            'block_minutes' => 15,
            'bucket_size' => 0.001,
        ]);

        $this->createTestOhlcvFile('binance', 'BTC/USDT', '1m', []);

        $engine = new OpenCrossProbabilityEngine(
            $this->binaryStorage,
            $this->fileService,
            $this->marketDataPath
        );

        $this->expectException(\App\Analysis\Exception\AnalysisException::class);
        $this->expectExceptionMessage('No data available');

        $engine->analyze($config);
    }

    public function test_single_block_with_immediate_cross(): void
    {
        $config = OpenCrossAnalysisConfig::fromArray([
            'exchange' => 'binance',
            'market' => 'BTC/USDT',
            'timeframe' => '1m',
            'block_minutes' => 5,
            'bucket_size' => 0.01,
        ]);

        $records = [
            ['timestamp' => 1000000000, 'open' => 100.0, 'high' => 100.5, 'low' => 99.5, 'close' => 100.0, 'volume' => 1.0],
            ['timestamp' => 1000000060, 'open' => 100.0, 'high' => 101.0, 'low' => 100.0, 'close' => 101.0, 'volume' => 1.0],
            ['timestamp' => 1000000120, 'open' => 101.0, 'high' => 102.0, 'low' => 101.0, 'close' => 102.0, 'volume' => 1.0],
            ['timestamp' => 1000000180, 'open' => 102.0, 'high' => 102.0, 'low' => 99.0, 'close' => 99.5, 'volume' => 1.0],
            ['timestamp' => 1000000240, 'open' => 99.5, 'high' => 100.0, 'low' => 99.0, 'close' => 99.5, 'volume' => 1.0],
        ];

        $this->createTestOhlcvFile('binance', 'BTC/USDT', '1m', $records);

        $engine = new OpenCrossProbabilityEngine(
            $this->binaryStorage,
            $this->fileService,
            $this->marketDataPath
        );

        $result = $engine->analyze($config);

        $this->assertGreaterThan(0, $result->totalBlocksAnalyzed);
        $this->assertGreaterThan(0, $result->totalObservations);
        $this->assertNotEmpty($result->probabilitySurface);
    }

    public function test_block_partitioning_alignment(): void
    {
        $config = OpenCrossAnalysisConfig::fromArray([
            'exchange' => 'binance',
            'market' => 'BTC/USDT',
            'timeframe' => '1m',
            'block_minutes' => 15,
            'bucket_size' => 0.01,
        ]);

        $numRecords = 30;
        $records = [];
        $baseTime = 1000000000;
        for ($i = 0; $i < $numRecords; $i++) {
            $records[] = [
                'timestamp' => $baseTime + ($i * 60),
                'open' => 100.0 + $i * 0.1,
                'high' => 100.5 + $i * 0.1,
                'low' => 99.5 + $i * 0.1,
                'close' => 100.0 + $i * 0.1,
                'volume' => 1.0,
            ];
        }

        $this->createTestOhlcvFile('binance', 'BTC/USDT', '1m', $records);

        $engine = new OpenCrossProbabilityEngine(
            $this->binaryStorage,
            $this->fileService,
            $this->marketDataPath
        );

        $result = $engine->analyze($config);

        $this->assertGreaterThanOrEqual(2, $result->totalBlocksAnalyzed);
    }

    public function test_volatility_normalization(): void
    {
        $config = OpenCrossAnalysisConfig::fromArray([
            'exchange' => 'binance',
            'market' => 'BTC/USDT',
            'timeframe' => '1m',
            'block_minutes' => 5,
            'bucket_size' => 0.5,
            'volatility_normalized' => true,
            'volatility_lookback' => 3,
        ]);

        $numRecords = 10;
        $records = [];
        $baseTime = 1000000000;
        for ($i = 0; $i < $numRecords; $i++) {
            $records[] = [
                'timestamp' => $baseTime + ($i * 60),
                'open' => 100.0,
                'high' => 101.0,
                'low' => 99.0,
                'close' => 100.0 + ($i % 2 === 0 ? 0.5 : -0.5),
                'volume' => 1.0,
            ];
        }

        $this->createTestOhlcvFile('binance', 'BTC/USDT', '1m', $records);

        $engine = new OpenCrossProbabilityEngine(
            $this->binaryStorage,
            $this->fileService,
            $this->marketDataPath
        );

        $result = $engine->analyze($config);

        $this->assertTrue($result->metadata['volatility_normalized']);
        $this->assertNotEmpty($result->probabilitySurface);
    }

    public function test_symmetric_merge(): void
    {
        $config = OpenCrossAnalysisConfig::fromArray([
            'exchange' => 'binance',
            'market' => 'BTC/USDT',
            'timeframe' => '1m',
            'block_minutes' => 5,
            'bucket_size' => 0.01,
            'merge_symmetric' => true,
        ]);

        $numRecords = 10;
        $records = [];
        $baseTime = 1000000000;
        for ($i = 0; $i < $numRecords; $i++) {
            $records[] = [
                'timestamp' => $baseTime + ($i * 60),
                'open' => 100.0,
                'high' => 101.0,
                'low' => 99.0,
                'close' => 100.0 + ($i % 2 === 0 ? 1.0 : -1.0),
                'volume' => 1.0,
            ];
        }

        $this->createTestOhlcvFile('binance', 'BTC/USDT', '1m', $records);

        $engine = new OpenCrossProbabilityEngine(
            $this->binaryStorage,
            $this->fileService,
            $this->marketDataPath
        );

        $result = $engine->analyze($config);

        $this->assertTrue($result->metadata['merge_symmetric']);

        foreach ($result->probabilitySurface as $point) {
            $this->assertGreaterThanOrEqual(0, $point->distanceBucket);
        }
    }
}
