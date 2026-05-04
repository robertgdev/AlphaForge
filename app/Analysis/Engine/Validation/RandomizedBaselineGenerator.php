<?php

namespace App\Analysis\Engine\Validation;

use App\Analysis\Config\ValidationConfig;
use App\Analysis\Dto\OpenCrossProbabilityResult;
use App\Analysis\Dto\Validation\RandomizationReport;
use App\Analysis\Engine\StatisticsAccumulator;

/**
 * Generates randomized baseline surfaces to detect if observed structure exceeds randomness.
 */
final class RandomizedBaselineGenerator
{
    /**
     * Generate randomized baseline and compare to original surface.
     *
     * @param  OpenCrossProbabilityResult  $originalSurface  Original probability surface
     * @param  array  $records  All OHLCV records
     * @param  ValidationConfig  $config  Configuration
     * @param  callable|null  $progressCallback  Optional progress callback
     */
    public function generate(
        OpenCrossProbabilityResult $originalSurface,
        array $records,
        ValidationConfig $config,
        ?callable $progressCallback = null
    ): RandomizationReport {
        $iterations = $config->randomizationIterations;
        $iterationResults = [];
        $surfaceDifferences = [];
        $structuralScores = [];
        $calibrationDegradations = [];

        // Build original surface map
        $originalMap = $this->buildSurfaceMap($originalSurface);

        // Partition records into blocks
        $blocks = $this->partitionIntoBlocks($records, $config->blockMinutes);

        for ($i = 0; $i < $iterations; $i++) {
            // Generate randomized surface
            $randomizedSurface = $this->generateRandomizedSurface($blocks, $config);
            $randomizedMap = $this->buildSurfaceMap($randomizedSurface);

            // Compare to original
            $comparison = $this->compareSurfaces($originalMap, $randomizedMap);
            $surfaceDifferences[] = $comparison['mean_difference'];

            // Calculate structural deviation score
            $structuralScore = $this->calculateStructuralDeviationScore(
                $originalMap,
                $randomizedMap
            );
            $structuralScores[] = $structuralScore;

            // Calculate calibration degradation
            $calibrationDegradation = $this->calculateCalibrationDegradation(
                $originalSurface,
                $randomizedSurface
            );
            $calibrationDegradations[] = $calibrationDegradation;

            $iterationResults[] = [
                'iteration' => $i + 1,
                'mean_difference' => round($comparison['mean_difference'], 4),
                'structural_score' => round($structuralScore, 4),
                'calibration_degradation' => round($calibrationDegradation, 4),
            ];

            if ($progressCallback !== null) {
                $progressCallback($i + 1, $iterations);
            }
        }

        // Calculate aggregate metrics
        $meanSurfaceDifference = array_sum($surfaceDifferences) / count($surfaceDifferences);
        $meanStructuralScore = array_sum($structuralScores) / count($structuralScores);
        $meanCalibrationDegradation = array_sum($calibrationDegradations) / count($calibrationDegradations);

        // Determine if significantly different
        $isSignificantlyDifferent = $this->determineSignificance(
            $meanStructuralScore,
            $meanSurfaceDifference
        );

        return new RandomizationReport(
            meanSurfaceDifference: $meanSurfaceDifference,
            structuralDeviationScore: $meanStructuralScore,
            calibrationDegradation: $meanCalibrationDegradation,
            isSignificantlyDifferent: $isSignificantlyDifferent,
            iterations: $iterations,
            iterationResults: $iterationResults
        );
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
                    $blocks[] = $currentBlock;
                }
                $currentBlock = [];
                $currentBlockStart = $blockStart;
            }

            $currentBlock[] = $record;
        }

        if (count($currentBlock) > 0) {
            $blocks[] = $currentBlock;
        }

        return $blocks;
    }

    /**
     * Generate a randomized surface by permuting blocks.
     *
     * @param  array  $blocks  Original blocks
     * @param  ValidationConfig  $config  Configuration
     */
    private function generateRandomizedSurface(array $blocks, ValidationConfig $config): OpenCrossProbabilityResult
    {
        $accumulator = new StatisticsAccumulator;

        foreach ($blocks as $blockRecords) {
            // Permute the block
            $permutedBlock = $this->permuteBlock($blockRecords);

            // Process the permuted block
            $this->processBlock($permutedBlock, $config, $accumulator);
        }

        // Create result
        $results = $accumulator->getResults();

        // Build a minimal result object
        return $this->createSurfaceFromAccumulator($results, count($blocks), $accumulator->getTotalObservations(), $config);
    }

    /**
     * Permute a block by shuffling minute order.
     *
     * @param  array  $blockRecords  Block records
     * @return array Permuted block
     */
    private function permuteBlock(array $blockRecords): array
    {
        // Shuffle the records within the block
        $shuffled = $blockRecords;
        shuffle($shuffled);

        // Re-index timestamps to maintain temporal sequence
        // but with shuffled OHLCV data
        $baseTimestamp = $blockRecords[0]['timestamp'];
        $permuted = [];

        foreach ($shuffled as $index => $record) {
            $permuted[] = [
                'timestamp' => $baseTimestamp + $index * 60,
                'open' => $record['open'],
                'high' => $record['high'],
                'low' => $record['low'],
                'close' => $record['close'],
                'volume' => $record['volume'],
            ];
        }

        return $permuted;
    }

    /**
     * Process a block and accumulate statistics.
     *
     * @param  array  $blockRecords  Block records
     * @param  ValidationConfig  $config  Configuration
     * @param  StatisticsAccumulator  $accumulator  Accumulator
     */
    private function processBlock(
        array $blockRecords,
        ValidationConfig $config,
        StatisticsAccumulator $accumulator
    ): void {
        $count = count($blockRecords);

        if ($count === 0) {
            return;
        }

        $blockOpen = (float) $blockRecords[0]['open'];

        // Compute future ranges
        $futureMinLow = PHP_FLOAT_MAX;
        $futureMaxHigh = PHP_FLOAT_MIN;

        for ($i = $count - 1; $i >= 0; $i--) {
            $tempMinLow = $futureMinLow;
            $tempMaxHigh = $futureMaxHigh;

            $currentLow = (float) $blockRecords[$i]['low'];
            $currentHigh = (float) $blockRecords[$i]['high'];

            $futureMinLow = min($futureMinLow, $currentLow);
            $futureMaxHigh = max($futureMaxHigh, $currentHigh);

            if ($i < $count - 1) {
                $blockRecords[$i]['future_min_low'] = $tempMinLow;
                $blockRecords[$i]['future_max_high'] = $tempMaxHigh;
            } else {
                $blockRecords[$i]['future_min_low'] = $currentLow;
                $blockRecords[$i]['future_max_high'] = $currentHigh;
            }
        }

        // Process each minute
        foreach ($blockRecords as $index => $record) {
            if ($index >= $count - 1) {
                continue;
            }

            $price = (float) $record['close'];
            $distance = $blockOpen > 0 ? ($price - $blockOpen) / $blockOpen : 0.0;

            $distanceBucket = floor($distance / $config->bucketSize) * $config->bucketSize;
            if ($config->mergeSymmetric) {
                $distanceBucket = abs($distanceBucket);
            }

            $minutesRemaining = $count - 1 - $index;

            $crossed = $this->determineCross(
                $distance,
                $blockOpen,
                $record['future_min_low'],
                $record['future_max_high']
            );

            $accumulator->record($distanceBucket, $minutesRemaining, $crossed);
        }
    }

    /**
     * Determine if a cross occurred.
     *
     * @param  float  $distance  Distance
     * @param  float  $blockOpen  Block open
     * @param  float  $futureMinLow  Future min low
     * @param  float  $futureMaxHigh  Future max high
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
     * Create a surface from accumulator results.
     *
     * @param  array  $results  Accumulator results
     * @param  int  $totalBlocks  Total blocks
     * @param  int  $totalObservations  Total observations
     * @param  ValidationConfig  $config  Configuration
     */
    private function createSurfaceFromAccumulator(
        array $results,
        int $totalBlocks,
        int $totalObservations,
        ValidationConfig $config
    ): OpenCrossProbabilityResult {
        $points = [];

        foreach ($results as $data) {
            $probability = $data['total'] > 0 ? $data['crosses'] / $data['total'] : 0.0;
            $confidence = $data['total'] >= $config->minimumSamples ? 'high' :
                         ($data['total'] >= 30 ? 'medium' : 'low');

            $points[] = [
                'distance_bucket' => $data['bucket'],
                'minutes_remaining' => $data['minutes_remaining'],
                'samples' => $data['total'],
                'cross_probability' => $probability,
                'confidence' => $confidence,
            ];
        }

        // Create a minimal surface representation
        return new class($points, $totalBlocks, $totalObservations) extends OpenCrossProbabilityResult
        {
            public function __construct(
                private array $pointsData,
                int $totalBlocks,
                int $totalObservations
            ) {
                parent::__construct(
                    [],
                    $totalBlocks,
                    $totalObservations,
                    ['randomized' => true]
                );
            }

            public function getSurfaceData(): array
            {
                return $this->pointsData;
            }
        };
    }

    /**
     * Build a surface map for comparison.
     *
     * @param  OpenCrossProbabilityResult  $surface  Probability surface
     */
    private function buildSurfaceMap(OpenCrossProbabilityResult $surface): array
    {
        $map = [];

        foreach ($surface->probabilitySurface as $point) {
            $key = sprintf('%.6f_%d', $point->distanceBucket, $point->minutesRemaining);
            $map[$key] = $point->crossProbability;
        }

        return $map;
    }

    /**
     * Compare two surfaces.
     *
     * @param  array  $map1  First surface map
     * @param  array  $map2  Second surface map
     */
    private function compareSurfaces(array $map1, array $map2): array
    {
        $commonKeys = array_intersect_key($map1, $map2);

        if (empty($commonKeys)) {
            return [
                'mean_difference' => 1.0,
                'max_difference' => 1.0,
            ];
        }

        $differences = [];
        foreach ($commonKeys as $key => $prob1) {
            $prob2 = $map2[$key];
            $differences[] = abs($prob1 - $prob2);
        }

        return [
            'mean_difference' => array_sum($differences) / count($differences),
            'max_difference' => max($differences),
        ];
    }

    /**
     * Calculate structural deviation score.
     *
     * This measures how much the structure of the surface differs,
     * not just the probability values.
     *
     * @param  array  $originalMap  Original surface map
     * @param  array  $randomizedMap  Randomized surface map
     */
    private function calculateStructuralDeviationScore(array $originalMap, array $randomizedMap): float
    {
        // Calculate gradient structure for original
        $originalGradients = $this->calculateGradients($originalMap);
        $randomizedGradients = $this->calculateGradients($randomizedMap);

        // Compare gradient structures
        $commonKeys = array_intersect_key($originalGradients, $randomizedGradients);

        if (empty($commonKeys)) {
            return 1.0;
        }

        $gradientDifferences = [];
        foreach ($commonKeys as $key => $gradient1) {
            $gradient2 = $randomizedGradients[$key];
            $gradientDifferences[] = abs($gradient1 - $gradient2);
        }

        return array_sum($gradientDifferences) / count($gradientDifferences);
    }

    /**
     * Calculate gradients in the probability surface.
     *
     * @param  array  $surfaceMap  Surface map
     * @return array Gradient map
     */
    private function calculateGradients(array $surfaceMap): array
    {
        $gradients = [];

        foreach ($surfaceMap as $key => $probability) {
            // Parse key
            $parts = explode('_', $key);
            $bucket = (float) $parts[0];
            $minutes = (int) $parts[1];

            // Calculate gradient with respect to distance
            $neighborKey = sprintf('%.6f_%d', $bucket + 0.001, $minutes);
            if (isset($surfaceMap[$neighborKey])) {
                $gradients[$key.'_dist'] = $surfaceMap[$neighborKey] - $probability;
            }

            // Calculate gradient with respect to time
            $timeNeighborKey = sprintf('%.6f_%d', $bucket, $minutes + 1);
            if (isset($surfaceMap[$timeNeighborKey])) {
                $gradients[$key.'_time'] = $surfaceMap[$timeNeighborKey] - $probability;
            }
        }

        return $gradients;
    }

    /**
     * Calculate calibration degradation.
     *
     * @param  OpenCrossProbabilityResult  $original  Original surface
     * @param  OpenCrossProbabilityResult  $randomized  Randomized surface
     */
    private function calculateCalibrationDegradation(
        OpenCrossProbabilityResult $original,
        OpenCrossProbabilityResult $randomized
    ): float {
        // Calculate entropy of each surface
        $originalEntropy = $this->calculateEntropy($original);
        $randomizedEntropy = $this->calculateEntropy($randomized);

        // Higher entropy in randomized = more uniform = less structure
        return max(0, $randomizedEntropy - $originalEntropy);
    }

    /**
     * Calculate entropy of a probability surface.
     *
     * @param  OpenCrossProbabilityResult  $surface  Probability surface
     */
    private function calculateEntropy(OpenCrossProbabilityResult $surface): float
    {
        $entropy = 0.0;

        foreach ($surface->probabilitySurface as $point) {
            $p = $point->crossProbability;
            if ($p > 0 && $p < 1) {
                $entropy -= $p * log($p) + (1 - $p) * log(1 - $p);
            }
        }

        return $entropy;
    }

    /**
     * Determine if the difference from randomized is significant.
     *
     * @param  float  $structuralScore  Structural deviation score
     * @param  float  $meanDifference  Mean surface difference
     */
    private function determineSignificance(float $structuralScore, float $meanDifference): bool
    {
        // The original surface should have:
        // 1. Significant structural deviation from random (> 0.1)
        // 2. Significant mean difference (> 0.05)

        return $structuralScore > 0.1 && $meanDifference > 0.05;
    }
}
