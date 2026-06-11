<?php

namespace App\AlphaForge\Jobs;

use App\AlphaForge\Backtesting\Dto\BacktestConfiguration;
use App\AlphaForge\Backtesting\Model\BacktestRun;
use App\AlphaForge\Backtesting\Optimization\MarketDataSnapshot;
use App\AlphaForge\Backtesting\Service\Backtester;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use Carbon\Carbon;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OptimizeParameterJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    /**
     * @param  array  $config  Serialized BacktestConfiguration data
     */
    public function __construct(
        public string $optimizationId,
        public string $snapshotPath,
        public array $config,
    ) {
        $this->onQueue(config('alphaforge.queues.backtests', 'backtests'));
    }

    public function handle(Backtester $backtester): void
    {
        $data = MarketDataSnapshot::fromSerializedFile($this->snapshotPath);

        $config = $this->hydrateConfig();

        $backtestRun = BacktestRun::create([
            'optimization_id' => $this->optimizationId,
            'strategy_alias' => $config->strategyAlias,
            'symbols' => $config->symbols,
            'timeframe' => $config->timeframe->value,
            'execution_timeframe' => $config->executionTimeframe?->value,
            'exchange' => $config->dataSourceExchangeId,
            'initial_capital' => (string) $config->initialCapital,
            'stake_currency' => $config->stakeCurrency,
            'strategy_inputs' => $config->strategyInputs,
            'commission_config' => $config->commissionConfig,
            'start_date' => $config->startDate ? Carbon::instance($config->startDate) : null,
            'end_date' => $config->endDate ? Carbon::instance($config->endDate) : null,
            'data_type' => $config->dataType,
            'brick_size' => $config->brickSize,
            'atr_period' => $config->atrPeriod,
            'status' => 'running',
        ]);

        try {
            $result = $backtester->runWithPreloadedData(
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

            $backtestRun->markAsCompleted(
                (float) ($result['final_capital'] ?? $config->initialCapital),
                $result['statistics'] ?? [],
            );
        } catch (\Throwable $e) {
            $backtestRun->markAsFailed($e->getMessage());
        }
    }

    private function hydrateConfig(): BacktestConfiguration
    {
        $c = $this->config;
        $timeframe = $c['timeframe'] instanceof TimeframeEnum
            ? $c['timeframe']
            : TimeframeEnum::from($c['timeframe']);

        $executionTimeframe = null;
        if (isset($c['executionTimeframe']) && $c['executionTimeframe'] !== null) {
            $executionTimeframe = $c['executionTimeframe'] instanceof TimeframeEnum
                ? $c['executionTimeframe']
                : TimeframeEnum::from($c['executionTimeframe']);
        }

        $startDate = null;
        if (isset($c['startDate']) && $c['startDate'] !== null) {
            $startDate = $c['startDate'] instanceof \DateTimeImmutable
                ? $c['startDate']
                : new \Safe\DateTimeImmutable($c['startDate']);
        }

        $endDate = null;
        if (isset($c['endDate']) && $c['endDate'] !== null) {
            $endDate = $c['endDate'] instanceof \DateTimeImmutable
                ? $c['endDate']
                : new \Safe\DateTimeImmutable($c['endDate']);
        }

        return new BacktestConfiguration(
            strategyAlias: $c['strategyAlias'],
            symbols: $c['symbols'],
            timeframe: $timeframe,
            dataSourceExchangeId: $c['dataSourceExchangeId'] ?? $c['data_source_exchange_id'] ?? '',
            initialCapital: $c['initialCapital'] ?? '10000',
            stakeCurrency: $c['stakeCurrency'] ?? 'USDT',
            strategyInputs: $c['strategyInputs'] ?? [],
            commissionConfig: $c['commissionConfig'] ?? [],
            startDate: $startDate,
            endDate: $endDate,
            executionTimeframe: $executionTimeframe,
            dataType: $c['dataType'] ?? 'ohlcv',
            brickSize: $c['brickSize'] ?? null,
            atrPeriod: $c['atrPeriod'] ?? null,
        );
    }
}
