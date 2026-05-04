<?php

namespace App\Analysis\Dto\Validation;

/**
 * Represents the cross-period surface comparison report.
 */
final readonly class CrossPeriodReport
{
    /**
     * @param  array  $periods  List of period identifiers
     * @param  array  $surfaceCorrelations  Correlation matrix between periods
     * @param  array  $meanAbsoluteDifferences  Mean absolute differences between periods
     * @param  bool  $monotonicityPreserved  Whether monotonicity is preserved across periods
     * @param  float  $overallPersistence  Overall structural persistence score
     * @param  array  $periodSurfaces  Summary of surfaces for each period
     */
    public function __construct(
        public array $periods,
        public array $surfaceCorrelations,
        public array $meanAbsoluteDifferences,
        public bool $monotonicityPreserved,
        public float $overallPersistence,
        public array $periodSurfaces
    ) {}

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'periods' => $this->periods,
            'surface_correlations' => $this->surfaceCorrelations,
            'mean_absolute_differences' => $this->meanAbsoluteDifferences,
            'monotonicity_preserved' => $this->monotonicityPreserved,
            'overall_persistence' => round($this->overallPersistence, 4),
            'period_surfaces' => $this->periodSurfaces,
        ];
    }
}
