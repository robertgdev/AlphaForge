<?php

namespace App\AlphaForge\Backtesting\Optimization;

use App\AlphaForge\Backtesting\Dto\BacktestConfiguration;
use App\AlphaForge\Backtesting\Model\BacktestRun;
use App\AlphaForge\Backtesting\Model\OptimizationRun;
use App\AlphaForge\Backtesting\Optimization\Generator\GeneticGenerator;
use App\AlphaForge\Backtesting\Optimization\Generator\GridGenerator;
use App\AlphaForge\Backtesting\Optimization\Generator\ParameterGeneratorInterface;
use App\AlphaForge\Backtesting\Optimization\Generator\RandomGenerator;
use App\AlphaForge\Backtesting\Optimization\Objective\ObjectiveFactory;
use App\AlphaForge\Backtesting\Optimization\Objective\ObjectiveFunctionInterface;
use App\AlphaForge\Backtesting\Optimization\Runner\OptimizationRunnerInterface;
use App\AlphaForge\Jobs\AggregateTopResultsJob;
use App\AlphaForge\Jobs\OptimizeParameterJob;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;
use Carbon\Carbon;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Safe\shell_exec;

class Optimizer
{
    public function __construct(
        private readonly StrategyRegistryInterface $strategyRegistry,
        private readonly OptimizationRunnerInterface $runner,
        private readonly MarketDataLoader $marketDataLoader,
    ) {}

    public function optimize(OptimizationConfig $config, ?callable $progressCallback = null): OptimizationRun
    {
        $space = $config->parameterOverrides
            ? ParameterSpace::fromArray($config->parameterOverrides)
            : ParameterSpace::fromStrategy($config->strategyAlias, $this->strategyRegistry);

        $generator = $this->createGenerator($config, $space);
        $objective = ObjectiveFactory::create($config->objective);

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

        Log::info('Starting optimization', [
            'optimization_id' => $optimizationRun->id,
            'method' => $config->method->value,
            'runner' => $config->runnerMode->value,
            'total_iterations' => $totalIterations,
            'objective' => $objective->label(),
        ]);

        $topResults = new TopNResults($config->topN, $objective);

        try {
            $data = $this->marketDataLoader->load(
                symbols: $config->symbols,
                timeframe: $config->timeframe,
                exchange: $config->exchange,
                startDate: $startDate,
                endDate: $endDate,
                executionTimeframe: $config->executionTimeframe,
                dataType: $config->dataType ?? 'ohlcv',
                brickSize: $config->brickSize,
                atrPeriod: $config->atrPeriod,
            );

            if ($config->runnerMode === ParallelRunnerMode::QUEUE) {
                $this->runGenerationQueue($config, $data, $objective, $generator, $optimizationRun);

                return $optimizationRun;
            }

            $completed = 0;

            while (true) {
                $generationConfigs = $this->collectGeneration($config, $generator);

                if (empty($generationConfigs)) {
                    break;
                }

                $onResult = function (array $result) use (
                    $objective,
                    $generator,
                    $topResults,
                    &$completed,
                    $totalIterations,
                    $optimizationRun,
                    $progressCallback,
                ): void {
                    $params = $result['params'];
                    $statistics = $result['statistics'];
                    $error = $result['error'] ?? null;
                    $finalCapitalRaw = (string) ($result['final_capital'] ?? '0');
                    $numTrades = (int) ($statistics['total_trades'] ?? 0);
                    $score = $error !== null ? 0.0 : $objective->score($statistics);

                    if ($error === null && $numTrades > 0) {
                        $generator->inform($params, $score);
                        $topResults->consider($params, $statistics, $score);
                    }

                    $completed++;

                    $optimizationRun->incrementProgress();

                    if ($progressCallback !== null) {
                        $progressCallback(new OptimizationProgress(
                            completed: $completed,
                            total: $totalIterations,
                            parameters: $params,
                            statistics: $statistics,
                            score: $score,
                            error: $error,
                            finalCapitalRaw: $finalCapitalRaw,
                        ));
                    }
                };

                if ($config->runnerMode === ParallelRunnerMode::FORK) {
                    $this->runGenerationFork($generationConfigs, $data, $onResult);
                } else {
                    $results = $this->runGenerationSync($generationConfigs, $data);
                    foreach ($results as $result) {
                        $onResult($result);
                    }
                }

                if ($completed % 100 === 0 || $completed === $totalIterations) {
                    Log::debug('Optimization progress', [
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

            Log::info('Optimization completed', [
                'optimization_id' => $optimizationRun->id,
                'total_completed' => $completed,
                'best_score' => ! empty($ranked) ? $ranked[0]->score : null,
            ]);

        } catch (\Throwable $e) {
            Log::error('Optimization failed', [
                'optimization_id' => $optimizationRun->id,
                'error' => $e->getMessage(),
            ]);
            $optimizationRun->markAsFailed($e->getMessage());
            throw $e;
        }

        return $optimizationRun;
    }

    public function getParameterRangesFromStrategy(string $strategyAlias): array
    {
        $space = ParameterSpace::fromStrategy($strategyAlias, $this->strategyRegistry);

        return $space->toArray();
    }

    /**
     * @return BacktestConfiguration[]
     */
    private function collectGeneration(OptimizationConfig $config, ParameterGeneratorInterface $generator): array
    {
        $generationConfigs = [];

        while ($params = $generator->next()) {
            $generationConfigs[] = new BacktestConfiguration(
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

        return $generationConfigs;
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
     * @param  callable(array): void|null  $onResult
     */
    private function runGenerationFork(array $configs, MarketDataSnapshot $data, ?callable $onResult = null): void
    {
        $workerCount = $this->resolveWorkerCount(count($configs));
        $forkRunner = new ForkParallelRunner($workerCount, storage_path('tmp'));

        $forkRunner->run(
            $configs,
            $data,
            fn (BacktestConfiguration $c, MarketDataSnapshot $d) => $this->runner->runSingle($c, $d),
            $onResult,
        );
    }

    private function resolveWorkerCount(int $totalConfigs): int
    {
        $cpuCores = $this->detectCpuCores();

        return min(max(1, $cpuCores), $totalConfigs);
    }

    private function detectCpuCores(): int
    {
        $cores = $this->rawCpuCores();
        $ratio = (float) config('alphaforge.optimization.cpu_ratio', 0.8);

        return max(1, (int) round($cores * $ratio));
    }

    private function rawCpuCores(): int
    {
        if (function_exists('swoole_cpu_num')) {
            $count = swoole_cpu_num();

            return $count > 0 ? $count : 4;
        }

        $nproc = (int) trim(shell_exec('nproc 2>/dev/null') ?: '');
        if ($nproc > 0) {
            return $nproc;
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $sysctl = (int) trim(shell_exec('sysctl -n hw.ncpu 2>/dev/null') ?: '');
            if ($sysctl > 0) {
                return $sysctl;
            }
        }

        return 4;
    }

    /**
     * Dispatch all parameter combinations as queue jobs.
     */
    private function runGenerationQueue(
        OptimizationConfig $config,
        MarketDataSnapshot $data,
        ObjectiveFunctionInterface $objective,
        ParameterGeneratorInterface $generator,
        OptimizationRun $optimizationRun,
    ): void {
        $snapshotPath = storage_path('tmp/optimization_'.$optimizationRun->id.'.snapshot');
        $data->saveToFile($snapshotPath);

        $jobs = [];

        while ($params = $generator->next()) {
            $backtestConfig = new BacktestConfiguration(
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

            $jobs[] = new OptimizeParameterJob(
                $optimizationRun->id,
                $snapshotPath,
                $backtestConfig->toArray(),
            );
        }

        if (empty($jobs)) {
            Log::warning('Queue optimization: no parameter combinations generated', ['optimization_id' => $optimizationRun->id]);

            return;
        }

        Bus::batch($jobs)
            ->name('Optimization '.$optimizationRun->id)
            ->then(function (Batch $batch) use ($optimizationRun, $objective, $config, $snapshotPath) {
                AggregateTopResultsJob::dispatch(
                    $optimizationRun->id,
                    $objective->label(),
                    $config->topN,
                    $snapshotPath,
                );
            })
            ->catch(function (Batch $batch, Throwable $e) use ($optimizationRun) {
                Log::error('Queue optimization batch failed', [
                    'optimization_id' => $optimizationRun->id,
                    'error' => $e->getMessage(),
                ]);
                $optimizationRun->markAsFailed('Batch error: '.$e->getMessage());
            })
            ->dispatch();

        Log::info('Queue optimization dispatched', [
            'optimization_id' => $optimizationRun->id,
            'total_jobs' => count($jobs),
        ]);
    }

    private function createGenerator(OptimizationConfig $config, ParameterSpace $space): ParameterGeneratorInterface
    {
        return match ($config->method) {
            OptimizationMethod::GRID => tap(new GridGenerator, fn ($g) => $g->initialize($space)),
            OptimizationMethod::RANDOM => tap(new RandomGenerator, fn ($g) => $g->initialize($space, $config->iterations, (int) config('alphaforge.optimization.random_max_retries', 10))),
            OptimizationMethod::GENETIC => tap(new GeneticGenerator, fn ($g) => $g->initialize(
                $space,
                $config->populationSize,
                $config->generations,
                $config->mutationRate,
                $config->crossoverRate,
            )),
        };
    }
}
