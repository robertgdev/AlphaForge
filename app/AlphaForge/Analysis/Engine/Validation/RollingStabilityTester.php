<?php

namespace App\AlphaForge\Analysis\Engine\Validation;

use App\AlphaForge\Analysis\Config\ValidationConfig;
use App\AlphaForge\Analysis\Dto\OpenCrossProbabilityResult;
use App\AlphaForge\Analysis\Dto\Validation\StabilityReport;
use App\AlphaForge\Analysis\Dto\Validation\StabilityWindow;
use App\AlphaForge\Analysis\Engine\OpenCrossProbabilityEngine;
use App\AlphaForge\Analysis\Exception\AnalysisException;

/**
 * Tests probability surface stability across rolling time windows.
 */
final class RollingStabilityTester
{
    public function __construct(
        private readonly OpenCrossProbabilityEngine $engine
    ) {}

    /**
     * Test rolling stability of probability surfaces.
     *
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float}>  $records  All OHLCV records
     * @param  ValidationConfig  $config  Configuration
     * @param  callable|null  $progressCallback  Optional progress callback
     *
     * @throws AnalysisException If testing fails
     */
    public function test(
        array $records,
        ValidationConfig $config,
        ?callable $progressCallback = null
    ): StabilityReport {
        // Generate rolling windows
        $windows = $this->generateWindows($records, $config);

        if (count($windows) < 2) {
            throw AnalysisException::insufficientData(
                'At least 2 rolling windows are required for stability testing. '.
                'Consider increasing the date range or decreasing window size.'
            );
        }

        // Build surfaces for each window
        $surfaces = [];
        $totalWindows = count($windows);
        $processedWindows = 0;

        foreach ($windows as $window) {
            $windowConfig = $config->toAnalysisConfig(
                $window['start_timestamp'],
                $window['end_timestamp']
            );

            $surfaces[] = [
                'window' => $window,
                'surface' => $this->engine->analyze($windowConfig),
            ];

            $processedWindows++;

            if ($progressCallback !== null) {
                $progressCallback($processedWindows, $totalWindows);
            }
        }

        // Compare consecutive windows
        $stabilityWindows = [];
        $correlations = [];

        for ($i = 1; $i < count($surfaces); $i++) {
            $prev = $surfaces[$i - 1];
            $curr = $surfaces[$i];

            $comparison = $this->compareSurfaces(
                $prev['surface'],
                $curr['surface']
            );

            $stabilityWindows[] = new StabilityWindow(
                windowStart: $curr['window']['start_date'],
                windowEnd: $curr['window']['end_date'],
                meanDifference: $comparison['mean_difference'],
                maxDifference: $comparison['max_difference'],
                correlation: $comparison['correlation']
            );

            $correlations[] = $comparison['correlation'];
        }

        // Calculate overall metrics
        $meanCorrelation = empty($correlations) ? 0.0 : array_sum($correlations) / count($correlations);
        $maxDrift = $this->calculateMaxDrift($stabilityWindows);
        $overallStabilityScore = $meanCorrelation;
        $isStable = $meanCorrelation >= 0.85 && $maxDrift <= 0.15;

        return new StabilityReport(
            windows: $stabilityWindows,
            overallStabilityScore: $overallStabilityScore,
            meanCorrelation: $meanCorrelation,
            maxDrift: $maxDrift,
            isStable: $isStable
        );
    }

    /**
     * Generate rolling time windows.
     *
     * @param  array<int, array{timestamp: int}>  $records  All records
     * @param  ValidationConfig  $config  Configuration
     * @return array<int, array{start_timestamp: int, end_timestamp: int, start_date: string, end_date: string}> Array of window definitions
     */
    private function generateWindows(array $records, ValidationConfig $config): array
    {
        if (empty($records)) {
            return [];
        }

        // Get date range from records
        $timestamps = array_column($records, 'timestamp');
        $minTimestamp = min($timestamps);
        $maxTimestamp = max($timestamps);

        // Convert window/step sizes to seconds
        $windowSeconds = $config->rollingWindowMonths * 30 * 24 * 60 * 60; // Approximate
        $stepSeconds = $config->rollingStepMonths * 30 * 24 * 60 * 60;

        $windows = [];
        $currentStart = $minTimestamp;

        while ($currentStart + $windowSeconds <= $maxTimestamp) {
            $currentEnd = $currentStart + $windowSeconds;

            $windows[] = [
                'start_timestamp' => $currentStart,
                'end_timestamp' => $currentEnd,
                'start_date' => date('Y-m-d', $currentStart),
                'end_date' => date('Y-m-d', $currentEnd),
            ];

            $currentStart += $stepSeconds;
        }

        return $windows;
    }

    /**
     * Compare two probability surfaces.
     *
     * @param  OpenCrossProbabilityResult  $surface1  First surface
     * @param  OpenCrossProbabilityResult  $surface2  Second surface
     * @return array{mean_difference: float, max_difference: float, correlation: float} Comparison metrics
     */
    public function compareSurfaces(
        OpenCrossProbabilityResult $surface1,
        OpenCrossProbabilityResult $surface2
    ): array {
        // Build maps for comparison
        $map1 = $this->buildProbabilityMap($surface1);
        $map2 = $this->buildProbabilityMap($surface2);

        // Find common keys
        $commonKeys = array_intersect_key($map1, $map2);

        if (empty($commonKeys)) {
            return [
                'mean_difference' => 1.0,
                'max_difference' => 1.0,
                'correlation' => 0.0,
            ];
        }

        // Calculate differences
        $differences = [];
        $values1 = [];
        $values2 = [];

        foreach ($commonKeys as $key => $prob1) {
            $prob2 = $map2[$key];
            $differences[] = abs($prob1 - $prob2);
            $values1[] = $prob1;
            $values2[] = $prob2;
        }

        $meanDifference = array_sum($differences) / count($differences);
        $maxDifference = max($differences);

        // Calculate correlation
        $correlation = $this->calculateCorrelation($values1, $values2);

        return [
            'mean_difference' => $meanDifference,
            'max_difference' => $maxDifference,
            'correlation' => $correlation,
        ];
    }

    /**
     * Build a flat probability map from a surface.
     *
     * @param  OpenCrossProbabilityResult  $surface  Probability surface
     * @return array<string, float> Map of bucket_minutes => probability
     */
    private function buildProbabilityMap(OpenCrossProbabilityResult $surface): array
    {
        $map = [];

        foreach ($surface->probabilitySurface as $point) {
            $key = sprintf('%.6f_%d', $point->distanceBucket, $point->minutesRemaining);
            $map[$key] = $point->crossProbability;
        }

        return $map;
    }

    /**
     * Calculate Pearson correlation coefficient.
     *
     * @param  array<int, float>  $values1  First set of values
     * @param  array<int, float>  $values2  Second set of values
     * @return float Correlation coefficient
     */
    private function calculateCorrelation(array $values1, array $values2): float
    {
        $n = count($values1);

        if ($n === 0 || $n !== count($values2)) {
            return 0.0;
        }

        $mean1 = array_sum($values1) / $n;
        $mean2 = array_sum($values2) / $n;

        $numerator = 0.0;
        $denom1 = 0.0;
        $denom2 = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $diff1 = $values1[$i] - $mean1;
            $diff2 = $values2[$i] - $mean2;

            $numerator += $diff1 * $diff2;
            $denom1 += $diff1 * $diff1;
            $denom2 += $diff2 * $diff2;
        }

        $denominator = sqrt($denom1 * $denom2);

        return $denominator > 0 ? $numerator / $denominator : 0.0;
    }

    /**
     * Calculate maximum drift across all windows.
     *
     * @param  array<int, StabilityWindow>  $stabilityWindows  Stability windows
     */
    private function calculateMaxDrift(array $stabilityWindows): float
    {
        if (empty($stabilityWindows)) {
            return 0.0;
        }

        $maxDrift = 0.0;

        foreach ($stabilityWindows as $window) {
            if ($window->meanDifference > $maxDrift) {
                $maxDrift = $window->meanDifference;
            }
        }

        return $maxDrift;
    }
}
