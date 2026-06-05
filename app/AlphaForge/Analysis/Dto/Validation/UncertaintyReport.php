<?php

namespace App\AlphaForge\Analysis\Dto\Validation;

/**
 * Represents the complete uncertainty estimation report.
 */
final readonly class UncertaintyReport
{
    /**
     * @param  array<BucketUncertainty>  $buckets  Uncertainty data for each bucket
     * @param  int  $flaggedBuckets  Number of buckets below minimum samples
     * @param  int  $totalBuckets  Total number of buckets
     * @param  float  $avgStandardError  Average standard error across buckets
     */
    public function __construct(
        public array $buckets,
        public int $flaggedBuckets,
        public int $totalBuckets,
        public float $avgStandardError
    ) {}

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'flagged_buckets' => $this->flaggedBuckets,
            'total_buckets' => $this->totalBuckets,
            'avg_standard_error' => round($this->avgStandardError, 4),
            'reliable_ratio' => $this->totalBuckets > 0
                ? round(($this->totalBuckets - $this->flaggedBuckets) / $this->totalBuckets, 4)
                : 0,
            'buckets' => array_map(fn (BucketUncertainty $b) => $b->toArray(), $this->buckets),
        ];
    }

    /**
     * Convert to CSV format.
     */
    public function toCsv(): string
    {
        $header = "distance_bucket,minutes_remaining,probability,samples,standard_error,ci_lower,ci_upper,is_reliable\n";
        $rows = [];

        foreach ($this->buckets as $bucket) {
            $rows[] = $bucket->toCsvRow();
        }

        return $header.implode("\n", $rows);
    }
}
