<?php

namespace App\AlphaForge\Backtesting\Optimization\Runner;

use App\AlphaForge\Backtesting\Dto\BacktestConfiguration;

interface OptimizationRunnerInterface
{
    public function runSingle(BacktestConfiguration $config): array;

    public function runBatch(array $configs): array;
}
