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

    /**
     * Whether the metric is statistically significant.
     *
     * A metric is considered significant when the probability of observing a
     * negative value is below 5% AND the worst-case (P5) outcome is positive.
     *
     * Significant  → P(negative) < 5%  and  P5 > 0
     * Marginal     → P(negative) 5–20%
     * Unreliable   → P(negative) > 20%
     */
    public function isSignificant(): bool
    {
        return $this->probNegative < 5.0 && $this->p5 > 0;
    }
}
