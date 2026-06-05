<?php

namespace App\AlphaForge\Analysis\Dto\Validation;

use App\AlphaForge\Analysis\Dto\OpenCrossProbabilityResult;

/**
 * Represents a probability surface for a specific regime.
 */
final readonly class RegimeSurface
{
    public function __construct(
        public string $regimeName,
        public OpenCrossProbabilityResult $surface,
        public int $observationCount,
        public float $avgVolatility
    ) {}

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'regime_name' => $this->regimeName,
            'observation_count' => $this->observationCount,
            'avg_volatility' => round($this->avgVolatility, 6),
            'surface_summary' => [
                'total_blocks' => $this->surface->totalBlocksAnalyzed,
                'total_observations' => $this->surface->totalObservations,
                'surface_points' => count($this->surface->probabilitySurface),
            ],
        ];
    }
}
