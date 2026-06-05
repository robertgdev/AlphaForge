<?php

namespace App\AlphaForge\Analysis\Dto;

use App\AlphaForge\Analysis\Config\OpenCrossAnalysisConfig;

use function Safe\json_encode;

/**
 * Complete result of the Open-Cross Probability analysis.
 */
class OpenCrossProbabilityResult
{
    /**
     * @param  array<ProbabilitySurfacePoint>  $probabilitySurface  The probability surface points
     * @param  int  $totalBlocksAnalyzed  Number of blocks analyzed
     * @param  int  $totalObservations  Total observations across all buckets
     * @param  array  $metadata  Analysis metadata
     */
    public function __construct(
        public readonly array $probabilitySurface,
        public readonly int $totalBlocksAnalyzed,
        public readonly int $totalObservations,
        public readonly array $metadata
    ) {}

    /**
     * Create a result from accumulated data.
     *
     * @param  array  $surfaceData  The probability surface data
     * @param  int  $totalBlocks  Number of blocks analyzed
     * @param  int  $totalObservations  Total observations
     * @param  OpenCrossAnalysisConfig  $config  The analysis configuration
     * @param  int  $peakMemoryBytes  Peak memory usage in bytes
     */
    public static function fromAnalysis(
        array $surfaceData,
        int $totalBlocks,
        int $totalObservations,
        OpenCrossAnalysisConfig $config,
        int $peakMemoryBytes = 0
    ): self {
        $points = array_map(
            fn (array $data) => ProbabilitySurfacePoint::fromStats(
                $data['bucket'],
                $data['minutes_remaining'],
                $data['total'],
                $data['crosses'],
                $config->minimumSamples
            ),
            $surfaceData
        );

        // Sort by distance bucket, then by minutes remaining
        usort($points, function (ProbabilitySurfacePoint $a, ProbabilitySurfacePoint $b) {
            if ($a->distanceBucket !== $b->distanceBucket) {
                return $a->distanceBucket <=> $b->distanceBucket;
            }

            return $b->minutesRemaining <=> $a->minutesRemaining; // Descending
        });

        $metadata = [
            'exchange' => $config->exchange,
            'market' => $config->market,
            'timeframe' => $config->timeframe,
            'block_minutes' => $config->blockMinutes,
            'bucket_size' => $config->bucketSize,
            'volatility_normalized' => $config->volatilityNormalized,
            'merge_symmetric' => $config->mergeSymmetric,
            'analysis_timestamp' => date('c'),
            'peak_memory_bytes' => $peakMemoryBytes,
            'peak_memory_formatted' => self::formatMemory($peakMemoryBytes),
        ];

        return new self(
            probabilitySurface: $points,
            totalBlocksAnalyzed: $totalBlocks,
            totalObservations: $totalObservations,
            metadata: $metadata
        );
    }

    /**
     * Format memory size in human-readable format.
     *
     * @param  int  $bytes  Memory in bytes
     * @return string Formatted string (e.g., "256.5 MB" or "1.2 GB")
     */
    public static function formatMemory(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1).' MB';
        }

        return number_format($bytes / (1024 * 1024 * 1024), 2).' GB';
    }

    /**
     * Convert to JSON string.
     *
     * @param  int  $flags  JSON encoding flags
     */
    public function toJson(int $flags = JSON_PRETTY_PRINT): string
    {
        return json_encode($this->toArray(), $flags);
    }

    /**
     * Convert to CSV string.
     */
    public function toCsv(): string
    {
        $header = "distance_bucket,minutes_remaining,samples,cross_probability,confidence\n";
        $rows = array_map(
            fn (ProbabilitySurfacePoint $point) => $point->toCsvRow(),
            $this->probabilitySurface
        );

        return $header.implode("\n", $rows);
    }

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'metadata' => $this->metadata,
            'summary' => [
                'total_blocks_analyzed' => $this->totalBlocksAnalyzed,
                'total_observations' => $this->totalObservations,
                'surface_points' => count($this->probabilitySurface),
            ],
            'probability_surface' => array_map(
                fn (ProbabilitySurfacePoint $point) => $point->toArray(),
                $this->probabilitySurface
            ),
        ];
    }

    /**
     * Get the probability surface as a 2D matrix for heatmap rendering.
     *
     * Note: Uses string keys for distance buckets to avoid PHP's float key truncation.
     *
     * @return array 2D array [distance_bucket_string][minutes_remaining] => probability
     */
    public function getHeatmapMatrix(): array
    {
        $matrix = [];

        foreach ($this->probabilitySurface as $point) {
            // Convert to string to avoid PHP truncating float keys to integers
            $bucketKey = (string) $point->distanceBucket;
            $minutes = $point->minutesRemaining;

            if (! isset($matrix[$bucketKey])) {
                $matrix[$bucketKey] = [];
            }

            $matrix[$bucketKey][$minutes] = [
                'probability' => $point->crossProbability,
                'samples' => $point->samples,
                'confidence' => $point->confidence,
                'bucket' => $point->distanceBucket, // Store original float value
            ];
        }

        return $matrix;
    }

    /**
     * Get all unique distance buckets in the surface.
     */
    public function getDistanceBuckets(): array
    {
        $buckets = array_map(
            fn (ProbabilitySurfacePoint $point) => $point->distanceBucket,
            $this->probabilitySurface
        );

        return array_unique($buckets);
    }

    /**
     * Get all unique minutes remaining values in the surface.
     */
    public function getMinutesRemaining(): array
    {
        $minutes = array_map(
            fn (ProbabilitySurfacePoint $point) => $point->minutesRemaining,
            $this->probabilitySurface
        );

        return array_unique($minutes);
    }

    /**
     * Get a cross-section at a specific minutes remaining value.
     *
     * @param  int  $minutes  Minutes remaining
     * @return array<ProbabilitySurfacePoint>
     */
    public function getCrossSection(int $minutes): array
    {
        return array_filter(
            $this->probabilitySurface,
            fn (ProbabilitySurfacePoint $point) => $point->minutesRemaining === $minutes
        );
    }

    /**
     * Find the point with the highest cross probability.
     */
    public function getHighestProbability(): ?ProbabilitySurfacePoint
    {
        $highest = null;

        foreach ($this->probabilitySurface as $point) {
            if ($highest === null || $point->crossProbability > $highest->crossProbability) {
                $highest = $point;
            }
        }

        return $highest;
    }

    /**
     * Find the point with the lowest cross probability.
     */
    public function getLowestProbability(): ?ProbabilitySurfacePoint
    {
        $lowest = null;

        foreach ($this->probabilitySurface as $point) {
            if ($lowest === null || $point->crossProbability < $lowest->crossProbability) {
                $lowest = $point;
            }
        }

        return $lowest;
    }
}
