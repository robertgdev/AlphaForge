<?php

namespace App\AlphaForge\Backtesting\Optimization;

readonly class OptimizationProgress
{
    public function __construct(
        public int $completed,
        public int $total,
        public array $parameters,
        public array $statistics,
        public float $score,
    ) {}
}
