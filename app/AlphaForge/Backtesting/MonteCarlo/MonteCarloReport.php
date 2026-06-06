<?php

namespace App\AlphaForge\Backtesting\MonteCarlo;

final readonly class MonteCarloReport
{
    /**
     * @param  array<string, MonteCarloMetric>  $metrics
     */
    public function __construct(
        public int $totalTrades,
        public int $iterations,
        public array $metrics,
    ) {}

    public function hasTrades(): bool
    {
        return $this->totalTrades > 0;
    }
}
