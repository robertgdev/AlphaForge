<?php

namespace App\AlphaForge\Backtesting\Optimization\Generator;

use App\AlphaForge\Backtesting\Optimization\ParameterSpace;

class GeneticGenerator implements ParameterGeneratorInterface
{
    private ParameterSpace $space;

    private int $populationSize;

    private int $maxGenerations;

    private float $mutationRate;

    private float $crossoverRate;

    private int $eliteCount;

    /** @var array<int, array{params: array<string, mixed>, score: float}> */
    private array $currentGeneration = [];

    private int $generationIndex = 0;

    private int $totalYielded = 0;

    private int $generation = 0;

    public function initialize(
        ParameterSpace $space,
        ?int $populationSize = null,
        ?int $maxGenerations = null,
        ?float $mutationRate = null,
        ?float $crossoverRate = null,
    ): void {
        $this->space = $space;
        $this->populationSize = $populationSize ?? 50;
        $this->maxGenerations = $maxGenerations ?? 20;
        $this->mutationRate = $mutationRate ?? 0.15;
        $this->crossoverRate = $crossoverRate ?? 0.7;
        $this->eliteCount = max(2, (int) ($this->populationSize * 0.1));
        $this->currentGeneration = [];
        $this->generationIndex = 0;
        $this->totalYielded = 0;
        $this->generation = 0;

        $this->currentGeneration = $this->randomPopulation();
    }

    public function next(): ?array
    {
        if ($this->generation >= $this->maxGenerations) {
            return null;
        }

        if ($this->generationIndex >= count($this->currentGeneration)) {
            return null;
        }

        $individual = $this->currentGeneration[$this->generationIndex];
        $this->generationIndex++;
        $this->totalYielded++;

        return $individual['params'];
    }

    public function currentIteration(): int
    {
        return $this->totalYielded;
    }

    public function totalIterations(): ?int
    {
        return $this->populationSize * $this->maxGenerations;
    }

    public function inform(array $parameters, float $score): void
    {
        if ($this->generationIndex > 0 && $this->generationIndex <= count($this->currentGeneration)) {
            $this->currentGeneration[$this->generationIndex - 1]['score'] = $score;
        }

        if ($this->generationIndex >= count($this->currentGeneration)) {
            $this->evolve();
        }
    }

    private function randomPopulation(): array
    {
        $population = [];
        for ($i = 0; $i < $this->populationSize; $i++) {
            $params = [];
            foreach ($this->space->dimensions as $name => $dimension) {
                $params[$name] = $dimension->randomValue();
            }
            $population[] = ['params' => $params, 'score' => 0.0];
        }

        return $population;
    }

    private function evolve(): void
    {
        usort($this->currentGeneration, fn ($a, $b) => $b['score'] <=> $a['score']);

        $nextGeneration = [];

        for ($i = 0; $i < $this->eliteCount && $i < count($this->currentGeneration); $i++) {
            $nextGeneration[] = [
                'params' => $this->currentGeneration[$i]['params'],
                'score' => 0.0,
            ];
        }

        while (count($nextGeneration) < $this->populationSize) {
            $parentA = $this->tournamentSelect();
            $parentB = $this->tournamentSelect();

            if (mt_rand() / mt_getrandmax() < $this->crossoverRate) {
                $child = $this->crossover($parentA, $parentB);
            } else {
                $child = $parentA['params'];
            }

            $child = $this->mutate($child);

            $nextGeneration[] = ['params' => $child, 'score' => 0.0];
        }

        $this->currentGeneration = $nextGeneration;
        $this->generationIndex = 0;
        $this->generation++;
    }

    private function tournamentSelect(int $tournamentSize = 3): array
    {
        $best = null;
        for ($i = 0; $i < $tournamentSize; $i++) {
            $candidate = $this->currentGeneration[array_rand($this->currentGeneration)];
            if ($best === null || $candidate['score'] > $best['score']) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function crossover(array $parentA, array $parentB): array
    {
        $child = [];
        foreach ($this->space->dimensions as $name => $dimension) {
            $child[$name] = mt_rand(0, 1) === 0
                ? $parentA['params'][$name]
                : $parentB['params'][$name];
        }

        return $child;
    }

    private function mutate(array $params): array
    {
        foreach ($this->space->dimensions as $name => $dimension) {
            if (mt_rand() / mt_getrandmax() < $this->mutationRate) {
                $step = $dimension->step;
                $perturbation = (mt_rand() / mt_getrandmax() - 0.5) * 2 * $step * 2;
                $params[$name] = $dimension->clamp($params[$name] + $perturbation);

                $validValues = $dimension->values();
                $closest = null;
                $closestDist = PHP_FLOAT_MAX;
                foreach ($validValues as $v) {
                    $dist = abs($v - $params[$name]);
                    if ($dist < $closestDist) {
                        $closestDist = $dist;
                        $closest = $v;
                    }
                }
                $params[$name] = $closest;
            }
        }

        return $params;
    }

    public function getState(): array
    {
        return [
            'generation' => $this->generation,
            'generation_index' => $this->generationIndex,
            'total_yielded' => $this->totalYielded,
            'max_generations' => $this->maxGenerations,
            'population_size' => $this->populationSize,
            'current_generation' => $this->currentGeneration,
        ];
    }

    public function restoreState(array $state): void
    {
        $this->generation = (int) ($state['generation'] ?? 0);
        $this->generationIndex = (int) ($state['generation_index'] ?? 0);
        $this->totalYielded = (int) ($state['total_yielded'] ?? 0);
        $this->currentGeneration = $state['current_generation'] ?? [];
    }
}
