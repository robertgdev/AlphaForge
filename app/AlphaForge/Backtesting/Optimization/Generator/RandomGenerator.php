<?php

namespace App\AlphaForge\Backtesting\Optimization\Generator;

use App\AlphaForge\Backtesting\Optimization\ParameterSpace;

class RandomGenerator implements ParameterGeneratorInterface
{
    private ParameterSpace $space;

    private int $maxIterations;

    private int $completed = 0;

    private int $maxRetries = 10;

    /** @var array<string, true> */
    private array $seen = [];

    public function initialize(ParameterSpace $space, ?int $iterations = null, int $maxRetries = 10): void
    {
        $this->space = $space;
        $this->maxIterations = $iterations ?? 500;
        $this->maxRetries = $maxRetries;
        $this->completed = 0;
        $this->seen = [];
    }

    public function next(): ?array
    {
        if ($this->completed >= $this->maxIterations) {
            return null;
        }

        $retries = 0;
        do {
            $params = [];
            foreach ($this->space->dimensions as $name => $dimension) {
                $params[$name] = $dimension->randomValue();
            }
            $key = $this->hash($params);
            $retries++;
        } while (isset($this->seen[$key]) && $retries < $this->maxRetries);

        if (isset($this->seen[$key])) {
            return null;
        }

        $this->completed++;
        $this->seen[$key] = true;

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

    private function hash(array $params): string
    {
        ksort($params);

        return json_encode($params, JSON_THROW_ON_ERROR);
    }
}
