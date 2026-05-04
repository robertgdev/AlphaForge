<?php

namespace App\Analysis\Dto\Validation;

/**
 * Represents the complete rolling stability test report.
 */
final readonly class StabilityReport
{
    /**
     * @param  array<StabilityWindow>  $windows  Rolling window results
     * @param  float  $overallStabilityScore  Overall stability score (0-1)
     * @param  float  $meanCorrelation  Mean correlation across windows
     * @param  float  $maxDrift  Maximum drift observed
     * @param  bool  $isStable  Whether the surface is considered stable
     */
    public function __construct(
        public array $windows,
        public float $overallStabilityScore,
        public float $meanCorrelation,
        public float $maxDrift,
        public bool $isStable
    ) {}

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'overall_stability_score' => round($this->overallStabilityScore, 4),
            'mean_correlation' => round($this->meanCorrelation, 4),
            'max_drift' => round($this->maxDrift, 4),
            'is_stable' => $this->isStable,
            'windows' => array_map(fn (StabilityWindow $w) => $w->toArray(), $this->windows),
        ];
    }

    /**
     * Convert to CSV format.
     */
    public function toCsv(): string
    {
        $header = "window_start,window_end,mean_difference,max_difference,correlation\n";
        $rows = [];

        foreach ($this->windows as $window) {
            $rows[] = sprintf(
                '%s,%s,%.4f,%.4f,%.4f',
                $window->windowStart,
                $window->windowEnd,
                $window->meanDifference,
                $window->maxDifference,
                $window->correlation
            );
        }

        return $header.implode("\n", $rows);
    }
}
