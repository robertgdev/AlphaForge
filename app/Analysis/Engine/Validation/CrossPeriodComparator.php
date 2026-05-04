<?php

namespace App\Analysis\Engine\Validation;

use App\Analysis\Config\OpenCrossAnalysisConfig;
use App\Analysis\Config\ValidationConfig;
use App\Analysis\Dto\OpenCrossProbabilityResult;
use App\Analysis\Dto\Validation\CrossPeriodReport;
use App\Analysis\Engine\OpenCrossProbabilityEngine;
use App\Analysis\Exception\AnalysisException;

/**
 * Compares probability surfaces across different calendar periods.
 */
final class CrossPeriodComparator
{
    public function __construct(
        private readonly OpenCrossProbabilityEngine $engine
    ) {}

    /**
     * Compare surfaces across calendar periods.
     *
     * @param  array  $records  All OHLCV records
     * @param  ValidationConfig  $config  Configuration
     * @param  callable|null  $progressCallback  Optional progress callback
     *
     * @throws AnalysisException If comparison fails
     */
    public function compare(
        array $records,
        ValidationConfig $config,
        ?callable $progressCallback = null
    ): CrossPeriodReport {
        // Split records by period (year by default)
        $periodRecords = $this->splitByPeriod($records, 'year');

        if (count($periodRecords) < 2) {
            throw AnalysisException::insufficientData(
                'At least 2 calendar periods are required for cross-period comparison.'
            );
        }

        // Build surfaces for each period
        $surfaces = [];
        $periods = array_keys($periodRecords);
        $totalPeriods = count($periods);
        $processedPeriods = 0;

        foreach ($periodRecords as $period => $periodRecs) {
            $periodConfig = $this->createPeriodConfig($periodRecs, $config);
            $surfaces[$period] = $this->engine->analyze($periodConfig);

            $processedPeriods++;

            if ($progressCallback !== null) {
                $progressCallback($processedPeriods, $totalPeriods);
            }
        }

        // Calculate correlation matrix
        $correlations = $this->calculateCorrelationMatrix($surfaces, $periods);

        // Calculate mean absolute differences
        $differences = $this->calculateDifferenceMatrix($surfaces, $periods);

        // Check monotonicity preservation
        $monotonicityPreserved = $this->checkMonotonicityPreservation($surfaces);

        // Calculate overall persistence
        $overallPersistence = $this->calculateOverallPersistence($correlations);

        // Build period surface summaries
        $periodSurfaces = [];
        foreach ($surfaces as $period => $surface) {
            $periodSurfaces[$period] = [
                'total_blocks' => $surface->totalBlocksAnalyzed,
                'total_observations' => $surface->totalObservations,
                'surface_points' => count($surface->probabilitySurface),
            ];
        }

        return new CrossPeriodReport(
            periods: $periods,
            surfaceCorrelations: $correlations,
            meanAbsoluteDifferences: $differences,
            monotonicityPreserved: $monotonicityPreserved,
            overallPersistence: $overallPersistence,
            periodSurfaces: $periodSurfaces
        );
    }

    /**
     * Split records by calendar period.
     *
     * @param  array  $records  All records
     * @param  string  $periodType  Period type (year, quarter, month)
     * @return array Records grouped by period
     */
    private function splitByPeriod(array $records, string $periodType = 'year'): array
    {
        $periodRecords = [];

        foreach ($records as $record) {
            $timestamp = $record['timestamp'];
            $period = $this->getPeriodIdentifier($timestamp, $periodType);

            if (! isset($periodRecords[$period])) {
                $periodRecords[$period] = [];
            }

            $periodRecords[$period][] = $record;
        }

        // Sort by period
        ksort($periodRecords);

        return $periodRecords;
    }

    /**
     * Get period identifier for a timestamp.
     *
     * @param  int  $timestamp  Unix timestamp
     * @param  string  $periodType  Period type
     * @return string Period identifier
     */
    private function getPeriodIdentifier(int $timestamp, string $periodType): string
    {
        $date = getdate($timestamp);

        return match ($periodType) {
            'year' => (string) $date['year'],
            'quarter' => sprintf('%d-Q%d', $date['year'], ceil($date['mon'] / 3)),
            'month' => sprintf('%d-%02d', $date['year'], $date['mon']),
            default => (string) $date['year'],
        };
    }

    /**
     * Create a config for a specific period.
     *
     * @param  array  $periodRecords  Records for the period
     * @param  ValidationConfig  $config  Base configuration
     */
    private function createPeriodConfig(
        array $periodRecords,
        ValidationConfig $config
    ): OpenCrossAnalysisConfig {
        $timestamps = array_column($periodRecords, 'timestamp');
        $startTimestamp = min($timestamps);
        $endTimestamp = max($timestamps);

        return $config->toAnalysisConfig($startTimestamp, $endTimestamp);
    }

    /**
     * Calculate correlation matrix between period surfaces.
     *
     * @param  array  $surfaces  Surfaces by period
     * @param  array  $periods  Period identifiers
     * @return array Correlation matrix
     */
    private function calculateCorrelationMatrix(array $surfaces, array $periods): array
    {
        $n = count($periods);
        $correlations = [];

        // Initialize matrix
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $correlations[$i][$j] = $i === $j ? 1.0 : 0.0;
            }
        }

        // Calculate pairwise correlations
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $correlation = $this->calculateSurfaceCorrelation(
                    $surfaces[$periods[$i]],
                    $surfaces[$periods[$j]]
                );

                $correlations[$i][$j] = $correlation;
                $correlations[$j][$i] = $correlation;
            }
        }

        return $correlations;
    }

    /**
     * Calculate mean absolute difference matrix between period surfaces.
     *
     * @param  array  $surfaces  Surfaces by period
     * @param  array  $periods  Period identifiers
     * @return array Difference matrix
     */
    private function calculateDifferenceMatrix(array $surfaces, array $periods): array
    {
        $n = count($periods);
        $differences = [];

        // Initialize matrix
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $differences[$i][$j] = $i === $j ? 0.0 : 0.0;
            }
        }

        // Calculate pairwise differences
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $difference = $this->calculateSurfaceDifference(
                    $surfaces[$periods[$i]],
                    $surfaces[$periods[$j]]
                );

                $differences[$i][$j] = $difference;
                $differences[$j][$i] = $difference;
            }
        }

        return $differences;
    }

    /**
     * Calculate correlation between two surfaces.
     *
     * @param  OpenCrossProbabilityResult  $surface1  First surface
     * @param  OpenCrossProbabilityResult  $surface2  Second surface
     * @return float Correlation coefficient
     */
    private function calculateSurfaceCorrelation(
        OpenCrossProbabilityResult $surface1,
        OpenCrossProbabilityResult $surface2
    ): float {
        $map1 = $this->buildProbabilityMap($surface1);
        $map2 = $this->buildProbabilityMap($surface2);

        $commonKeys = array_intersect_key($map1, $map2);

        if (count($commonKeys) < 3) {
            return 0.0;
        }

        $values1 = [];
        $values2 = [];

        foreach ($commonKeys as $key => $_) {
            $values1[] = $map1[$key];
            $values2[] = $map2[$key];
        }

        return $this->pearsonCorrelation($values1, $values2);
    }

    /**
     * Calculate mean absolute difference between two surfaces.
     *
     * @param  OpenCrossProbabilityResult  $surface1  First surface
     * @param  OpenCrossProbabilityResult  $surface2  Second surface
     * @return float Mean absolute difference
     */
    private function calculateSurfaceDifference(
        OpenCrossProbabilityResult $surface1,
        OpenCrossProbabilityResult $surface2
    ): float {
        $map1 = $this->buildProbabilityMap($surface1);
        $map2 = $this->buildProbabilityMap($surface2);

        $commonKeys = array_intersect_key($map1, $map2);

        if (empty($commonKeys)) {
            return 1.0;
        }

        $totalDiff = 0.0;

        foreach ($commonKeys as $key => $_) {
            $totalDiff += abs($map1[$key] - $map2[$key]);
        }

        return $totalDiff / count($commonKeys);
    }

    /**
     * Build a probability map from a surface.
     *
     * @param  OpenCrossProbabilityResult  $surface  Probability surface
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
     * @param  array  $values1  First set of values
     * @param  array  $values2  Second set of values
     */
    private function pearsonCorrelation(array $values1, array $values2): float
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
     * Check if monotonicity is preserved across periods.
     *
     * Monotonicity: probability should generally decrease with distance from open
     * and increase with time remaining.
     *
     * @param  array  $surfaces  Surfaces by period
     */
    private function checkMonotonicityPreservation(array $surfaces): bool
    {
        $monotonicityScores = [];

        foreach ($surfaces as $period => $surface) {
            $score = $this->calculateMonotonicityScore($surface);
            $monotonicityScores[$period] = $score;
        }

        // Check if all periods have reasonable monotonicity
        foreach ($monotonicityScores as $score) {
            if ($score < 0.5) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculate monotonicity score for a surface.
     *
     * @param  OpenCrossProbabilityResult  $surface  Probability surface
     * @return float Monotonicity score (0-1)
     */
    private function calculateMonotonicityScore(OpenCrossProbabilityResult $surface): float
    {
        // Group by minutes remaining
        $byTime = [];

        foreach ($surface->probabilitySurface as $point) {
            $minutes = $point->minutesRemaining;

            if (! isset($byTime[$minutes])) {
                $byTime[$minutes] = [];
            }

            $byTime[$minutes][] = $point;
        }

        $totalChecks = 0;
        $monotonicChecks = 0;

        // Check monotonicity within each time slice
        foreach ($byTime as $points) {
            // Sort by distance bucket
            usort($points, fn ($a, $b) => $a->distanceBucket <=> $b->distanceBucket);

            // Check if probability decreases with distance from zero
            for ($i = 1; $i < count($points); $i++) {
                $prev = $points[$i - 1];
                $curr = $points[$i];

                // Only check high-confidence points
                if ($prev->confidence !== 'high' || $curr->confidence !== 'high') {
                    continue;
                }

                $totalChecks++;

                // For positive distances, probability should decrease
                // For negative distances, probability should increase (towards zero)
                if ($prev->distanceBucket < 0 && $curr->distanceBucket < 0) {
                    // Both negative: probability should increase as we approach zero
                    if ($curr->crossProbability >= $prev->crossProbability) {
                        $monotonicChecks++;
                    }
                } elseif ($prev->distanceBucket > 0 && $curr->distanceBucket > 0) {
                    // Both positive: probability should decrease as we move away from zero
                    if ($curr->crossProbability <= $prev->crossProbability) {
                        $monotonicChecks++;
                    }
                }
            }
        }

        return $totalChecks > 0 ? $monotonicChecks / $totalChecks : 0.0;
    }

    /**
     * Calculate overall persistence score.
     *
     * @param  array  $correlations  Correlation matrix
     */
    private function calculateOverallPersistence(array $correlations): float
    {
        $n = count($correlations);

        if ($n < 2) {
            return 1.0;
        }

        // Calculate mean of off-diagonal correlations
        $totalCorrelation = 0.0;
        $count = 0;

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $totalCorrelation += $correlations[$i][$j];
                $count++;
            }
        }

        return $count > 0 ? $totalCorrelation / $count : 0.0;
    }
}
