<?php

namespace App\AlphaForge\Backtesting\MonteCarlo;

final readonly class MonteCarloMetric
{
    public function __construct(
        public string $label,
        public float $p5,
        public float $p25,
        public float $median,
        public float $p75,
        public float $p95,
        public float $probNegative,
    ) {}

    public function isSignificant(): bool
    {
        return $this->probNegative < 5.0 && $this->p5 > 0;
    }
}
