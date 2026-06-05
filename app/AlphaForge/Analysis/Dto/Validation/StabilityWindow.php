<?php

namespace App\AlphaForge\Analysis\Dto\Validation;

/**
 * Represents a single rolling window stability result.
 */
final readonly class StabilityWindow
{
    public function __construct(
        public string $windowStart,
        public string $windowEnd,
        public float $meanDifference,
        public float $maxDifference,
        public float $correlation
    ) {}

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'window_start' => $this->windowStart,
            'window_end' => $this->windowEnd,
            'mean_difference' => round($this->meanDifference, 4),
            'max_difference' => round($this->maxDifference, 4),
            'correlation' => round($this->correlation, 4),
        ];
    }
}
