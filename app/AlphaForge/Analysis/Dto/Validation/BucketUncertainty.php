<?php

namespace App\AlphaForge\Analysis\Dto\Validation;

/**
 * Represents uncertainty estimation for a single bucket.
 */
final readonly class BucketUncertainty
{
    public function __construct(
        public float $distanceBucket,
        public int $minutesRemaining,
        public float $probability,
        public int $samples,
        public float $standardError,
        public array $confidenceInterval95,
        public bool $isReliable
    ) {}

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'distance_bucket' => $this->distanceBucket,
            'minutes_remaining' => $this->minutesRemaining,
            'probability' => round($this->probability, 4),
            'samples' => $this->samples,
            'standard_error' => round($this->standardError, 4),
            'confidence_interval_95' => [
                round($this->confidenceInterval95[0], 4),
                round($this->confidenceInterval95[1], 4),
            ],
            'is_reliable' => $this->isReliable,
        ];
    }

    /**
     * Convert to CSV row.
     */
    public function toCsvRow(): string
    {
        return sprintf(
            '%s,%d,%.4f,%d,%.4f,%.4f,%.4f,%s',
            $this->distanceBucket,
            $this->minutesRemaining,
            $this->probability,
            $this->samples,
            $this->standardError,
            $this->confidenceInterval95[0],
            $this->confidenceInterval95[1],
            $this->isReliable ? 'yes' : 'no'
        );
    }
}
