<?php

namespace App\AlphaForge\Backtesting\Optimization\Objective;

interface ObjectiveFunctionInterface
{
    public function score(array $statistics): float;

    public function label(): string;
}
