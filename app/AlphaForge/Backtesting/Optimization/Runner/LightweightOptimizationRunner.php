<?php

namespace App\AlphaForge\Backtesting\Optimization\Runner;

use App\AlphaForge\Backtesting\Dto\BacktestConfiguration;
use App\AlphaForge\Backtesting\Service\Backtester;
use Carbon\Carbon;

class LightweightOptimizationRunner implements OptimizationRunnerInterface
{
    public function __construct(
        private readonly Backtester $backtester,
    ) {}

    public function runSingle(BacktestConfiguration $config): array
    {
        return $this->backtester->run(
            strategyAlias: $config->strategyAlias,
            symbols: $config->symbols,
            timeframe: $config->timeframe,
            exchange: $config->dataSourceExchangeId,
            initialCapital: (string) $config->initialCapital,
            stakeCurrency: $config->stakeCurrency,
            strategyInputs: $config->strategyInputs,
            commissionConfig: $config->commissionConfig,
            additionalTimeframes: [],
            startDate: $config->startDate ? Carbon::instance($config->startDate) : null,
            endDate: $config->endDate ? Carbon::instance($config->endDate) : null,
            executionTimeframe: $config->executionTimeframe,
            dataType: $config->dataType,
            brickSize: $config->brickSize,
            atrPeriod: $config->atrPeriod,
        );
    }

    public function runBatch(array $configs): array
    {
        return array_map(fn (BacktestConfiguration $c) => $this->runSingle($c), $configs);
    }
}
