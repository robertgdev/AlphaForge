<?php

namespace App\AlphaForge\Backtesting\Optimization\Generator;

use App\AlphaForge\Backtesting\Optimization\ParameterSpace;

class RandomGenerator implements ParameterGeneratorInterface
{
    private ParameterSpace $space;

    private int $maxIterations;

    private int $completed = 0;

    public function initialize(ParameterSpace $space, ?int $iterations = null): void
    {
        $this->space = $space;
        $this->maxIterations = $iterations ?? 500;
        $this->completed = 0;
    }

    public function next(): ?array
    {
        if ($this->completed >= $this->maxIterations) {
            return null;
        }

        $this->completed++;

        $params = [];
        foreach ($this->space->dimensions as $name => $dimension) {
            $params[$name] = $dimension->randomValue();
        }

        return $params;
    }

    public function currentIteration(): int
    {
        return $this->completed;
    }

    public function totalIterations(): ?int
    {
        return $this->maxIterations;
    }

    public function inform(array $parameters, float $score): void {}
}
