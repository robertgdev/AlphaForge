<?php

namespace App\AlphaForge\Analysis\Engine;

use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Services\MarketDataFileService;
use App\AlphaForge\Analysis\Config\OpenCrossAnalysisConfig;
use App\AlphaForge\Analysis\Dto\OpenCrossProbabilityResult;
use App\AlphaForge\Analysis\Exception\AnalysisException;
use Generator;

/**
 * Core engine for computing Open-Cross probability surfaces.
 *
 * Implements an O(n) algorithm using backward scanning for future range precomputation.
 */
final class OpenCrossProbabilityEngine
{
    /**
     * @param  BinaryStorageInterface  $binaryStorage  Service for reading binary OHLCV files
     * @param  MarketDataFileService  $fileService  Service for generating file paths
     * @param  string  $marketDataPath  Base path for market data storage
     */
    public function __construct(
        private readonly BinaryStorageInterface $binaryStorage,
        private readonly MarketDataFileService $fileService,
        private readonly string $marketDataPath
    ) {}

    /**
     * Run the Open-Cross probability analysis.
     *
     * @param  OpenCrossAnalysisConfig  $config  Analysis configuration
     * @param  callable|null  $progressCallback  Optional callback for progress updates
     * @return OpenCrossProbabilityResult The analysis results
     *
     * @throws AnalysisException If analysis fails
     */
    public function analyze(
        OpenCrossAnalysisConfig $config,
        ?callable $progressCallback = null
    ): OpenCrossProbabilityResult {
        // Track peak memory usage
        $peakMemory = 0;
        $initialMemory = memory_get_usage(true);

        // Get the source file path
        $sourcePath = $this->fileService->generateFilePath(
            $config->exchange,
            $config->market,
            $config->timeframe,
            'ohlcv'
        );

        if (! file_exists($sourcePath)) {
            throw AnalysisException::fileNotFound($sourcePath);
        }

        // Read header to get record count
        $header = $this->binaryStorage->readHeader($sourcePath);
        $totalRecords = $header['numRecords'];

        if ($totalRecords === 0) {
            throw AnalysisException::emptyData();
        }

        // Stream records sequentially
        $records = $this->binaryStorage->readRecordsSequentially($sourcePath);

        // Convert generator to array for multiple passes (needed for volatility calculation)
        // For very large files, this could be memory-intensive; consider chunking for production
        $recordsArray = iterator_to_array($records);

        // Apply date filtering if configured
        if ($config->hasDateFilter()) {
            $recordsArray = array_filter($recordsArray, fn ($record) => $config->isWithinDateRange($record['timestamp']));
            $recordsArray = array_values($recordsArray); // Re-index array
        }

        // Check if we have any records after filtering
        if (empty($recordsArray)) {
            throw AnalysisException::emptyData('No records found within the specified date range.');
        }

        // Update peak memory
        $peakMemory = max($peakMemory, memory_get_usage(true));

        // Calculate volatility if normalization is enabled
        $volatilities = [];
        if ($config->volatilityNormalized) {
            $calculator = new VolatilityCalculator;
            $volatilities = $calculator->calculateRollingVolatility(
                $recordsArray,
                $config->volatilityLookback
            );
        }

        // Update peak memory after volatility calculation
        $peakMemory = max($peakMemory, memory_get_usage(true));

        // Partition into blocks and process
        $blocks = $this->partitionIntoBlocks($recordsArray, $config->blockMinutes, $volatilities, $config);

        // Update peak memory after block partitioning
        $peakMemory = max($peakMemory, memory_get_usage(true));

        $accumulator = new StatisticsAccumulator;
        $totalBlocks = count($blocks);
        $processedBlocks = 0;

        foreach ($blocks as $blockData) {
            $this->processBlock($blockData, $config, $accumulator);
            $processedBlocks++;

            // Track memory periodically
            if ($processedBlocks % 100 === 0) {
                $peakMemory = max($peakMemory, memory_get_usage(true));
            }

            if ($progressCallback !== null && $processedBlocks % 100 === 0) {
                $progressCallback($processedBlocks, $totalBlocks);
            }
        }

        // Final progress callback
        if ($progressCallback !== null) {
            $progressCallback($totalBlocks, $totalBlocks);
        }

        // Final memory check
        $peakMemory = max($peakMemory, memory_get_usage(true));

        return OpenCrossProbabilityResult::fromAnalysis(
            $accumulator->getResults(),
            $totalBlocks,
            $accumulator->getTotalObservations(),
            $config,
            $peakMemory
        );
    }

    /**
     * Partition records into non-overlapping time blocks.
     *
     * Blocks are aligned by timestamp:
     * - For 15-minute blocks: 00:00-00:14, 00:15-00:29, etc.
     * - For 1-hour blocks: 00:00-00:59, 01:00-01:59, etc.
     *
     * @param  array  $records  Array of OHLCV records
     * @param  int  $blockMinutes  Block duration in minutes
     * @param  array  $volatilities  Array of volatility values (empty if not normalized)
     * @param  OpenCrossAnalysisConfig  $config  Configuration
     * @return array Array of block data arrays
     */
    private function partitionIntoBlocks(
        array $records,
        int $blockMinutes,
        array $volatilities,
        OpenCrossAnalysisConfig $config
    ): array {
        $blocks = [];
        $currentBlock = [];
        $currentBlockStart = null;
        $blockSeconds = $blockMinutes * 60;

        foreach ($records as $index => $record) {
            $timestamp = $record['timestamp'];

            // Calculate block alignment
            $blockStart = $this->getBlockStart($timestamp, $blockSeconds);

            if ($currentBlockStart === null) {
                $currentBlockStart = $blockStart;
            }

            // Check if we've moved to a new block
            if ($blockStart !== $currentBlockStart) {
                // Save the previous block if it has enough records
                if (count($currentBlock) > 0) {
                    $blocks[] = $this->finalizeBlock($currentBlock, $volatilities, $config);
                }

                // Start a new block
                $currentBlock = [];
                $currentBlockStart = $blockStart;
            }

            // Add volatility to record if normalized
            if ($config->volatilityNormalized && isset($volatilities[$index])) {
                $record['volatility'] = $volatilities[$index];
            }

            $currentBlock[] = $record;
        }

        // Don't forget the last block
        if (count($currentBlock) > 0) {
            $blocks[] = $this->finalizeBlock($currentBlock, $volatilities, $config);
        }

        return $blocks;
    }

    /**
     * Calculate the block start timestamp for a given timestamp.
     *
     * @param  int  $timestamp  Unix timestamp
     * @param  int  $blockSeconds  Block duration in seconds
     * @return int Block start timestamp
     */
    private function getBlockStart(int $timestamp, int $blockSeconds): int
    {
        return (int) (floor($timestamp / $blockSeconds) * $blockSeconds);
    }

    /**
     * Finalize a block by computing future ranges.
     *
     * @param  array  $records  Block records
     * @param  array  $volatilities  Volatility values
     * @param  OpenCrossAnalysisConfig  $config  Configuration
     * @return array Processed block data
     */
    private function finalizeBlock(
        array $records,
        array $volatilities,
        OpenCrossAnalysisConfig $config
    ): array {
        $count = count($records);

        // Compute future ranges using backward scan (O(n))
        $futureMinLow = PHP_FLOAT_MAX;
        $futureMaxHigh = PHP_FLOAT_MIN;

        for ($i = $count - 1; $i >= 0; $i--) {
            $currentLow = (float) $records[$i]['low'];
            $currentHigh = (float) $records[$i]['high'];

            // Update future extremes
            $futureMinLow = min($futureMinLow, $currentLow);
            $futureMaxHigh = max($futureMaxHigh, $currentHigh);

            // Store future extremes for this position
            // These represent the min/max of prices AFTER this position
            $records[$i]['future_min_low'] = $futureMinLow;
            $records[$i]['future_max_high'] = $futureMaxHigh;

            // Reset for next iteration to get true "future" values
            // We need to track from the NEXT position, not current
            if ($i < $count - 1) {
                $records[$i]['future_min_low'] = $futureMinLow;
                $records[$i]['future_max_high'] = $futureMaxHigh;
            } else {
                // Last record has no future
                $records[$i]['future_min_low'] = $currentLow;
                $records[$i]['future_max_high'] = $currentHigh;
            }
        }

        // Correct the future ranges: they should exclude the current candle
        $futureMinLow = PHP_FLOAT_MAX;
        $futureMaxHigh = PHP_FLOAT_MIN;

        for ($i = $count - 1; $i >= 0; $i--) {
            $tempMinLow = $futureMinLow;
            $tempMaxHigh = $futureMaxHigh;

            $currentLow = (float) $records[$i]['low'];
            $currentHigh = (float) $records[$i]['high'];

            // Update for next iteration (higher index)
            $futureMinLow = min($futureMinLow, $currentLow);
            $futureMaxHigh = max($futureMaxHigh, $currentHigh);

            // Store the future extremes BEFORE including current candle
            if ($i < $count - 1) {
                $records[$i]['future_min_low'] = $tempMinLow;
                $records[$i]['future_max_high'] = $tempMaxHigh;
            } else {
                // Last record: no future data, use current values as placeholder
                $records[$i]['future_min_low'] = $currentLow;
                $records[$i]['future_max_high'] = $currentHigh;
            }
        }

        return [
            'records' => $records,
            'block_open' => (float) $records[0]['open'],
            'block_length' => $count,
        ];
    }

    /**
     * Process a single block and accumulate statistics.
     *
     * @param  array  $blockData  Block data with records and metadata
     * @param  OpenCrossAnalysisConfig  $config  Configuration
     * @param  StatisticsAccumulator  $accumulator  Statistics accumulator
     */
    private function processBlock(
        array $blockData,
        OpenCrossAnalysisConfig $config,
        StatisticsAccumulator $accumulator
    ): void {
        $records = $blockData['records'];
        $blockOpen = $blockData['block_open'];
        $blockLength = $blockData['block_length'];

        foreach ($records as $index => $record) {
            // Skip the last minute (no future to analyze)
            if ($index >= $blockLength - 1) {
                continue;
            }

            // Calculate distance from block open
            $price = $config->useClosePrice ? (float) $record['close'] : (float) $record['close'];
            $rawDistance = $price - $blockOpen;

            // Calculate minutes remaining
            $minutesRemaining = $blockLength - 1 - $index;

            // Calculate distance for bucketing
            if ($config->volatilityNormalized && isset($record['volatility'])) {
                // Use log returns for proper sigma normalization
                // This is consistent with how volatility is computed (std dev of log returns)
                $distance = $blockOpen > 0 && $price > 0 ? log($price / $blockOpen) : 0.0;

                // Get 1-minute volatility (std dev of log returns)
                $volatility1m = $record['volatility'];

                // Scale volatility by sqrt(time) to get expected volatility over remaining time
                // σ_remaining = σ_1m × √(minutes_remaining)
                // This follows from the property that variance scales linearly with time
                $volatilityRemaining = $volatility1m * sqrt(max(1, $minutesRemaining));

                // Apply minimum volatility floor (0.1% = 0.001) to prevent extreme z-scores
                // This is a reasonable floor for liquid markets
                $volatilityRemaining = max($volatilityRemaining, 0.001);

                // Convert distance to z-score (standard deviations)
                // z = log(price/open) / σ_remaining
                $distance = $distance / $volatilityRemaining;
            } else {
                // Non-normalized: use simple percentage distance
                $distance = $blockOpen > 0 ? $rawDistance / $blockOpen : 0.0;
            }

            // Get bucket key
            $distanceBucket = $config->getBucketKey($distance);

            // Determine if crossed
            $crossed = $this->determineCross(
                $rawDistance,
                $blockOpen,
                $record['future_min_low'],
                $record['future_max_high'],
                $config
            );

            // Record the observation
            $accumulator->record($distanceBucket, $minutesRemaining, $crossed);
        }
    }

    /**
     * Determine if a cross occurred.
     *
     * A cross occurs if:
     * - Price is above open (distance > 0) and future low goes below open
     * - Price is below open (distance < 0) and future high goes above open
     *
     * @param  float  $distance  Current distance from open
     * @param  float  $blockOpen  Block open price
     * @param  float  $futureMinLow  Minimum low in future
     * @param  float  $futureMaxHigh  Maximum high in future
     * @param  OpenCrossAnalysisConfig  $config  Configuration
     * @return bool True if crossed
     */
    private function determineCross(
        float $distance,
        float $blockOpen,
        float $futureMinLow,
        float $futureMaxHigh,
        OpenCrossAnalysisConfig $config
    ): bool {
        // If we're at the open (distance ≈ 0), no meaningful cross to detect
        if (abs($distance) < 0.0000001) {
            return false;
        }

        if ($distance > 0) {
            // Price is above open - check if future low crosses below open
            return $futureMinLow < $blockOpen;
        } else {
            // Price is below open - check if future high crosses above open
            return $futureMaxHigh > $blockOpen;
        }
    }

    /**
     * Get information about the source data file.
     *
     * @param  string  $exchange  Exchange identifier
     * @param  string  $market  Market symbol
     * @param  string  $timeframe  Timeframe
     * @return array Header information
     *
     * @throws AnalysisException If file not found
     */
    public function getSourceFileInfo(string $exchange, string $market, string $timeframe): array
    {
        $sourcePath = $this->fileService->generateFilePath($exchange, $market, $timeframe, 'ohlcv');

        if (! file_exists($sourcePath)) {
            throw AnalysisException::fileNotFound($sourcePath);
        }

        return $this->binaryStorage->readHeader($sourcePath);
    }
}
