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
use App\AlphaForge\Backtesting\Optimization\Runner\OptimizationRunnerInterface;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class Optimizer
{
    public function __construct(
        private readonly StrategyRegistryInterface $strategyRegistry,
        private readonly OptimizationRunnerInterface $runner,
    ) {}

    public function optimize(OptimizationConfig $config, ?callable $progressCallback = null): OptimizationRun
    {
        $space = $config->parameterOverrides
            ? ParameterSpace::fromArray($config->parameterOverrides)
            : ParameterSpace::fromStrategy($config->strategyAlias, $this->strategyRegistry);

        $generator = $this->createGenerator($config, $space);
        $objective = ObjectiveFactory::create($config->objective);

        $totalIterations = $generator->totalIterations() ?? 0;

        $optimizationRun = OptimizationRun::create([
            'strategy_alias' => $config->strategyAlias,
            'symbols' => $config->symbols,
            'timeframe' => $config->timeframe->value,
            'exchange' => $config->exchange,
            'initial_capital' => $config->initialCapital,
            'stake_currency' => $config->stakeCurrency,
            'commission_config' => $config->commissionConfig,
            'start_date' => $config->startDate ? Carbon::instance($config->startDate) : null,
            'end_date' => $config->endDate ? Carbon::instance($config->endDate) : null,
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
            'total_iterations' => $totalIterations,
            'objective' => $objective->label(),
        ]);

        $topResults = new TopNResults($config->topN, $objective);
        $completed = 0;

        try {
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

                $result = $this->runner->runSingle($backtestConfig);
                $score = $objective->score($result['statistics']);

                $generator->inform($params, $score);
                $topResults->consider($params, $result['statistics'], $score);
                $completed++;

                $optimizationRun->incrementProgress();

                if ($progressCallback !== null) {
                    $progressCallback(new OptimizationProgress(
                        completed: $completed,
                        total: $totalIterations,
                        parameters: $params,
                        statistics: $result['statistics'],
                        score: $score,
                    ));
                }

                if ($completed % 50 === 0) {
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
                    'start_date' => $config->startDate ? Carbon::instance($config->startDate) : null,
                    'end_date' => $config->endDate ? Carbon::instance($config->endDate) : null,
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

    private function createGenerator(OptimizationConfig $config, ParameterSpace $space): ParameterGeneratorInterface
    {
        return match ($config->method) {
            OptimizationMethod::GRID => tap(new GridGenerator, fn ($g) => $g->initialize($space)),
            OptimizationMethod::RANDOM => tap(new RandomGenerator, fn ($g) => $g->initialize($space, $config->iterations)),
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
