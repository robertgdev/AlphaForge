<?php

namespace App\AlphaForge\Analysis\Dto\Validation;

/**
 * Represents a single calibration bin result.
 */
final readonly class CalibrationBin
{
    public function __construct(
        public float $binStart,
        public float $binEnd,
        public int $samples,
        public float $avgPredictedProbability,
        public float $observedFrequency,
        public float $calibrationError
    ) {}

    /**
     * Get the bin label (e.g., "0.45-0.50").
     */
    public function getBinLabel(): string
    {
        return sprintf('%.2f-%.2f', $this->binStart, $this->binEnd);
    }

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'probability_bin' => $this->getBinLabel(),
            'samples' => $this->samples,
            'avg_predicted' => round($this->avgPredictedProbability, 4),
            'observed_frequency' => round($this->observedFrequency, 4),
            'calibration_error' => round($this->calibrationError, 4),
        ];
    }
}
