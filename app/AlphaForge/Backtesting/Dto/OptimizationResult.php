<?php

namespace App\AlphaForge\Backtesting\Dto;

readonly class OptimizationResult
{
    public function __construct(
        public array $parameters,
        public array $statistics,
        public int $rank = 0,
        public ?string $backtestRunId = null,
    ) {}

    public function getMetricValue(string $metric): string
    {
        return $this->statistics[$metric] ?? '0';
    }

    public function toArray(): array
    {
        return [
            'parameters' => $this->parameters,
            'statistics' => $this->statistics,
            'rank' => $this->rank,
            'backtest_run_id' => $this->backtestRunId,
        ];
    }
}
