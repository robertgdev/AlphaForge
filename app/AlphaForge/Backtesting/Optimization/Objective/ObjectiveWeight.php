<?php

namespace App\AlphaForge\Backtesting\Optimization\Objective;

readonly class ObjectiveWeight
{
    public function __construct(
        public string $metric,
        public float $coefficient,
    ) {}
}
