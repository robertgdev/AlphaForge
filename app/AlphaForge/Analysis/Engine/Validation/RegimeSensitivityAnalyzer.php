<?php

namespace App\AlphaForge\Analysis\Engine\Validation;

use App\AlphaForge\Analysis\Config\OpenCrossAnalysisConfig;
use App\AlphaForge\Analysis\Config\ValidationConfig;
use App\AlphaForge\Analysis\Dto\OpenCrossProbabilityResult;
use App\AlphaForge\Analysis\Dto\Validation\RegimeReport;
use App\AlphaForge\Analysis\Dto\Validation\RegimeSurface;
use App\AlphaForge\Analysis\Engine\OpenCrossProbabilityEngine;
use App\AlphaForge\Analysis\Engine\StatisticsAccumulator;
use App\AlphaForge\Analysis\Engine\VolatilityCalculator;
use App\AlphaForge\Analysis\Exception\AnalysisException;

/**
 * Analyzes probability surface sensitivity to volatility regimes.
 */
final class RegimeSensitivityAnalyzer
{
    public function __construct(
        private readonly OpenCrossProbabilityEngine $engine
    ) {}

    /**
     * Analyze regime sensitivity.
     *
     * @param  array  $records  All OHLCV records
     * @param  ValidationConfig  $config  Configuration
     * @param  callable|null  $progressCallback  Optional progress callback
     *
     * @throws AnalysisException If analysis fails
     */
    public function analyze(
        array $records,
        ValidationConfig $config,
        ?callable $progressCallback = null
    ): RegimeReport {
        // Calculate volatility for each record
        $volatilityCalculator = new VolatilityCalculator;
        $volatilities = $volatilityCalculator->calculateRollingVolatility(
            $records,
            $config->volatilityLookback
        );

        // Classify records into regimes
        $regimeRecords = $this->classifyRecordsIntoRegimes(
            $records,
            $volatilities,
            $config
        );

        if (empty($regimeRecords['low']) || empty($regimeRecords['high'])) {
            throw AnalysisException::insufficientData(
                'Insufficient data in one or more volatility regimes for analysis.'
            );
        }

        // Build surfaces for each regime
        $regimeSurfaces = [];
        $regimeCalibrations = [];
        $totalRegimes = 2; // Low and High
        $processedRegimes = 0;

        // Low volatility regime
        $lowVolSurface = $this->buildRegimeSurface(
            $regimeRecords['low'],
            $config,
            'low_volatility'
        );
        $regimeSurfaces[] = $lowVolSurface;
        $regimeCalibrations['low_volatility'] = $this->calculateRegimeCalibration($lowVolSurface);
        $processedRegimes++;

        if ($progressCallback !== null) {
            $progressCallback($processedRegimes, $totalRegimes);
        }

        // High volatility regime
        $highVolSurface = $this->buildRegimeSurface(
            $regimeRecords['high'],
            $config,
            'high_volatility'
        );
        $regimeSurfaces[] = $highVolSurface;
        $regimeCalibrations['high_volatility'] = $this->calculateRegimeCalibration($highVolSurface);
        $processedRegimes++;

        if ($progressCallback !== null) {
            $progressCallback($processedRegimes, $totalRegimes);
        }

        // Calculate surface distance
        $surfaceDistance = $this->calculateSurfaceDistance(
            $lowVolSurface->surface,
            $highVolSurface->surface
        );

        // Calculate cross-regime stability
        $crossRegimeStability = 1.0 - $surfaceDistance;

        // Determine if differences are explainable
        $isExplainable = $this->determineExplainability(
            $surfaceDistance,
            $regimeCalibrations
        );

        return new RegimeReport(
            regimeSurfaces: $regimeSurfaces,
            surfaceDistance: $surfaceDistance,
            crossRegimeStability: $crossRegimeStability,
            calibrationByRegime: $regimeCalibrations,
            isExplainable: $isExplainable
        );
    }

    /**
     * Classify records into volatility regimes.
     *
     * @param  array  $records  All records
     * @param  array  $volatilities  Volatility values
     * @param  ValidationConfig  $config  Configuration
     * @return array Records grouped by regime
     */
    private function classifyRecordsIntoRegimes(
        array $records,
        array $volatilities,
        ValidationConfig $config
    ): array {
        $regimeRecords = [
            'low' => [],
            'high' => [],
        ];

        // Calculate volatility threshold based on classifier type
        $threshold = $this->calculateVolatilityThreshold($volatilities, $config);

        foreach ($records as $index => $record) {
            if (! isset($volatilities[$index])) {
                continue;
            }

            $volatility = $volatilities[$index];
            $regime = $this->classifyRegime($volatility, $threshold, $config);

            if ($regime === 'low') {
                $regimeRecords['low'][] = $record;
            } elseif ($regime === 'high') {
                $regimeRecords['high'][] = $record;
            }
        }

        return $regimeRecords;
    }

    /**
     * Calculate volatility threshold based on configuration.
     *
     * @param  array  $volatilities  Volatility values
     * @param  ValidationConfig  $config  Configuration
     */
    private function calculateVolatilityThreshold(array $volatilities, ValidationConfig $config): float
    {
        $validVolatilities = array_filter($volatilities, fn ($v) => $v > 0);

        if (empty($validVolatilities)) {
            return 0.0;
        }

        switch ($config->regimeClassifier) {
            case ValidationConfig::REGIME_VOLATILITY_PERCENTILE:
                // Use percentile-based threshold
                $sorted = $validVolatilities;
                sort($sorted);
                $percentileIndex = (int) floor($config->regimeThreshold * count($sorted));

                return $sorted[$percentileIndex] ?? 0.0;

            case ValidationConfig::REGIME_VOLATILITY_THRESHOLD:
                // Use fixed threshold
                return $config->regimeThreshold;

            case ValidationConfig::REGIME_ATR_BASED:
                // Use ATR-based threshold (mean + threshold * std)
                $mean = array_sum($validVolatilities) / count($validVolatilities);
                $variance = 0.0;
                foreach ($validVolatilities as $v) {
                    $variance += ($v - $mean) ** 2;
                }
                $std = sqrt($variance / count($validVolatilities));

                return $mean + $config->regimeThreshold * $std;

            default:
                return array_sum($validVolatilities) / count($validVolatilities);
        }
    }

    /**
     * Classify a single volatility value into a regime.
     *
     * @param  float  $volatility  Volatility value
     * @param  float  $threshold  Threshold value
     * @param  ValidationConfig  $config  Configuration
     * @return string|null Regime name or null for neutral
     */
    private function classifyRegime(float $volatility, float $threshold, ValidationConfig $config): ?string
    {
        if ($volatility <= $threshold * 0.8) {
            return 'low';
        }

        if ($volatility >= $threshold * 1.2) {
            return 'high';
        }

        // Neutral zone - exclude from analysis
        return null;
    }

    /**
     * Build a probability surface for a specific regime.
     *
     * @param  array  $records  Regime records
     * @param  ValidationConfig  $config  Configuration
     * @param  string  $regimeName  Regime name
     */
    private function buildRegimeSurface(
        array $records,
        ValidationConfig $config,
        string $regimeName
    ): RegimeSurface {
        // Create a temporary config for this regime
        $analysisConfig = $config->toAnalysisConfig();

        // We need to pass the filtered records directly to the engine
        // Since the engine reads from file, we need a different approach
        // For now, we'll compute the surface manually using the engine's logic

        $surface = $this->computeSurfaceFromRecords($records, $analysisConfig);

        // Calculate average volatility for this regime
        $avgVolatility = 0.0;
        if (! empty($records)) {
            $sum = 0.0;
            foreach ($records as $record) {
                $tr = $this->calculateTrueRange($record);
                $sum += $tr / ($record['close'] > 0 ? $record['close'] : 1);
            }
            $avgVolatility = $sum / count($records);
        }

        return new RegimeSurface(
            regimeName: $regimeName,
            surface: $surface,
            observationCount: count($records),
            avgVolatility: $avgVolatility
        );
    }

    /**
     * Compute probability surface from records directly.
     *
     * @param  array  $records  Records
     * @param  OpenCrossAnalysisConfig  $config  Configuration
     */
    private function computeSurfaceFromRecords(
        array $records,
        OpenCrossAnalysisConfig $config
    ): OpenCrossProbabilityResult {
        // Partition into blocks
        $blocks = $this->partitionIntoBlocks($records, $config->blockMinutes);

        // Accumulate statistics
        $accumulator = new StatisticsAccumulator;

        foreach ($blocks as $blockData) {
            $this->processBlock($blockData, $config, $accumulator);
        }

        return OpenCrossProbabilityResult::fromAnalysis(
            $accumulator->getResults(),
            count($blocks),
            $accumulator->getTotalObservations(),
            $config,
            memory_get_usage(true)
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
     * Finalize a block.
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
     * Process a block.
     *
     * @param  array  $blockData  Block data
     * @param  OpenCrossAnalysisConfig  $config  Configuration
     * @param  StatisticsAccumulator  $accumulator  Accumulator
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
            if ($index >= $blockLength - 1) {
                continue;
            }

            $price = (float) $record['close'];
            $distance = $blockOpen > 0 ? ($price - $blockOpen) / $blockOpen : 0.0;

            $distanceBucket = $config->getBucketKey($distance);
            $minutesRemaining = $blockLength - 1 - $index;

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
     * Calculate true range for a record.
     *
     * @param  array  $record  OHLCV record
     */
    private function calculateTrueRange(array $record): float
    {
        $high = (float) $record['high'];
        $low = (float) $record['low'];
        $close = (float) $record['close'];

        return max(
            $high - $low,
            abs($high - $close),
            abs($low - $close)
        );
    }

    /**
     * Calculate calibration error for a regime surface.
     *
     * @param  RegimeSurface  $regimeSurface  Regime surface
     */
    private function calculateRegimeCalibration(RegimeSurface $regimeSurface): float
    {
        // Simple calibration metric: average deviation from 0.5
        // In a well-calibrated model, predictions should match outcomes
        $totalDeviation = 0.0;
        $count = 0;

        foreach ($regimeSurface->surface->probabilitySurface as $point) {
            if ($point->confidence === 'high') {
                // For now, use a simple metric
                $totalDeviation += abs($point->crossProbability - 0.5);
                $count++;
            }
        }

        return $count > 0 ? $totalDeviation / $count : 0.0;
    }

    /**
     * Calculate distance between two surfaces.
     *
     * @param  OpenCrossProbabilityResult  $surface1  First surface
     * @param  OpenCrossProbabilityResult  $surface2  Second surface
     */
    private function calculateSurfaceDistance(
        OpenCrossProbabilityResult $surface1,
        OpenCrossProbabilityResult $surface2
    ): float {
        $map1 = [];
        foreach ($surface1->probabilitySurface as $point) {
            $key = sprintf('%.6f_%d', $point->distanceBucket, $point->minutesRemaining);
            $map1[$key] = $point->crossProbability;
        }

        $map2 = [];
        foreach ($surface2->probabilitySurface as $point) {
            $key = sprintf('%.6f_%d', $point->distanceBucket, $point->minutesRemaining);
            $map2[$key] = $point->crossProbability;
        }

        // Find common keys
        $commonKeys = array_intersect_key($map1, $map2);

        if (empty($commonKeys)) {
            return 1.0;
        }

        $totalDiff = 0.0;
        foreach ($commonKeys as $key => $prob1) {
            $prob2 = $map2[$key];
            $totalDiff += abs($prob1 - $prob2);
        }

        return $totalDiff / count($commonKeys);
    }

    /**
     * Determine if regime differences are explainable.
     *
     * @param  float  $surfaceDistance  Surface distance
     * @param  array  $calibrations  Calibration by regime
     */
    private function determineExplainability(float $surfaceDistance, array $calibrations): bool
    {
        // Differences are explainable if:
        // 1. Surface distance is not too large (< 0.2)
        // 2. Both regimes have reasonable calibration

        if ($surfaceDistance > 0.2) {
            return false;
        }

        foreach ($calibrations as $calibration) {
            if ($calibration > 0.3) {
                return false;
            }
        }

        return true;
    }
}
