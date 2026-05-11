<?php

namespace App\AlphaForge\Backtesting\Optimization;

readonly class ScoredResult
{
    public function __construct(
        public array $parameters,
        public array $statistics,
        public float $score,
    ) {}

    public function toArray(): array
    {
        return [
            'parameters' => $this->parameters,
            'statistics' => $this->statistics,
            'score' => $this->score,
        ];
    }
}
