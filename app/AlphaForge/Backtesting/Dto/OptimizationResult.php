<?php

namespace App\AlphaForge\Backtesting\Dto;

readonly class OptimizationResult
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $statistics
     */
    public function __construct(
        public array $parameters,
        public array $statistics,
        public int $rank = 0,
        public ?string $backtestRunId = null,
    ) {}

    public function getMetricValue(string $metric): string
    {
        $value = $this->statistics[$metric] ?? '0';

        return (string) $value;
    }

    public function withRank(int $rank): self
    {
        return new self(
            parameters: $this->parameters,
            statistics: $this->statistics,
            rank: $rank,
            backtestRunId: $this->backtestRunId,
        );
    }

    /**
     * @return array<string, mixed>
     */
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
