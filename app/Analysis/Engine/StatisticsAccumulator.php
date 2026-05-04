<?php

namespace App\Analysis\Engine;

/**
 * Efficient accumulator for cross probability statistics.
 *
 * Aggregates cross/total counts by (distance_bucket, minutes_remaining) pairs.
 */
final class StatisticsAccumulator
{
    /**
     * @var array<string, array{total: int, crosses: int}> Statistics storage
     */
    private array $stats = [];

    /**
     * @var int Total number of observations recorded
     */
    private int $totalObservations = 0;

    /**
     * @var int Total number of crosses recorded
     */
    private int $totalCrosses = 0;

    /**
     * Record an observation.
     *
     * @param  float  $distanceBucket  The distance bucket
     * @param  int  $minutesRemaining  Minutes remaining in the block
     * @param  bool  $crossed  Whether a cross occurred
     */
    public function record(float $distanceBucket, int $minutesRemaining, bool $crossed): void
    {
        $key = $this->makeKey($distanceBucket, $minutesRemaining);

        if (! isset($this->stats[$key])) {
            $this->stats[$key] = [
                'bucket' => $distanceBucket,
                'minutes_remaining' => $minutesRemaining,
                'total' => 0,
                'crosses' => 0,
            ];
        }

        $this->stats[$key]['total']++;
        $this->totalObservations++;

        if ($crossed) {
            $this->stats[$key]['crosses']++;
            $this->totalCrosses++;
        }
    }

    /**
     * Get all accumulated results.
     *
     * @return array Array of [bucket, minutes_remaining, total, crosses] records
     */
    public function getResults(): array
    {
        return array_values($this->stats);
    }

    /**
     * Get the heatmap data as a 2D matrix.
     *
     * @return array [distance_bucket][minutes_remaining] => ['total' => int, 'crosses' => int]
     */
    public function getHeatmapData(): array
    {
        $matrix = [];

        foreach ($this->stats as $data) {
            $bucket = $data['bucket'];
            $minutes = $data['minutes_remaining'];

            if (! isset($matrix[$bucket])) {
                $matrix[$bucket] = [];
            }

            $matrix[$bucket][$minutes] = [
                'total' => $data['total'],
                'crosses' => $data['crosses'],
            ];
        }

        return $matrix;
    }

    /**
     * Get overall statistics.
     */
    public function getStatistics(): array
    {
        return [
            'total_observations' => $this->totalObservations,
            'total_crosses' => $this->totalCrosses,
            'overall_cross_rate' => $this->totalObservations > 0
                ? $this->totalCrosses / $this->totalObservations
                : 0.0,
            'unique_buckets' => count($this->stats),
        ];
    }

    /**
     * Get the total number of observations.
     */
    public function getTotalObservations(): int
    {
        return $this->totalObservations;
    }

    /**
     * Get the total number of crosses.
     */
    public function getTotalCrosses(): int
    {
        return $this->totalCrosses;
    }

    /**
     * Get the number of unique bucket combinations.
     */
    public function getUniqueBucketCount(): int
    {
        return count($this->stats);
    }

    /**
     * Check if the accumulator has any data.
     */
    public function isEmpty(): bool
    {
        return empty($this->stats);
    }

    /**
     * Merge another accumulator into this one.
     *
     * @param  StatisticsAccumulator  $other  The other accumulator to merge
     */
    public function merge(StatisticsAccumulator $other): void
    {
        foreach ($other->stats as $key => $data) {
            if (! isset($this->stats[$key])) {
                $this->stats[$key] = $data;
            } else {
                $this->stats[$key]['total'] += $data['total'];
                $this->stats[$key]['crosses'] += $data['crosses'];
            }
        }

        $this->totalObservations += $other->totalObservations;
        $this->totalCrosses += $other->totalCrosses;
    }

    /**
     * Get statistics for a specific bucket/minutes combination.
     *
     * @param  float  $distanceBucket  The distance bucket
     * @param  int  $minutesRemaining  Minutes remaining
     * @return array|null ['total' => int, 'crosses' => int] or null if not found
     */
    public function get(float $distanceBucket, int $minutesRemaining): ?array
    {
        $key = $this->makeKey($distanceBucket, $minutesRemaining);

        return $this->stats[$key] ?? null;
    }

    /**
     * Create a unique key for a bucket/minutes combination.
     *
     * @param  float  $distanceBucket  The distance bucket
     * @param  int  $minutesRemaining  Minutes remaining
     */
    private function makeKey(float $distanceBucket, int $minutesRemaining): string
    {
        return sprintf('%.6f_%d', $distanceBucket, $minutesRemaining);
    }

    /**
     * Clear all accumulated data.
     */
    public function clear(): void
    {
        $this->stats = [];
        $this->totalObservations = 0;
        $this->totalCrosses = 0;
    }
}
