<?php

namespace App\AlphaForge\Analysis\Dto\Validation;

/**
 * Represents the randomized baseline comparison report.
 */
final readonly class RandomizationReport
{
    /**
     * @param  float  $meanSurfaceDifference  Mean absolute difference from randomized surfaces
     * @param  float  $structuralDeviationScore  Score indicating structural deviation (0-1)
     * @param  float  $calibrationDegradation  Calibration error increase in randomized surfaces
     * @param  bool  $isSignificantlyDifferent  Whether original differs significantly from random
     * @param  int  $iterations  Number of randomization iterations performed
     * @param  array  $iterationResults  Results from each iteration
     */
    public function __construct(
        public float $meanSurfaceDifference,
        public float $structuralDeviationScore,
        public float $calibrationDegradation,
        public bool $isSignificantlyDifferent,
        public int $iterations,
        public array $iterationResults
    ) {}

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'mean_surface_difference' => round($this->meanSurfaceDifference, 4),
            'structural_deviation_score' => round($this->structuralDeviationScore, 4),
            'calibration_degradation' => round($this->calibrationDegradation, 4),
            'is_significantly_different' => $this->isSignificantlyDifferent,
            'iterations' => $this->iterations,
            'iteration_results' => $this->iterationResults,
        ];
    }
}
