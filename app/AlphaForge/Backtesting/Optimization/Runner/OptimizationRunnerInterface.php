<?php

namespace App\AlphaForge\Backtesting\Optimization\Runner;

use App\AlphaForge\Backtesting\Dto\BacktestConfiguration;
use App\AlphaForge\Backtesting\Optimization\MarketDataSnapshot;

interface OptimizationRunnerInterface
{
    public function runSingle(BacktestConfiguration $config, MarketDataSnapshot $data): array;

    public function runBatch(array $configs, MarketDataSnapshot $data): array;
}
