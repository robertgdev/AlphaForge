<?php

namespace App\AlphaForge\Analysis\Engine\Validation;

use App\AlphaForge\Analysis\Config\ValidationConfig;
use App\AlphaForge\Analysis\Dto\OpenCrossProbabilityResult;
use App\AlphaForge\Analysis\Dto\Validation\CalibrationBin;
use App\AlphaForge\Analysis\Dto\Validation\CalibrationReport;

/**
 * Tests probability calibration by comparing predicted probabilities to observed frequencies.
 */
final class CalibrationTester
{
    /**
     * Test calibration of a probability surface against test data.
     *
     * @param  OpenCrossProbabilityResult  $surface  Probability surface to test
     * @param  array  $testRecords  Test records to evaluate against
     * @param  ValidationConfig  $config  Configuration
     */
    public function test(
        OpenCrossProbabilityResult $surface,
        array $testRecords,
        ValidationConfig $config
    ): CalibrationReport {
        // Get predictions and outcomes
        $predictions = $this->collectPredictionsAndOutcomes($surface, $testRecords, $config);

        if (empty($predictions)) {
            return $this->createEmptyReport();
        }

        // Compute calibration bins
        $bins = $this->computeCalibrationBins($predictions, $config->calibrationBinWidth);

        // Compute metrics
        $brierScore = $this->computeBrierScore($predictions);
        $meanAbsoluteError = $this->computeMeanAbsoluteCalibrationError($bins);
        $maxDeviation = $this->computeMaxCalibrationDeviation($bins);

        // Determine if calibrated
        $isCalibrated = $this->determineCalibrationStatus($bins, $meanAbsoluteError);

        $totalSamples = array_sum(array_column($predictions, 'count'));

        return new CalibrationReport(
            bins: $bins,
            brierScore: $brierScore,
            meanAbsoluteCalibrationError: $meanAbsoluteError,
            maxCalibrationDeviation: $maxDeviation,
            isCalibrated: $isCalibrated,
            totalSamples: $totalSamples
        );
    }

    /**
     * Collect predictions and outcomes from test data.
     *
     * @param  OpenCrossProbabilityResult  $surface  Probability surface
     * @param  array  $testRecords  Test records
     * @param  ValidationConfig  $config  Configuration
     * @return array Array of [predicted, actual, count] entries
     */
    private function collectPredictionsAndOutcomes(
        OpenCrossProbabilityResult $surface,
        array $testRecords,
        ValidationConfig $config
    ): array {
        // Build surface lookup map
        $surfaceMap = $this->buildSurfaceMap($surface);

        $predictions = [];
        $blocks = $this->partitionIntoBlocks($testRecords, $config->blockMinutes);

        foreach ($blocks as $blockData) {
            $this->collectBlockPredictions($blockData, $surfaceMap, $config, $predictions);
        }

        return $predictions;
    }

    /**
     * Build a lookup map from the probability surface.
     *
     * @param  OpenCrossProbabilityResult  $surface  Probability surface
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

            $map[$bucketKey][$minutes] = [
                'probability' => $point->crossProbability,
                'samples' => $point->samples,
            ];
        }

        return $map;
    }

    /**
     * Partition records into blocks.
     *
     * @param  array  $records  Records
     * @param  int  $blockMinutes  Block duration
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
     * @param  array  $records  Block records
     */
    private function finalizeBlock(array $records): array
    {
        $count = count($records);

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

        return [
            'records' => $records,
            'block_open' => (float) $records[0]['open'],
            'block_length' => $count,
        ];
    }

    /**
     * Collect predictions from a single block.
     *
     * @param  array  $blockData  Block data
     * @param  array  $surfaceMap  Surface lookup map
     * @param  ValidationConfig  $config  Configuration
     * @param  array  $predictions  Predictions array (output)
     */
    private function collectBlockPredictions(
        array $blockData,
        array $surfaceMap,
        ValidationConfig $config,
        array &$predictions
    ): void {
        $records = $blockData['records'];
        $blockOpen = $blockData['block_open'];
        $blockLength = $blockData['block_length'];

        foreach ($records as $index => $record) {
            if ($index >= $blockLength - 1) {
                continue;
            }

            $price = (float) $record['close'];
            $distance = $blockOpen > 0 ? ($price - $blockOpen) / $blockOpen : 0.0;

            $bucketKey = sprintf('%.6f', floor($distance / $config->bucketSize) * $config->bucketSize);
            $minutesRemaining = $blockLength - 1 - $index;

            if (isset($surfaceMap[$bucketKey][$minutesRemaining])) {
                $predicted = $surfaceMap[$bucketKey][$minutesRemaining]['probability'];

                $crossed = $this->determineCross(
                    $distance,
                    $blockOpen,
                    $record['future_min_low'],
                    $record['future_max_high']
                );

                $predictions[] = [
                    'predicted' => $predicted,
                    'actual' => $crossed ? 1 : 0,
                    'count' => 1,
                ];
            }
        }
    }

    /**
     * Determine if a cross occurred.
     *
     * @param  float  $distance  Current distance
     * @param  float  $blockOpen  Block open price
     * @param  float  $futureMinLow  Future minimum low
     * @param  float  $futureMaxHigh  Future maximum high
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

    /**
     * Compute calibration bins.
     *
     * @param  array  $predictions  Predictions array
     * @param  float  $binWidth  Bin width
     * @return array<CalibrationBin>
     */
    private function computeCalibrationBins(array $predictions, float $binWidth): array
    {
        // Group predictions into bins
        $binData = [];

        foreach ($predictions as $pred) {
            $binIndex = (int) floor($pred['predicted'] / $binWidth);
            $binStart = $binIndex * $binWidth;
            $binEnd = $binStart + $binWidth;

            $key = sprintf('%.2f_%.2f', $binStart, $binEnd);

            if (! isset($binData[$key])) {
                $binData[$key] = [
                    'bin_start' => $binStart,
                    'bin_end' => min($binEnd, 1.0),
                    'samples' => 0,
                    'sum_predicted' => 0.0,
                    'sum_actual' => 0.0,
                ];
            }

            $binData[$key]['samples'] += $pred['count'];
            $binData[$key]['sum_predicted'] += $pred['predicted'] * $pred['count'];
            $binData[$key]['sum_actual'] += $pred['actual'] * $pred['count'];
        }

        // Convert to CalibrationBin objects
        $bins = [];
        foreach ($binData as $data) {
            if ($data['samples'] === 0) {
                continue;
            }

            $avgPredicted = $data['sum_predicted'] / $data['samples'];
            $observedFrequency = $data['sum_actual'] / $data['samples'];
            $calibrationError = abs($avgPredicted - $observedFrequency);

            $bins[] = new CalibrationBin(
                binStart: $data['bin_start'],
                binEnd: $data['bin_end'],
                samples: $data['samples'],
                avgPredictedProbability: $avgPredicted,
                observedFrequency: $observedFrequency,
                calibrationError: $calibrationError
            );
        }

        // Sort by bin start
        usort($bins, fn ($a, $b) => $a->binStart <=> $b->binStart);

        return $bins;
    }

    /**
     * Compute the Brier score.
     *
     * Brier = mean((predicted - actual)^2)
     *
     * @param  array  $predictions  Predictions array
     */
    public function computeBrierScore(array $predictions): float
    {
        if (empty($predictions)) {
            return 0.0;
        }

        $sumSquaredError = 0.0;
        $totalCount = 0;

        foreach ($predictions as $pred) {
            $error = $pred['predicted'] - $pred['actual'];
            $sumSquaredError += $error * $error * $pred['count'];
            $totalCount += $pred['count'];
        }

        return $totalCount > 0 ? $sumSquaredError / $totalCount : 0.0;
    }

    /**
     * Compute mean absolute calibration error across bins.
     *
     * @param  array  $bins  Calibration bins
     */
    private function computeMeanAbsoluteCalibrationError(array $bins): float
    {
        if (empty($bins)) {
            return 0.0;
        }

        $totalError = 0.0;
        $totalSamples = 0;

        foreach ($bins as $bin) {
            $totalError += $bin->calibrationError * $bin->samples;
            $totalSamples += $bin->samples;
        }

        return $totalSamples > 0 ? $totalError / $totalSamples : 0.0;
    }

    /**
     * Compute maximum calibration deviation.
     *
     * @param  array  $bins  Calibration bins
     */
    private function computeMaxCalibrationDeviation(array $bins): float
    {
        if (empty($bins)) {
            return 0.0;
        }

        $maxDeviation = 0.0;

        foreach ($bins as $bin) {
            if ($bin->calibrationError > $maxDeviation) {
                $maxDeviation = $bin->calibrationError;
            }
        }

        return $maxDeviation;
    }

    /**
     * Determine if the model is calibrated based on bin statistics.
     *
     * @param  array  $bins  Calibration bins
     * @param  float  $meanAbsoluteError  Mean absolute calibration error
     */
    private function determineCalibrationStatus(array $bins, float $meanAbsoluteError): bool
    {
        // Check if mean absolute error is acceptable
        if ($meanAbsoluteError > 0.05) {
            return false;
        }

        // Check for systematic bias (all errors in same direction)
        $positiveErrors = 0;
        $negativeErrors = 0;

        foreach ($bins as $bin) {
            $diff = $bin->avgPredictedProbability - $bin->observedFrequency;
            if ($diff > 0.01) {
                $positiveErrors++;
            } elseif ($diff < -0.01) {
                $negativeErrors++;
            }
        }

        // If all errors are in one direction, model has systematic bias
        if ($positiveErrors > 0 && $negativeErrors === 0) {
            return false; // Systematic over-prediction
        }
        if ($negativeErrors > 0 && $positiveErrors === 0) {
            return false; // Systematic under-prediction
        }

        return true;
    }

    /**
     * Create an empty calibration report.
     */
    private function createEmptyReport(): CalibrationReport
    {
        return new CalibrationReport(
            bins: [],
            brierScore: 0.0,
            meanAbsoluteCalibrationError: 0.0,
            maxCalibrationDeviation: 0.0,
            isCalibrated: false,
            totalSamples: 0
        );
    }
}
