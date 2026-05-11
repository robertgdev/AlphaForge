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
}
