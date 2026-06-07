<?php

namespace App\AlphaForge\Backtesting\Optimization\Runner;

use App\AlphaForge\Backtesting\Dto\BacktestConfiguration;
use App\AlphaForge\Backtesting\Optimization\MarketDataSnapshot;
use App\AlphaForge\Backtesting\Service\Backtester;

class LightweightOptimizationRunner implements OptimizationRunnerInterface
{
    public function __construct(
        private readonly Backtester $backtester,
    ) {}

    public function runSingle(BacktestConfiguration $config, MarketDataSnapshot $data): array
    {
        return $this->backtester->runWithPreloadedData(
            strategyAlias: $config->strategyAlias,
            symbols: $config->symbols,
            timeframe: $config->timeframe,
            initialCapital: (string) $config->initialCapital,
            stakeCurrency: $config->stakeCurrency,
            strategyInputs: $config->strategyInputs,
            commissionConfig: $config->commissionConfig,
            additionalTimeframes: [],
            data: $data,
            executionTimeframe: $config->executionTimeframe,
        );
    }

    public function runBatch(array $configs, MarketDataSnapshot $data): array
    {
        return array_map(fn (BacktestConfiguration $c) => $this->runSingle($c, $data), $configs);
    }
}
