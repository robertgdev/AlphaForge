<?php

namespace App\AlphaForge\Backtesting\Optimization\Generator;

use App\AlphaForge\Backtesting\Optimization\ParameterSpace;

interface ParameterGeneratorInterface
{
    public function initialize(ParameterSpace $space): void;

    public function next(): ?array;

    public function currentIteration(): int;

    public function totalIterations(): ?int;

    public function inform(array $parameters, float $score): void;

    /**
     * Serialize the generator's internal state for checkpointing.
     *
     * @return array<string, mixed>
     */
    public function getState(): array;

    /**
     * Restore the generator's state from a previously saved checkpoint.
     *
     * @param  array<string, mixed>  $state
     */
    public function restoreState(array $state): void;
}
