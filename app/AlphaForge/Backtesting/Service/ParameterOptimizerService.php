<?php

namespace App\AlphaForge\Backtesting\Service;

use App\AlphaForge\Backtesting\Dto\OptimizationResult;
use App\AlphaForge\Backtesting\Model\BacktestRun;
use App\AlphaForge\Backtesting\Model\OptimizationRun;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ParameterOptimizerService
{
    private const DEFAULT_METRIC = 'sharpe_ratio';

    public function __construct(
        private readonly Backtester $backtester,
        private readonly StrategyRegistryInterface $strategyRegistry,
    ) {}

    /**
     * Run an optimization.
     *
     * @param  string  $strategyAlias
     * @param  array  $symbols
     * @param  TimeframeEnum  $timeframe
     * @param  string  $exchange
     * @param  string  $initialCapital
     * @param  string  $stakeCurrency
     * @param  array  $parameterRanges  e.g. ['fastPeriod' => ['min' => 5, 'max' => 20, 'step' => 5]]
     * @param  string  $optimizationMetric
     * @param  array  $commissionConfig
     * @param  Carbon|null  $startDate
     * @param  Carbon|null  $endDate
     * @return OptimizationRun
     */
    public function optimize(
        string $strategyAlias,
        array $symbols,
        TimeframeEnum $timeframe,
        string $exchange,
        string $initialCapital,
        string $stakeCurrency,
        array $parameterRanges,
        string $optimizationMetric = self::DEFAULT_METRIC,
        array $commissionConfig = [],
        ?Carbon $startDate = null,
        ?Carbon $endDate = null
    ): OptimizationRun {
        // Create optimization run record
        $optimizationRun = OptimizationRun::create([
            'strategy_alias' => $strategyAlias,
            'symbols' => $symbols,
            'timeframe' => $timeframe->value,
            'exchange' => $exchange,
            'initial_capital' => $initialCapital,
            'stake_currency' => $stakeCurrency,
            'commission_config' => $commissionConfig,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'parameter_ranges' => $parameterRanges,
            'optimization_metric' => $optimizationMetric,
            'total_combinations' => 0,
            'completed_combinations' => 0,
            'status' => 'pending',
        ]);

        // Generate all parameter combinations
        $combinations = $this->generateCombinations($parameterRanges);
        $totalCombinations = count($combinations);
        
        $optimizationRun->update(['total_combinations' => $totalCombinations]);
        $optimizationRun->markAsRunning();

        Log::info("Starting optimization", [
            'optimization_id' => $optimizationRun->id,
            'total_combinations' => $totalCombinations,
        ]);

        $results = [];
        $bestMetric = null;
        $bestParams = null;
        $bestStats = null;
        $bestRunId = null;

        try {
            foreach ($combinations as $index => $params) {
                // Run backtest with these parameters
                $result = $this->runBacktestForParams(
                    $optimizationRun,
                    $strategyAlias,
                    $symbols,
                    $timeframe,
                    $exchange,
                    $initialCapital,
                    $stakeCurrency,
                    $params,
                    $commissionConfig,
                    $startDate,
                    $endDate
                );

                $metricValue = $result['statistics'][$optimizationMetric] ?? '0';

                // Track best result
                if ($bestMetric === null || $this->compareMetric($metricValue, $bestMetric, $optimizationMetric) > 0) {
                    $bestMetric = $metricValue;
                    $bestParams = $params;
                    $bestStats = $result['statistics'];
                    $bestRunId = $result['run_id'];
                }

                $results[] = new OptimizationResult(
                    parameters: $params,
                    statistics: $result['statistics'],
                    backtestRunId: $result['run_id'],
                );

                $optimizationRun->incrementProgress();

                Log::debug("Optimization progress", [
                    'optimization_id' => $optimizationRun->id,
                    'completed' => $optimizationRun->completed_combinations,
                    'total' => $totalCombinations,
                    'params' => $params,
                    'metric' => $metricValue,
                ]);
            }

            // Rank results
            usort($results, fn ($a, $b) => $this->compareMetric(
                $a->getMetricValue($optimizationMetric),
                $b->getMetricValue($optimizationMetric),
                $optimizationMetric
            ));

            foreach ($results as $rank => $result) {
                $result->rank = $rank + 1;
            }

            $optimizationRun->markAsCompleted($bestParams, $bestStats);

            Log::info("Optimization completed", [
                'optimization_id' => $optimizationRun->id,
                'best_params' => $bestParams,
                'best_metric' => $bestMetric,
            ]);

        } catch (\Throwable $e) {
            Log::error("Optimization failed", [
                'optimization_id' => $optimizationRun->id,
                'error' => $e->getMessage(),
            ]);
            $optimizationRun->markAsFailed($e->getMessage());
            throw $e;
        }

        return $optimizationRun;
    }

    /**
     * Run a backtest for a specific set of parameters.
     */
    private function runBacktestForParams(
        OptimizationRun $optimizationRun,
        string $strategyAlias,
        array $symbols,
        TimeframeEnum $timeframe,
        string $exchange,
        string $initialCapital,
        string $stakeCurrency,
        array $params,
        array $commissionConfig,
        ?Carbon $startDate,
        ?Carbon $endDate
    ): array {
        // Create a backtest run record
        $backtestRun = BacktestRun::create([
            'optimization_id' => $optimizationRun->id,
            'strategy_alias' => $strategyAlias,
            'symbols' => $symbols,
            'timeframe' => $timeframe->value,
            'exchange' => $exchange,
            'initial_capital' => $initialCapital,
            'stake_currency' => $stakeCurrency,
            'strategy_inputs' => $params,
            'commission_config' => $commissionConfig,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'running',
        ]);

        try {
            $result = $this->backtester->run(
                strategyAlias: $strategyAlias,
                symbols: $symbols,
                timeframe: $timeframe,
                exchange: $exchange,
                initialCapital: $initialCapital,
                stakeCurrency: $stakeCurrency,
                strategyInputs: $params,
                commissionConfig: $commissionConfig,
                additionalTimeframes: [],
                startDate: $startDate,
                endDate: $endDate
            );

            $backtestRun->markAsCompleted($result['final_capital'], $result['statistics']);

            return [
                'run_id' => $backtestRun->id,
                'statistics' => $result['statistics'],
            ];

        } catch (\Throwable $e) {
            $backtestRun->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate all parameter combinations from ranges.
     */
    public function generateCombinations(array $parameterRanges): array
    {
        $keys = array_keys($parameterRanges);
        $combinations = [[]];

        foreach ($parameterRanges as $param => $config) {
            $min = $config['min'];
            $max = $config['max'];
            $step = $config['step'] ?? 1;
            $type = $config['type'] ?? 'int';

            $values = [];
            for ($v = $min; $v <= $max; $v += $step) {
                $values[] = $type === 'float' ? (float) $v : (int) $v;
            }

            $combinations = $this->cartesianProduct($combinations, $values, $param);
        }

        return $combinations;
    }

    /**
     * Calculate Cartesian product of existing combinations with new values.
     */
    private function cartesianProduct(array $combinations, array $values, string $key): array
    {
        $result = [];

        foreach ($combinations as $combination) {
            foreach ($values as $value) {
                $newCombination = $combination;
                $newCombination[$key] = $value;
                $result[] = $newCombination;
            }
        }

        return $result;
    }

    /**
     * Compare two metric values. Returns positive if $a > $b.
     */
    private function compareMetric(string $a, string $b, string $metric): int
    {
        // For drawdown metrics, lower is better
        if (str_contains($metric, 'drawdown') || $metric === 'max_drawdown') {
            return bccomp($b, $a, 8);
        }

        // For all other metrics, higher is better
        return bccomp($a, $b, 8);
    }

    /**
     * Get ranked results for an optimization.
     */
    public function getRankedResults(OptimizationRun $optimizationRun): Collection
    {
        $metric = $optimizationRun->optimization_metric;

        return $optimizationRun->backtestRuns()
            ->where('status', 'completed')
            ->get()
            ->map(fn ($run) => new OptimizationResult(
                parameters: $run->strategy_inputs,
                statistics: $run->statistics ?? [],
                backtestRunId: $run->id,
            ))
            ->sortByDesc(fn ($r) => $this->compareMetric(
                $r->getMetricValue($metric),
                '0',
                $metric
            ))
            ->values()
            ->each(fn ($r, $i) => $r->rank = $i + 1);
    }

    /**
     * Extract parameter ranges from strategy's #[Input] attributes.
     */
    public function getParameterRangesFromStrategy(string $strategyAlias): array
    {
        $strategy = $this->strategyRegistry->get($strategyAlias);
        $reflection = new \ReflectionClass($strategy);

        $ranges = [];

        foreach ($reflection->getProperties() as $property) {
            $inputAttribute = null;
            foreach ($property->getAttributes() as $attribute) {
                if ($attribute->getName() === 'App\AlphaForge\Strategy\Attribute\Input') {
                    $inputAttribute = $attribute->newInstance();
                    break;
                }
            }

            if ($inputAttribute && $inputAttribute->min !== null && $inputAttribute->max !== null) {
                $propertyName = $property->getName();
                $ranges[$propertyName] = [
                    'min' => $inputAttribute->min,
                    'max' => $inputAttribute->max,
                    'step' => $inputAttribute->step ?? 1,
                    'type' => $property->getType()->getName() === 'float' ? 'float' : 'int',
                ];
            }
        }

        return $ranges;
    }
}
