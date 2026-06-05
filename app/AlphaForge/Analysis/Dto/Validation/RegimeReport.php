<?php

namespace App\AlphaForge\Analysis\Dto\Validation;

/**
 * Represents the complete regime sensitivity analysis report.
 */
final readonly class RegimeReport
{
    /**
     * @param  array<RegimeSurface>  $regimeSurfaces  Surfaces for each regime
     * @param  float  $surfaceDistance  Distance between regime surfaces
     * @param  float  $crossRegimeStability  Stability score across regimes
     * @param  array  $calibrationByRegime  Calibration errors per regime
     * @param  bool  $isExplainable  Whether regime differences are explainable
     */
    public function __construct(
        public array $regimeSurfaces,
        public float $surfaceDistance,
        public float $crossRegimeStability,
        public array $calibrationByRegime,
        public bool $isExplainable
    ) {}

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        $regimes = [];
        foreach ($this->regimeSurfaces as $regimeSurface) {
            $regimes[$regimeSurface->regimeName] = $regimeSurface->toArray();
        }

        return [
            'surface_distance' => round($this->surfaceDistance, 4),
            'cross_regime_stability' => round($this->crossRegimeStability, 4),
            'is_explainable' => $this->isExplainable,
            'calibration_by_regime' => $this->calibrationByRegime,
            'regimes' => $regimes,
        ];
    }
}
