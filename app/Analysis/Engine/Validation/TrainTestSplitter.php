<?php

namespace App\Analysis\Engine\Validation;

use App\Analysis\Config\ValidationConfig;
use App\Analysis\Dto\OpenCrossProbabilityResult;
use App\Analysis\Dto\Validation\TrainTestResult;
use App\Analysis\Engine\OpenCrossProbabilityEngine;
use App\Analysis\Exception\AnalysisException;

/**
 * Handles chronological train/test data splitting for out-of-sample validation.
 *
 * IMPORTANT: Random shuffling is strictly prohibited to maintain temporal integrity.
 */
final class TrainTestSplitter
{
    public function __construct(
        private readonly OpenCrossProbabilityEngine $engine
    ) {}

    /**
     * Split records chronologically and build surfaces.
     *
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float}>  $records  All OHLCV records
     * @param  ValidationConfig  $config  Validation configuration
     * @param  callable|null  $progressCallback  Optional progress callback
     *
     * @throws AnalysisException If split cannot be performed
     */
    public function splitAndBuild(
        array $records,
        ValidationConfig $config,
        ?callable $progressCallback = null
    ): TrainTestResult {
        // Validate chronological split
        if (! $config->hasTrainTestSplit()) {
            throw AnalysisException::invalidConfiguration(
                'Train/test split requires both train and test date ranges to be specified.'
            );
        }

        // Split records chronologically
        $splitData = $this->splitRecords($records, $config);

        // Build surface on training data only
        $trainConfig = $config->toAnalysisConfig(
            $config->trainStartTimestamp,
            $config->trainEndTimestamp
        );

        $trainSurface = $this->engine->analyze($trainConfig, $progressCallback);

        // Evaluate on test data
        $testMetrics = $this->evaluateOnTest(
            $trainSurface,
            $splitData['test'],
            $config
        );

        return new TrainTestResult(
            trainSurface: $trainSurface,
            trainObservations: count($splitData['train']),
            testObservations: count($splitData['test']),
            trainPeriod: [
                'start' => date('Y-m-d', $config->trainStartTimestamp),
                'end' => date('Y-m-d', $config->trainEndTimestamp),
            ],
            testPeriod: [
                'start' => date('Y-m-d', $config->testStartTimestamp),
                'end' => date('Y-m-d', $config->testEndTimestamp),
            ],
            meanPredictedProbability: $testMetrics['mean_predicted'],
            meanRealizedFrequency: $testMetrics['mean_realized'],
            absoluteCalibrationError: $testMetrics['calibration_error']
        );
    }

    /**
     * Split records into train and test sets chronologically.
     *
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float}>  $records  All records
     * @param  ValidationConfig  $config  Configuration
     * @return array{train: array<int, array{timestamp: int, open: float, high: float, low: float, close: float}>, test: array<int, array{timestamp: int, open: float, high: float, low: float, close: float}>}
     *
     * @throws AnalysisException If split validation fails
     */
    public function splitRecords(array $records, ValidationConfig $config): array
    {
        $trainRecords = [];
        $testRecords = [];

        foreach ($records as $record) {
            $timestamp = $record['timestamp'];

            // Assign to train set
            if ($timestamp >= $config->trainStartTimestamp && $timestamp <= $config->trainEndTimestamp) {
                $trainRecords[] = $record;

                continue;
            }

            // Assign to test set
            if ($timestamp >= $config->testStartTimestamp && $timestamp <= $config->testEndTimestamp) {
                $testRecords[] = $record;
            }
        }

        // Validate chronological order (no overlap)
        $this->validateChronologicalIntegrity($trainRecords, $testRecords, $config);

        return [
            'train' => $trainRecords,
            'test' => $testRecords,
        ];
    }

    /**
     * Validate that the split maintains chronological integrity.
     *
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float}>  $trainRecords  Training records
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float}>  $testRecords  Test records
     * @param  ValidationConfig  $config  Configuration
     *
     * @throws AnalysisException If integrity check fails
     */
    private function validateChronologicalIntegrity(
        array $trainRecords,
        array $testRecords,
        ValidationConfig $config
    ): void {
        // Check for empty sets
        if (empty($trainRecords)) {
            throw AnalysisException::insufficientData('No records found in training period.');
        }

        if (empty($testRecords)) {
            throw AnalysisException::insufficientData('No records found in test period.');
        }

        // Get max train timestamp and min test timestamp
        $maxTrainTimestamp = max(array_column($trainRecords, 'timestamp'));
        $minTestTimestamp = min(array_column($testRecords, 'timestamp'));

        // Ensure test comes after train (chronological order)
        if ($minTestTimestamp <= $maxTrainTimestamp) {
            throw AnalysisException::invalidConfiguration(
                'Test period must start after train period ends. '.
                'Train ends: '.date('Y-m-d H:i:s', $maxTrainTimestamp).', '.
                'Test starts: '.date('Y-m-d H:i:s', $minTestTimestamp)
            );
        }

        // Verify no random shuffling occurred
        $this->assertNoShuffling($trainRecords, 'train');
        $this->assertNoShuffling($testRecords, 'test');
    }

    /**
     * Assert that records are in chronological order (no shuffling).
     *
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float}>  $records  Records to check
     * @param  string  $setName  Name of the set for error messages
     *
     * @throws AnalysisException If shuffling is detected
     */
    private function assertNoShuffling(array $records, string $setName): void
    {
        $prevTimestamp = 0;

        foreach ($records as $record) {
            if ($record['timestamp'] < $prevTimestamp) {
                throw AnalysisException::invalidConfiguration(
                    "Records in {$setName} set are not in chronological order. ".
                    'Random shuffling is strictly prohibited for out-of-sample validation.'
                );
            }
            $prevTimestamp = $record['timestamp'];
        }
    }

    /**
     * Evaluate the trained surface on test data.
     *
     * @param  OpenCrossProbabilityResult  $surface  Trained probability surface
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float, future_min_low?: float, future_max_high?: float}>  $testRecords  Test records
     * @param  ValidationConfig  $config  Configuration
     * @return array{mean_predicted: float, mean_realized: float, calibration_error: float}
     */
    private function evaluateOnTest(
        OpenCrossProbabilityResult $surface,
        array $testRecords,
        ValidationConfig $config
    ): array {
        // Build a lookup map from the surface
        $surfaceMap = $this->buildSurfaceMap($surface);

        $predictions = [];
        $outcomes = [];

        // Partition test records into blocks
        $blocks = $this->partitionIntoBlocks($testRecords, $config->blockMinutes);

        foreach ($blocks as $blockData) {
            $this->evaluateBlock($blockData, $surfaceMap, $config, $predictions, $outcomes);
        }

        // Calculate metrics
        $meanPredicted = empty($predictions) ? 0.0 : array_sum($predictions) / count($predictions);
        $meanRealized = empty($outcomes) ? 0.0 : array_sum($outcomes) / count($outcomes);
        $calibrationError = abs($meanPredicted - $meanRealized);

        return [
            'mean_predicted' => $meanPredicted,
            'mean_realized' => $meanRealized,
            'calibration_error' => $calibrationError,
        ];
    }

    /**
     * Build a lookup map from the probability surface.
     *
     * @param  OpenCrossProbabilityResult  $surface  Probability surface
     * @return array<string, array<int, float>> Map of [bucket_key][minutes_remaining] => probability
     */
    private function buildSurfaceMap(OpenCrossProbabilityResult $surface): array
    {
        $map = [];

        foreach ($surface->probabilitySurface as $point) {
            $bucketKey = sprintf('%.6f', $point->distanceBucket);
            $minutes = $point->minutesRemaining;

            if (! isset($map[$bucketKey])) {
                $map[$bucketKey] = [];
            }

            $map[$bucketKey][$minutes] = $point->crossProbability;
        }

        return $map;
    }

    /**
     * Partition test records into blocks.
     *
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float}>  $records  Test records
     * @param  int  $blockMinutes  Block duration in minutes
     * @return array<int, array{records: array<int, array{timestamp: int, open: float, high: float, low: float, close: float, future_min_low?: float, future_max_high?: float}>, block_open: float, block_length: int}>
     */
    private function partitionIntoBlocks(array $records, int $blockMinutes): array
    {
        $blocks = [];
        $currentBlock = [];
        $currentBlockStart = null;
        $blockSeconds = $blockMinutes * 60;

        foreach ($records as $record) {
            $timestamp = $record['timestamp'];
            $blockStart = (int) (floor($timestamp / $blockSeconds) * $blockSeconds);

            if ($currentBlockStart === null) {
                $currentBlockStart = $blockStart;
            }

            if ($blockStart !== $currentBlockStart) {
                if (count($currentBlock) > 0) {
                    $blocks[] = $this->finalizeBlock($currentBlock);
                }
                $currentBlock = [];
                $currentBlockStart = $blockStart;
            }

            $currentBlock[] = $record;
        }

        if (count($currentBlock) > 0) {
            $blocks[] = $this->finalizeBlock($currentBlock);
        }

        return $blocks;
    }

    /**
     * Finalize a block by computing future ranges.
     *
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float}>  $records  Block records
     * @return array{records: array<int, array{timestamp: int, open: float, high: float, low: float, close: float, future_min_low: float, future_max_high: float}>, block_open: float, block_length: int}
     */
    private function finalizeBlock(array $records): array
    {
        $count = count($records);

        // Compute future ranges using backward scan
        $futureMinLow = PHP_FLOAT_MAX;
        $futureMaxHigh = PHP_FLOAT_MIN;

        $finalizedRecords = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $tempMinLow = $futureMinLow;
            $tempMaxHigh = $futureMaxHigh;

            $currentLow = (float) $records[$i]['low'];
            $currentHigh = (float) $records[$i]['high'];

            $futureMinLow = min($futureMinLow, $currentLow);
            $futureMaxHigh = max($futureMaxHigh, $currentHigh);

            if ($i < $count - 1) {
                $finalizedRecords[$i] = [
                    'timestamp' => $records[$i]['timestamp'],
                    'open' => $records[$i]['open'],
                    'high' => $records[$i]['high'],
                    'low' => $records[$i]['low'],
                    'close' => $records[$i]['close'],
                    'future_min_low' => $tempMinLow,
                    'future_max_high' => $tempMaxHigh,
                ];
            } else {
                $finalizedRecords[$i] = [
                    'timestamp' => $records[$i]['timestamp'],
                    'open' => $records[$i]['open'],
                    'high' => $records[$i]['high'],
                    'low' => $records[$i]['low'],
                    'close' => $records[$i]['close'],
                    'future_min_low' => $currentLow,
                    'future_max_high' => $currentHigh,
                ];
            }
        }
        $finalizedRecords = array_values($finalizedRecords);

        return [
            'records' => $finalizedRecords,
            'block_open' => (float) $records[0]['open'],
            'block_length' => $count,
        ];
    }

    /**
     * Evaluate a single block against the surface.
     *
     * @param  array{records: array<int, array{timestamp: int, open: float, high: float, low: float, close: float, future_min_low: float, future_max_high: float}>, block_open: float, block_length: int}  $blockData  Block data
     * @param  array<string, array<int, float>>  $surfaceMap  Surface lookup map
     * @param  ValidationConfig  $config  Configuration
     * @param  array<int, float>  $predictions  Predictions array (output)
     * @param  array<int, int>  $outcomes  Outcomes array (output)
     */
    private function evaluateBlock(
        array $blockData,
        array $surfaceMap,
        ValidationConfig $config,
        array &$predictions,
        array &$outcomes
    ): void {
        $records = $blockData['records'];
        $blockOpen = $blockData['block_open'];
        $blockLength = $blockData['block_length'];

        foreach ($records as $index => $record) {
            if ($index >= $blockLength - 1) {
                continue;
            }

            // Calculate distance
            $price = (float) $record['close'];
            $distance = $blockOpen > 0 ? ($price - $blockOpen) / $blockOpen : 0.0;

            // Get bucket key
            $bucketKey = sprintf('%.6f', floor($distance / $config->bucketSize) * $config->bucketSize);
            $minutesRemaining = $blockLength - 1 - $index;

            // Look up probability from surface
            $probability = $surfaceMap[$bucketKey][$minutesRemaining] ?? null;

            if ($probability !== null) {
                $predictions[] = $probability;

                // Determine actual outcome
                $crossed = $this->determineCross(
                    $distance,
                    $blockOpen,
                    $record['future_min_low'],
                    $record['future_max_high']
                );
                $outcomes[] = $crossed ? 1 : 0;
            }
        }
    }

    /**
     * Determine if a cross occurred.
     *
     * @param  float  $distance  Current distance from open
     * @param  float  $blockOpen  Block open price
     * @param  float  $futureMinLow  Minimum low in future
     * @param  float  $futureMaxHigh  Maximum high in future
     */
    private function determineCross(
        float $distance,
        float $blockOpen,
        float $futureMinLow,
        float $futureMaxHigh
    ): bool {
        if (abs($distance) < 0.0000001) {
            return false;
        }

        if ($distance > 0) {
            return $futureMinLow < $blockOpen;
        } else {
            return $futureMaxHigh > $blockOpen;
        }
    }
}
