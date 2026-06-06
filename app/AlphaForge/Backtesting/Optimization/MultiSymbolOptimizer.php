<?php

namespace App\AlphaForge\Backtesting\Optimization;

use App\AlphaForge\Backtesting\Dto\BacktestConfiguration;
use App\AlphaForge\Backtesting\Model\BacktestRun;
use App\AlphaForge\Backtesting\Model\OptimizationRun;
use App\AlphaForge\Backtesting\Optimization\Generator\GeneticGenerator;
use App\AlphaForge\Backtesting\Optimization\Generator\GridGenerator;
use App\AlphaForge\Backtesting\Optimization\Generator\ParameterGeneratorInterface;
use App\AlphaForge\Backtesting\Optimization\Generator\RandomGenerator;
use App\AlphaForge\Backtesting\Optimization\Objective\PortfolioObjective;
use App\AlphaForge\Backtesting\Optimization\Runner\OptimizationRunnerInterface;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MultiSymbolOptimizer
{
    public function __construct(
        private readonly StrategyRegistryInterface $strategyRegistry,
        private readonly OptimizationRunnerInterface $runner,
        private readonly MarketDataLoader $marketDataLoader,
    ) {}

    public function optimize(OptimizationConfig $config, ?callable $progressCallback = null): OptimizationRun
    {
        $symbols = $config->symbols;
        if (count($symbols) < 2) {
            // Fallback: single-symbol optimization through the regular optimizer
            throw new \InvalidArgumentException(
                'Multi-symbol optimization requires at least 2 symbols. Got: '.implode(', ', $symbols)
            );
        }

        $space = $config->parameterOverrides
            ? ParameterSpace::fromArray($config->parameterOverrides)
            : ParameterSpace::fromStrategy($config->strategyAlias, $this->strategyRegistry);

        $generator = $this->createGenerator($config, $space);
        $objective = new PortfolioObjective;

        $totalIterations = $generator->totalIterations() ?? 0;
        $startDate = $config->startDate ? Carbon::instance($config->startDate) : null;
        $endDate = $config->endDate ? Carbon::instance($config->endDate) : null;

        $optimizationRun = OptimizationRun::create([
            'strategy_alias' => $config->strategyAlias,
            'symbols' => $config->symbols,
            'timeframe' => $config->timeframe->value,
            'exchange' => $config->exchange,
            'initial_capital' => $config->initialCapital,
            'stake_currency' => $config->stakeCurrency,
            'commission_config' => $config->commissionConfig,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'parameter_ranges' => $space->toArray(),
            'optimization_method' => $config->method->value,
            'optimization_objective' => $objective->label(),
            'optimization_metric' => $objective->label(),
            'total_combinations' => $totalIterations,
            'completed_combinations' => 0,
            'status' => 'pending',
            'top_n' => $config->topN,
        ]);

        $optimizationRun->markAsRunning();

        Log::info('Starting multi-symbol optimization', [
            'optimization_id' => $optimizationRun->id,
            'method' => $config->method->value,
            'symbols' => $symbols,
            'total_iterations' => $totalIterations,
            'objective' => $objective->label(),
        ]);

        $topResults = new TopNResults($config->topN, $objective);

        try {
            $data = $this->marketDataLoader->load(
                symbols: $symbols,
                timeframe: $config->timeframe,
                exchange: $config->exchange,
                startDate: $startDate,
                endDate: $endDate,
                executionTimeframe: $config->executionTimeframe,
                dataType: $config->dataType ?? 'ohlcv',
                brickSize: $config->brickSize,
                atrPeriod: $config->atrPeriod,
            );

            $completed = 0;

            while (true) {
                $generationConfigs = $this->collectGeneration($config, $generator);

                if (empty($generationConfigs)) {
                    break;
                }

                // Expand: one config per symbol per parameter set
                $allConfigs = [];
                foreach ($generationConfigs as $baseConfig) {
                    foreach ($symbols as $symbol) {
                        $allConfigs[] = new BacktestConfiguration(
                            strategyAlias: $baseConfig->strategyAlias,
                            symbols: [$symbol],
                            timeframe: $baseConfig->timeframe,
                            dataSourceExchangeId: $baseConfig->dataSourceExchangeId,
                            initialCapital: $baseConfig->initialCapital,
                            stakeCurrency: $baseConfig->stakeCurrency,
                            strategyInputs: $baseConfig->strategyInputs,
                            commissionConfig: $baseConfig->commissionConfig,
                            startDate: $baseConfig->startDate,
                            endDate: $baseConfig->endDate,
                            executionTimeframe: $baseConfig->executionTimeframe,
                            dataType: $baseConfig->dataType ?? 'ohlcv',
                            brickSize: $baseConfig->brickSize,
                            atrPeriod: $baseConfig->atrPeriod,
                        );
                    }
                }

                $onResult = function (array $result) use ($symbols): void {
                    $params = $result['params'];
                    $statistics = $result['statistics'];
                    $error = $result['error'] ?? null;
                    $numTrades = (int) ($statistics['total_trades'] ?? 0);

                    if ($error !== null || $numTrades === 0) {
                        return;
                    }

                    $this->accumulate($params, $result, $symbols);
                };

                // Run all symbol-expanded configs
                $results = $this->runGenerationSync($allConfigs, $data);
                foreach ($results as $r) {
                    $onResult($r);
                }

                // After all symbol results collected, score parameter sets
                foreach ($this->pendingSets as $paramsKey => $set) {
                    if ($set['completed'] === count($symbols)) {
                        $symbolStats = $set['symbolStats'];
                        // Add config for min trade filtering
                        $symbolStats['_config'] = ['min_trades_per_symbol' => 1];
                        $score = $objective->score($symbolStats);

                        $generator->inform($set['params'], $score);
                        $topResults->consider($set['params'], $this->mergeStatistics($symbolStats), $score);

                        $completed++;

                        $optimizationRun->incrementProgress();

                        if ($progressCallback !== null) {
                            $progressCallback(new OptimizationProgress(
                                completed: $completed,
                                total: $totalIterations,
                                parameters: $set['params'],
                                statistics: $this->mergeStatistics($symbolStats),
                                score: $score,
                                error: null,
                                finalCapitalRaw: '0',
                            ));
                        }

                        unset($this->pendingSets[$paramsKey]);
                    }
                }

                if ($completed % 10 === 0 || $completed === $totalIterations) {
                    Log::debug('Portfolio optimization progress', [
                        'optimization_id' => $optimizationRun->id,
                        'completed' => $completed,
                        'total' => $totalIterations,
                    ]);
                }
            }

            $ranked = $topResults->ranked();

            foreach ($ranked as $rank => $r) {
                BacktestRun::create([
                    'optimization_id' => $optimizationRun->id,
                    'strategy_alias' => $config->strategyAlias,
                    'symbols' => $config->symbols,
                    'timeframe' => $config->timeframe->value,
                    'exchange' => $config->exchange,
                    'initial_capital' => $config->initialCapital,
                    'stake_currency' => $config->stakeCurrency,
                    'strategy_inputs' => $r->parameters,
                    'commission_config' => $config->commissionConfig,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => 'completed',
                    'final_capital' => $r->statistics['final_capital'] ?? $config->initialCapital,
                    'statistics' => array_merge($r->statistics, ['optimization_score' => (string) $r->score]),
                ]);
            }

            if (! empty($ranked)) {
                $best = $ranked[0];
                $optimizationRun->markAsCompleted($best->parameters, $best->statistics);
            } else {
                $optimizationRun->markAsFailed('No results generated');
            }

            Log::info('Multi-symbol optimization completed', [
                'optimization_id' => $optimizationRun->id,
                'total_completed' => $completed,
                'best_score' => ! empty($ranked) ? $ranked[0]->score : null,
            ]);

        } catch (\Throwable $e) {
            Log::error('Multi-symbol optimization failed', [
                'optimization_id' => $optimizationRun->id,
                'error' => $e->getMessage(),
            ]);
            $optimizationRun->markAsFailed($e->getMessage());
            throw $e;
        }

        return $optimizationRun;
    }

    /** @var array<string, array{params: array<string, mixed>, completed: int, symbolStats: array<string, array<string, mixed>>}> */
    private array $pendingSets = [];

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $result
     * @param  array<string>  $symbols
     */
    private function accumulate(array $params, array $result, array $symbols): void
    {
        $symbol = $result['symbols'][0] ?? null;
        if ($symbol === null) {
            return;
        }

        $paramsKey = md5(serialize($params));

        if (! isset($this->pendingSets[$paramsKey])) {
            $this->pendingSets[$paramsKey] = [
                'params' => $params,
                'completed' => 0,
                'symbolStats' => [],
            ];
        }

        $this->pendingSets[$paramsKey]['symbolStats'][$symbol] = $result['statistics'] ?? [];
        $this->pendingSets[$paramsKey]['completed']++;
    }

    /**
     * Merge per-symbol statistics into a single combined stats array.
     *
     * @param  array<string, array<string, mixed>>  $symbolStats
     * @return array<string, mixed>
     */
    private function mergeStatistics(array $symbolStats): array
    {
        unset($symbolStats['_config']);

        $totalTrades = 0;
        $totalReturn = 0.0;
        $totalDrawdown = 0.0;
        $totalSharpe = 0.0;
        $count = 0;

        foreach ($symbolStats as $symbol => $stats) {
            $totalTrades += (int) ($stats['total_trades'] ?? 0);
            $totalReturn += (float) ($stats['total_return_percent'] ?? 0);
            $totalDrawdown += abs((float) ($stats['max_drawdown_percent'] ?? 0));
            $totalSharpe += (float) ($stats['sharpe_ratio'] ?? 0);
            $count++;
        }

        if ($count === 0) {
            return ['total_trades' => 0];
        }

        return [
            'total_trades' => $totalTrades,
            'total_return_percent' => round($totalReturn / $count, 2),
            'max_drawdown_percent' => round(-$totalDrawdown / $count, 2),
            'sharpe_ratio' => round($totalSharpe / $count, 4),
            'symbols_count' => $count,
            'per_symbol' => $symbolStats,
        ];
    }

    private function createGenerator(OptimizationConfig $config, ParameterSpace $space): ParameterGeneratorInterface
    {
        return match ($config->method) {
            OptimizationMethod::GRID => tap(new GridGenerator, fn ($g) => $g->initialize($space)),
            OptimizationMethod::RANDOM => tap(new RandomGenerator, fn ($g) => $g->initialize($space, $config->iterations, (int) config('alphaforge.optimization.random_max_retries', 10))),
            OptimizationMethod::GENETIC => tap(new GeneticGenerator, fn ($g) => $g->initialize(
                $space,
                $config->populationSize ?? 50,
                $config->generations ?? 20,
                $config->mutationRate ?? 0.1,
                $config->crossoverRate ?? 0.7,
            )),
        };
    }

    /**
     * @param  BacktestConfiguration[]  $configs
     * @return array<int, array{params: array, statistics: array, final_capital: string}>
     */
    private function runGenerationSync(array $configs, MarketDataSnapshot $data): array
    {
        return array_map(
            fn (BacktestConfiguration $c) => $this->runner->runSingle($c, $data),
            $configs
        );
    }

    /**
     * @param  BacktestConfiguration[]  $configs
     */
    private function collectGeneration(OptimizationConfig $config, ParameterGeneratorInterface $generator): array
    {
        $configs = [];

        while ($params = $generator->next()) {
            $configs[] = new BacktestConfiguration(
                strategyAlias: $config->strategyAlias,
                symbols: $config->symbols,
                timeframe: $config->timeframe,
                dataSourceExchangeId: $config->exchange,
                initialCapital: $config->initialCapital,
                stakeCurrency: $config->stakeCurrency,
                strategyInputs: $params,
                commissionConfig: $config->commissionConfig,
                startDate: $config->startDate,
                endDate: $config->endDate,
                executionTimeframe: $config->executionTimeframe,
                dataType: $config->dataType ?? 'ohlcv',
                brickSize: $config->brickSize,
                atrPeriod: $config->atrPeriod,
            );
        }

        return $configs;
    }
}
