#!/usr/bin/env php
<?php

/**
 * Performance Benchmark Script for Open-Cross Probability Engine
 *
 * This script benchmarks the performance of the OpenCrossProbabilityEngine
 * with varying data sizes to ensure O(n) complexity.
 *
 * Usage: php scripts/open_cross_benchmark.php [records]
 *
 * @example php scripts/open_cross_benchmark.php 500000
 */

// Bootstrap Laravel application
require_once __DIR__.'/../vendor/autoload.php';

use App\AlphaForge\Analysis\Engine\OpenCrossProbabilityEngine;
use App\AlphaForge\Analysis\Engine\StatisticsAccumulator;
use App\AlphaForge\Analysis\Engine\VolatilityCalculator;
use Illuminate\Contracts\Console\Kernel;

// Initialize Laravel app for config access
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/**
 * Generate synthetic OHLCV data.
 */
function generateSyntheticData(int $count, float $basePrice = 100.0): array
{
    $records = [];
    $timestamp = strtotime('2025-01-01 00:00:00');
    $price = $basePrice;

    for ($i = 0; $i < $count; $i++) {
        // Random walk with some volatility
        $change = (rand(-100, 100) / 1000) * $basePrice * 0.01;
        $price += $change;

        $high = $price + abs(rand(0, 100) / 1000 * $basePrice * 0.005);
        $low = $price - abs(rand(0, 100) / 1000 * $basePrice * 0.005);
        $open = $low + rand(0, 100) / 100 * ($high - $low);
        $close = $low + rand(0, 100) / 100 * ($high - $low);

        $records[] = [
            'timestamp' => $timestamp + ($i * 60),
            'open' => round($open, 2),
            'high' => round($high, 2),
            'low' => round($low, 2),
            'close' => round($close, 2),
            'volume' => rand(100, 10000) / 100,
        ];
    }

    return $records;
}

/**
 * Format bytes to human readable.
 */
function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return round($bytes, 2).' '.$units[$i];
}

/**
 * Run benchmark for a specific data size.
 */
function runBenchmark(int $recordCount, bool $volatilityNormalized = false): array
{
    echo "\n".str_repeat('=', 60)."\n";
    echo "Benchmark: {$recordCount} records\n";
    echo 'Volatility Normalized: '.($volatilityNormalized ? 'Yes' : 'No')."\n";
    echo str_repeat('=', 60)."\n";

    // Generate data
    $startTime = microtime(true);
    $records = generateSyntheticData($recordCount);
    $generationTime = microtime(true) - $startTime;

    echo 'Data generation time: '.round($generationTime, 3)."s\n";
    echo 'Memory usage (data): '.formatBytes(memory_get_usage(true))."\n";

    // Create accumulator
    $accumulator = new StatisticsAccumulator;

    // Create volatility calculator if needed
    $volatilities = [];
    if ($volatilityNormalized) {
        $calculator = new VolatilityCalculator;
        $startTime = microtime(true);
        $volatilities = $calculator->calculateRollingVolatility($records, 20);
        $volatilityTime = microtime(true) - $startTime;
        echo 'Volatility calculation time: '.round($volatilityTime, 3)."s\n";
    }

    // Simulate block processing
    $blockMinutes = 15;
    $blockSeconds = $blockMinutes * 60;
    $bucketSize = 0.001;

    $startTime = microtime(true);
    $peakMemory = memory_get_usage(true);

    $currentBlock = [];
    $currentBlockStart = null;
    $blockCount = 0;

    foreach ($records as $index => $record) {
        $timestamp = $record['timestamp'];
        $blockStart = (int) (floor($timestamp / $blockSeconds) * $blockSeconds);

        if ($currentBlockStart === null) {
            $currentBlockStart = $blockStart;
        }

        if ($blockStart !== $currentBlockStart) {
            if (count($currentBlock) > 0) {
                // Process block
                processBlock($currentBlock, $accumulator, $bucketSize, $volatilities, $volatilityNormalized);
                $blockCount++;
            }
            $currentBlock = [];
            $currentBlockStart = $blockStart;
        }

        if ($volatilityNormalized && isset($volatilities[$index])) {
            $record['volatility'] = $volatilities[$index];
        }
        $currentBlock[] = $record;

        // Track peak memory
        $currentMemory = memory_get_usage(true);
        if ($currentMemory > $peakMemory) {
            $peakMemory = $currentMemory;
        }
    }

    // Process last block
    if (count($currentBlock) > 0) {
        processBlock($currentBlock, $accumulator, $bucketSize, $volatilities, $volatilityNormalized);
        $blockCount++;
    }

    $processingTime = microtime(true) - $startTime;

    echo "\nResults:\n";
    echo '  Blocks processed: '.number_format($blockCount)."\n";
    echo '  Total observations: '.number_format($accumulator->getTotalObservations())."\n";
    echo '  Unique buckets: '.number_format($accumulator->getUniqueBucketCount())."\n";
    echo '  Processing time: '.round($processingTime, 3)."s\n";
    echo '  Records/second: '.number_format($recordCount / $processingTime)."\n";
    echo '  Peak memory: '.formatBytes($peakMemory)."\n";

    // Check performance requirement
    $targetTime = $recordCount / 166667; // ~500k in 3 seconds = 166,667 records/sec
    $performanceRatio = $processingTime / $targetTime;

    if ($processingTime <= 3 || $performanceRatio <= 1.5) {
        echo "  Status: \033[32mPASS\033[0m (within performance target)\n";
    } else {
        echo "  Status: \033[31mWARN\033[0m (above performance target)\n";
    }

    return [
        'records' => $recordCount,
        'blocks' => $blockCount,
        'observations' => $accumulator->getTotalObservations(),
        'processing_time' => $processingTime,
        'records_per_second' => $recordCount / $processingTime,
        'peak_memory' => $peakMemory,
        'volatility_normalized' => $volatilityNormalized,
    ];
}

/**
 * Process a single block.
 */
function processBlock(
    array $records,
    StatisticsAccumulator $accumulator,
    float $bucketSize,
    array $volatilities,
    bool $volatilityNormalized
): void {
    $count = count($records);
    if ($count < 2) {
        return;
    }

    $blockOpen = (float) $records[0]['open'];

    // Compute future ranges using backward scan
    $futureMinLow = PHP_FLOAT_MAX;
    $futureMaxHigh = PHP_FLOAT_MIN;

    for ($i = $count - 1; $i >= 0; $i--) {
        $tempMinLow = $futureMinLow;
        $tempMaxHigh = $futureMaxHigh;

        $currentLow = (float) $records[$i]['low'];
        $currentHigh = (float) $records[$i]['high'];

        $futureMinLow = min($futureMinLow, $currentLow);
        $futureMaxHigh = max($futureMaxHigh, $currentHigh);

        if ($i < $count - 1) {
            $records[$i]['future_min_low'] = $tempMinLow;
            $records[$i]['future_max_high'] = $tempMaxHigh;
        } else {
            $records[$i]['future_min_low'] = $currentLow;
            $records[$i]['future_max_high'] = $currentHigh;
        }
    }

    // Process each minute
    foreach ($records as $index => $record) {
        if ($index >= $count - 1) {
            continue;
        }

        $price = (float) $record['close'];
        $distance = $price - $blockOpen;

        if ($volatilityNormalized && isset($record['volatility'])) {
            $volatility = max($record['volatility'], 0.0001);
            $distance = $distance / $volatility;
        } else {
            $distance = $blockOpen > 0 ? $distance / $blockOpen : 0.0;
        }

        $distanceBucket = floor($distance / $bucketSize) * $bucketSize;
        $minutesRemaining = $count - 1 - $index;

        // Determine cross
        $crossed = false;
        if (abs($distance) > 0.0000001) {
            if ($distance > 0) {
                $crossed = $record['future_min_low'] < $blockOpen;
            } else {
                $crossed = $record['future_max_high'] > $blockOpen;
            }
        }

        $accumulator->record($distanceBucket, $minutesRemaining, $crossed);
    }
}

// Main execution
$defaultRecords = 100000;
$recordCount = isset($argv[1]) ? (int) $argv[1] : $defaultRecords;

echo "\n";
echo "Open-Cross Probability Engine - Performance Benchmark\n";
echo "=====================================================\n";
echo 'PHP version: '.PHP_VERSION."\n";
echo 'Memory limit: '.ini_get('memory_limit')."\n";

// Run benchmarks at different scales
$scales = [10000, 50000, 100000, 250000, 500000];
$results = [];

foreach ($scales as $scale) {
    if ($scale <= $recordCount) {
        $results[] = runBenchmark($scale, false);
    }
}

// Run with volatility normalization if requested
if ($recordCount >= 100000) {
    echo "\n\nRunning with volatility normalization...\n";
    $results[] = runBenchmark(min(100000, $recordCount), true);
}

// Summary
echo "\n\n";
echo str_repeat('=', 60)."\n";
echo "BENCHMARK SUMMARY\n";
echo str_repeat('=', 60)."\n";
echo sprintf("%-12s %-12s %-12s %-12s\n", 'Records', 'Time (s)', 'Recs/sec', 'Memory');
echo str_repeat('-', 48)."\n";

foreach ($results as $result) {
    echo sprintf(
        "%-12s %-12s %-12s %-12s\n",
        number_format($result['records']),
        round($result['processing_time'], 3),
        number_format($result['records_per_second']),
        formatBytes($result['peak_memory'])
    );
}

// Complexity analysis
if (count($results) >= 3) {
    echo "\nComplexity Analysis:\n";

    $firstResult = $results[0];
    $lastResult = $results[count($results) - 1];

    $sizeRatio = $lastResult['records'] / $firstResult['records'];
    $timeRatio = $lastResult['processing_time'] / $firstResult['processing_time'];

    echo "  Size increase: {$sizeRatio}x\n";
    echo '  Time increase: '.round($timeRatio, 2)."x\n";

    if ($timeRatio <= $sizeRatio * 1.2) {
        echo "  Complexity: \033[32mO(n)\033[0m (linear)\n";
    } elseif ($timeRatio <= $sizeRatio * 1.2 * $sizeRatio) {
        echo "  Complexity: \033[33mO(n log n)\033[0m (log-linear)\n";
    } else {
        echo "  Complexity: \033[31mO(n²)\033[0m (quadratic) - WARNING!\n";
    }
}

echo "\nBenchmark complete.\n";
