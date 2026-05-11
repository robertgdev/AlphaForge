<?php

namespace App\AlphaForge\Backtesting\Optimization\Generator;

use App\AlphaForge\Backtesting\Optimization\ParameterSpace;

class GridGenerator implements ParameterGeneratorInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $combinations = [];

    private int $index = 0;

    public function initialize(ParameterSpace $space): void
    {
        $this->combinations = $this->cartesian($space);
        $this->index = 0;
    }

    public function next(): ?array
    {
        return $this->combinations[$this->index++] ?? null;
    }

    public function currentIteration(): int
    {
        return $this->index;
    }

    public function totalIterations(): ?int
    {
        return count($this->combinations);
    }

    public function inform(array $parameters, float $score): void {}

    private function cartesian(ParameterSpace $space): array
    {
        $combinations = [[]];

        foreach ($space->dimensions as $name => $dimension) {
            $values = $dimension->values();
            $newCombinations = [];

            foreach ($combinations as $combination) {
                foreach ($values as $value) {
                    $newCombination = $combination;
                    $newCombination[$name] = $value;
                    $newCombinations[] = $newCombination;
                }
            }

            $combinations = $newCombinations;
        }

        return $combinations;
    }
}
