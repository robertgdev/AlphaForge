<?php

namespace App\AlphaForge\Backtesting\Optimization\Objective;

class SingleMetricObjective implements ObjectiveFunctionInterface
{
    private const LOWER_IS_BETTER = ['max_drawdown', 'max_drawdown_percent'];

    public function __construct(
        private readonly string $metric,
    ) {}

    public function score(array $statistics): float
    {
        $value = (float) ($statistics[$this->metric] ?? 0);

        if (in_array($this->metric, self::LOWER_IS_BETTER, true) || str_contains($this->metric, 'drawdown')) {
            return -$value;
        }

        return $value;
    }

    public function label(): string
    {
        return $this->metric;
    }
}
