<?php

namespace App\AlphaForge\Backtesting\Optimization;

use App\AlphaForge\Backtesting\Optimization\Objective\ObjectiveFunctionInterface;

class TopNResults
{
    /** @var ScoredResult[] */
    private array $results = [];

    public function __construct(
        private readonly int $n,
        private readonly ObjectiveFunctionInterface $objective,
    ) {}

    public function consider(array $params, array $statistics, float $score): void
    {
        $this->results[] = new ScoredResult($params, $statistics, $score);

        usort($this->results, fn (ScoredResult $a, ScoredResult $b) => $b->score <=> $a->score);

        if (count($this->results) > $this->n * 2) {
            $this->results = array_slice($this->results, 0, $this->n);
        }
    }

    public function ranked(): array
    {
        usort($this->results, fn (ScoredResult $a, ScoredResult $b) => $b->score <=> $a->score);

        return array_slice($this->results, 0, $this->n);
    }

    public function count(): int
    {
        return count($this->results);
    }
}
