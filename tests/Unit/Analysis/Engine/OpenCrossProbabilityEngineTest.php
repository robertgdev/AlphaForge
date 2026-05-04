<?php

namespace Tests\Unit\Analysis\Engine;

use App\Analysis\Config\OpenCrossAnalysisConfig;
use App\Analysis\Engine\OpenCrossProbabilityEngine;
use App\Analysis\Exception\AnalysisException;
use App\Stochastix\Data\Service\BinaryStorageInterface;
use App\Stochastix\Services\MarketDataFileService;
use Generator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OpenCrossProbabilityEngine.
 */
final class OpenCrossProbabilityEngineTest extends TestCase
{
    private BinaryStorageInterface&MockObject $binaryStorage;

    private MarketDataFileService&MockObject $fileService;

    private string $marketDataPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->binaryStorage = $this->createMock(BinaryStorageInterface::class);
        $this->fileService = $this->createMock(MarketDataFileService::class);
        $this->marketDataPath = '/tmp/marketdata';
    }

    /**
     * Test that analysis fails when source file not found.
     */
    public function test_analyze_fails_when_file_not_found(): void
    {
        $config = OpenCrossAnalysisConfig::fromArray([
            'exchange' => 'binance',
            'market' => 'BTC/USDT',
            'timeframe' => '1m',
            'block_minutes' => 15,
            'bucket_size' => 0.001,
        ]);

        $this->fileService
            ->method('generateFilePath')
            ->willReturn('/tmp/marketdata/binance/BTC_USDT/1m/ohlcv.stchx');

        $engine = new OpenCrossProbabilityEngine(
            $this->binaryStorage,
            $this->fileService,
            $this->marketDataPath
        );

        $this->expectException(AnalysisException::class);
        $this->expectExceptionMessage('Market data file not found');

        $engine->analyze($config);
    }

    /**
     * Test that analysis fails with empty data.
     */
    public function test_analyze_fails_with_empty_data(): void
    {
        $config = OpenCrossAnalysisConfig::fromArray([
            'exchange' => 'binance',
            'market' => 'BTC/USDT',
            'timeframe' => '1m',
            'block_minutes' => 15,
            'bucket_size' => 0.001,
        ]);

        $filePath = '/tmp/marketdata/binance/BTC_USDT/1m/ohlcv.stchx';

        $this->fileService
            ->method('generateFilePath')
            ->willReturn($filePath);

        $this->binaryStorage
            ->method('readHeader')
            ->willReturn([
                'magic' => 'STCHXBF1',
                'version' => 1,
                'headerLength' => 64,
                'recordLength' => 48,
                'numRecords' => 0,
                'symbol' => 'BTC/USDT',
                'timeframe' => '1m',
            ]);

        $this->binaryStorage
            ->method('readRecordsSequentially')
            ->willReturn((function (): Generator {
                yield from [];
            })());

        // Create the file so it exists
        file_put_contents($filePath, '');

        $engine = new OpenCrossProbabilityEngine(
            $this->binaryStorage,
            $this->fileService,
            $this->marketDataPath
        );

        $this->expectException(AnalysisException::class);
        $this->expectExceptionMessage('No data available');

        try {
            $engine->analyze($config);
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * Test single block analysis with immediate cross.
     */
    public function test_single_block_with_immediate_cross(): void
    {
        $config = OpenCrossAnalysisConfig::fromArray([
            'exchange' => 'binance',
            'market' => 'BTC/USDT',
            'timeframe' => '1m',
            'block_minutes' => 5,
            'bucket_size' => 0.01, // 1% buckets
        ]);

        $filePath = '/tmp/marketdata/binance/BTC_USDT/1m/ohlcv.stchx';

        $this->fileService
            ->method('generateFilePath')
            ->willReturn($filePath);

        $this->binaryStorage
            ->method('readHeader')
            ->willReturn([
                'magic' => 'STCHXBF1',
                'version' => 1,
                'headerLength' => 64,
                'recordLength' => 48,
                'numRecords' => 5,
                'symbol' => 'BTC/USDT',
                'timeframe' => '1m',
            ]);

        // Create test data: price starts at 100, goes up to 102, then crosses back down
        $records = [
            ['timestamp' => 1000000000, 'open' => 100.0, 'high' => 100.5, 'low' => 99.5, 'close' => 100.0, 'volume' => 1.0],
            ['timestamp' => 1000000060, 'open' => 100.0, 'high' => 101.0, 'low' => 100.0, 'close' => 101.0, 'volume' => 1.0], // +1%
            ['timestamp' => 1000000120, 'open' => 101.0, 'high' => 102.0, 'low' => 101.0, 'close' => 102.0, 'volume' => 1.0], // +2%
            ['timestamp' => 1000000180, 'open' => 102.0, 'high' => 102.0, 'low' => 99.0, 'close' => 99.5, 'volume' => 1.0],   // Crosses below open!
            ['timestamp' => 1000000240, 'open' => 99.5, 'high' => 100.0, 'low' => 99.0, 'close' => 99.5, 'volume' => 1.0],
        ];

        $this->binaryStorage
            ->method('readRecordsSequentially')
            ->willReturn((function () use ($records): Generator {
                foreach ($records as $record) {
                    yield $record;
                }
            })());

        // Create the file so it exists
        file_put_contents($filePath, '');

        $engine = new OpenCrossProbabilityEngine(
            $this->binaryStorage,
            $this->fileService,
            $this->marketDataPath
        );

        try {
            $result = $engine->analyze($config);

            $this->assertGreaterThan(0, $result->totalBlocksAnalyzed);
            $this->assertGreaterThan(0, $result->totalObservations);
            $this->assertNotEmpty($result->probabilitySurface);
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * Test block partitioning alignment.
     */
    public function test_block_partitioning_alignment(): void
    {
        $config = OpenCrossAnalysisConfig::fromArray([
            'exchange' => 'binance',
            'market' => 'BTC/USDT',
            'timeframe' => '1m',
            'block_minutes' => 15,
            'bucket_size' => 0.01,
        ]);

        $filePath = '/tmp/marketdata/binance/BTC_USDT/1m/ohlcv.stchx';

        $this->fileService
            ->method('generateFilePath')
            ->willReturn($filePath);

        // Create 30 minutes of data (should create 2 blocks)
        $numRecords = 30;
        $this->binaryStorage
            ->method('readHeader')
            ->willReturn([
                'magic' => 'STCHXBF1',
                'version' => 1,
                'headerLength' => 64,
                'recordLength' => 48,
                'numRecords' => $numRecords,
                'symbol' => 'BTC/USDT',
                'timeframe' => '1m',
            ]);

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

        $this->binaryStorage
            ->method('readRecordsSequentially')
            ->willReturn((function () use ($records): Generator {
                foreach ($records as $record) {
                    yield $record;
                }
            })());

        file_put_contents($filePath, '');

        $engine = new OpenCrossProbabilityEngine(
            $this->binaryStorage,
            $this->fileService,
            $this->marketDataPath
        );

        try {
            $result = $engine->analyze($config);

            // Should have 2 blocks (30 minutes / 15 minutes per block)
            $this->assertEquals(2, $result->totalBlocksAnalyzed);
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * Test volatility normalization option.
     */
    public function test_volatility_normalization(): void
    {
        $config = OpenCrossAnalysisConfig::fromArray([
            'exchange' => 'binance',
            'market' => 'BTC/USDT',
            'timeframe' => '1m',
            'block_minutes' => 5,
            'bucket_size' => 0.5, // 0.5 sigma buckets
            'volatility_normalized' => true,
            'volatility_lookback' => 3,
        ]);

        $filePath = '/tmp/marketdata/binance/BTC_USDT/1m/ohlcv.stchx';

        $this->fileService
            ->method('generateFilePath')
            ->willReturn($filePath);

        $numRecords = 10;
        $this->binaryStorage
            ->method('readHeader')
            ->willReturn([
                'magic' => 'STCHXBF1',
                'version' => 1,
                'headerLength' => 64,
                'recordLength' => 48,
                'numRecords' => $numRecords,
                'symbol' => 'BTC/USDT',
                'timeframe' => '1m',
            ]);

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

        $this->binaryStorage
            ->method('readRecordsSequentially')
            ->willReturn((function () use ($records): Generator {
                foreach ($records as $record) {
                    yield $record;
                }
            })());

        file_put_contents($filePath, '');

        $engine = new OpenCrossProbabilityEngine(
            $this->binaryStorage,
            $this->fileService,
            $this->marketDataPath
        );

        try {
            $result = $engine->analyze($config);

            $this->assertTrue($result->metadata['volatility_normalized']);
            $this->assertNotEmpty($result->probabilitySurface);
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * Test symmetric merge option.
     */
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

        $filePath = '/tmp/marketdata/binance/BTC_USDT/1m/ohlcv.stchx';

        $this->fileService
            ->method('generateFilePath')
            ->willReturn($filePath);

        $numRecords = 10;
        $this->binaryStorage
            ->method('readHeader')
            ->willReturn([
                'magic' => 'STCHXBF1',
                'version' => 1,
                'headerLength' => 64,
                'recordLength' => 48,
                'numRecords' => $numRecords,
                'symbol' => 'BTC/USDT',
                'timeframe' => '1m',
            ]);

        $records = [];
        $baseTime = 1000000000;
        for ($i = 0; $i < $numRecords; $i++) {
            $records[] = [
                'timestamp' => $baseTime + ($i * 60),
                'open' => 100.0,
                'high' => 101.0,
                'low' => 99.0,
                'close' => 100.0 + ($i % 2 === 0 ? 1.0 : -1.0), // Alternating +1% and -1%
                'volume' => 1.0,
            ];
        }

        $this->binaryStorage
            ->method('readRecordsSequentially')
            ->willReturn((function () use ($records): Generator {
                foreach ($records as $record) {
                    yield $record;
                }
            })());

        file_put_contents($filePath, '');

        $engine = new OpenCrossProbabilityEngine(
            $this->binaryStorage,
            $this->fileService,
            $this->marketDataPath
        );

        try {
            $result = $engine->analyze($config);

            $this->assertTrue($result->metadata['merge_symmetric']);

            // With symmetric merge, all buckets should be non-negative (absolute value)
            foreach ($result->probabilitySurface as $point) {
                $this->assertGreaterThanOrEqual(0, $point->distanceBucket);
            }
        } finally {
            @unlink($filePath);
        }
    }
}
