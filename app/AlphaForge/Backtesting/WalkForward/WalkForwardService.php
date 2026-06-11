<?php

namespace App\AlphaForge\Backtesting\WalkForward;

use App\AlphaForge\Backtesting\Dto\WalkForwardConfiguration;
use App\AlphaForge\Backtesting\Model\OptimizationRun;
use App\AlphaForge\Backtesting\Model\WalkForwardResult;
use App\AlphaForge\Backtesting\Model\WalkForwardRun;
use App\AlphaForge\Backtesting\Optimization\Objective\ObjectiveFactory;
use App\AlphaForge\Backtesting\Optimization\Objective\ObjectiveFunctionInterface;
use App\AlphaForge\Backtesting\Optimization\OptimizationConfig;
use App\AlphaForge\Backtesting\Optimization\Optimizer;
use App\AlphaForge\Backtesting\Service\Backtester;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Safe\DateTimeImmutable;

class WalkForwardService
{
    public function __construct(
        private readonly Optimizer $optimizer,
        private readonly Backtester $backtester,
    ) {}

    public function run(WalkForwardConfiguration $config, ?callable $progressCallback = null): WalkForwardRun
    {
        $objective = ObjectiveFactory::create($config->objective);

        [$isStart, $isEnd, $oosStart, $oosEnd] = $this->computeDateSplit($config);

        $wfRun = WalkForwardRun::create([
            'strategy_alias' => $config->strategyAlias,
            'symbols' => $config->symbols,
            'timeframe' => $config->timeframe->value,
            'exchange' => $config->exchange,
            'initial_capital' => $config->initialCapital,
            'stake_currency' => $config->stakeCurrency,
            'commission_config' => $config->commissionConfig,
            'is_start_date' => $isStart,
            'is_end_date' => $isEnd,
            'oos_start_date' => $oosStart,
            'oos_end_date' => $oosEnd,
            'split_ratio' => $config->splitRatio,
            'optimization_method' => $config->method->value,
            'optimization_objective' => $objective->label(),
            'top_n' => $config->topN,
            'parameter_ranges' => $config->parameterOverrides,
            'status' => 'pending',
            'execution_timeframe' => $config->executionTimeframe?->value,
            'min_trades_threshold' => $config->minTrades,
            'data_type' => $config->dataType ?? 'ohlcv',
            'brick_size' => $config->brickSize,
            'atr_period' => $config->atrPeriod,
        ]);

        try {
            $optimizationRun = $this->runOptimizationPhase($config, $wfRun, $isStart, $isEnd);

            $wfRun->optimization_run_id = $optimizationRun->id;
            $wfRun->total_combinations = $optimizationRun->total_combinations;
            $wfRun->save();

            $topResults = $this->runForwardTestPhase($config, $wfRun, $optimizationRun, $objective, $oosStart, $oosEnd, $progressCallback);

            $this->finalizeWithBestResult($wfRun, $topResults, $objective);

        } catch (\Throwable $e) {
            Log::error('Walk-forward run failed', [
                'walk_forward_run_id' => $wfRun->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $wfRun->markAsFailed($e->getMessage());
            throw $e;
        }

        return $wfRun;
    }

    /**
     * Compute the in-sample and out-of-sample date split.
     *
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon} [isStart, isEnd, oosStart, oosEnd]
     */
    public function computeDateSplit(WalkForwardConfiguration $config): array
    {
        $fullStart = $config->startDate
            ? Carbon::instance($config->startDate)
            : $this->detectDataStart($config);

        $fullEnd = $config->endDate
            ? Carbon::instance($config->endDate)
            : $this->detectDataEnd($config);

        if ($config->oosStartDate !== null) {
            $oosStart = Carbon::parse($config->oosStartDate);
            $isStart = $fullStart;
            $isEnd = $oosStart->copy()->subDay()->endOfDay();
        } else {
            $totalDays = $fullStart->diffInDays($fullEnd);
            $isDays = (int) round($totalDays * $config->splitRatio);

            $isStart = $fullStart;
            $isEnd = $fullStart->copy()->addDays($isDays)->endOfDay();
            $oosStart = $isEnd->copy()->addDay()->startOfDay();
        }

        if ($oosStart->gte($fullEnd)) {
            throw new \InvalidArgumentException(
                "Out-of-sample start date ({$oosStart->toDateString()}) must be before end date ({$fullEnd->toDateString()}). "
                .'Increase the date range or reduce the split ratio.'
            );
        }

        return [$isStart, $isEnd, $oosStart, $fullEnd];
    }

    private function runOptimizationPhase(
        WalkForwardConfiguration $config,
        WalkForwardRun $wfRun,
        Carbon $isStart,
        Carbon $isEnd,
    ): OptimizationRun {
        $wfRun->markAsOptimizing();

        Log::info('Walk-forward: starting optimization phase', [
            'walk_forward_run_id' => $wfRun->id,
            'is_start' => $isStart->toDateString(),
            'is_end' => $isEnd->toDateString(),
        ]);

        $optimizationConfig = new OptimizationConfig;
        $optimizationConfig->strategyAlias = $config->strategyAlias;
        $optimizationConfig->symbols = $config->symbols;
        $optimizationConfig->timeframe = $config->timeframe;
        $optimizationConfig->exchange = $config->exchange;
        $optimizationConfig->initialCapital = $config->initialCapital;
        $optimizationConfig->stakeCurrency = $config->stakeCurrency;
        $optimizationConfig->commissionConfig = $config->commissionConfig;
        $optimizationConfig->method = $config->method;
        $optimizationConfig->iterations = $config->iterations;
        $optimizationConfig->populationSize = $config->populationSize;
        $optimizationConfig->generations = $config->generations;
        $optimizationConfig->mutationRate = $config->mutationRate;
        $optimizationConfig->crossoverRate = $config->crossoverRate;
        $optimizationConfig->objective = $config->objective;
        $optimizationConfig->topN = $config->topN;
        $optimizationConfig->parameterOverrides = $config->parameterOverrides;
        $optimizationConfig->startDate = new DateTimeImmutable($isStart->toIso8601String());
        $optimizationConfig->endDate = new DateTimeImmutable($isEnd->toIso8601String());
        $optimizationConfig->executionTimeframe = $config->executionTimeframe;
        $optimizationConfig->dataType = $config->dataType ?? 'ohlcv';
        $optimizationConfig->brickSize = $config->brickSize;
        $optimizationConfig->atrPeriod = $config->atrPeriod;
        $optimizationConfig->runnerMode = $config->runnerMode;
        $optimizationConfig->workerCount = $config->workerCount;

        return $this->optimizer->optimize($optimizationConfig);
    }

    /**
     * @return WalkForwardResult[]
     */
    private function runForwardTestPhase(
        WalkForwardConfiguration $config,
        WalkForwardRun $wfRun,
        OptimizationRun $optimizationRun,
        ObjectiveFunctionInterface $objective,
        Carbon $oosStart,
        Carbon $oosEnd,
        ?callable $progressCallback = null,
    ): array {
        $wfRun->markAsForwardTesting();

        Log::info('Walk-forward: starting forward-test phase', [
            'walk_forward_run_id' => $wfRun->id,
            'oos_start' => $oosStart->toDateString(),
            'oos_end' => $oosEnd->toDateString(),
        ]);

        $topBacktestRuns = $optimizationRun->backtestRuns()
            ->orderByDesc('statistics->optimization_score')
            ->limit($config->topN)
            ->get();

        $totalTests = $topBacktestRuns->count();
        $completed = 0;
        $results = [];
        $seenParams = [];
        $rank = 0;

        foreach ($topBacktestRuns as $backtestRun) {
            $paramKey = json_encode($backtestRun->strategy_inputs);
            if (isset($seenParams[$paramKey])) {
                continue;
            }
            $seenParams[$paramKey] = true;
            $rank++;

            $isScore = (float) ($backtestRun->statistics['optimization_score'] ?? 0);

            $oosResult = $this->backtester->run(
                strategyAlias: $config->strategyAlias,
                symbols: $config->symbols,
                timeframe: $config->timeframe,
                exchange: $config->exchange,
                initialCapital: (string) $config->initialCapital,
                stakeCurrency: $config->stakeCurrency,
                strategyInputs: $backtestRun->strategy_inputs,
                commissionConfig: $config->commissionConfig,
                additionalTimeframes: [],
                startDate: $oosStart,
                endDate: $oosEnd,
                executionTimeframe: $config->executionTimeframe,
                dataType: $config->dataType ?? 'ohlcv',
                brickSize: $config->brickSize,
                atrPeriod: $config->atrPeriod,
            );

            $oosScore = $objective->score($oosResult['statistics']);
            $scoreDegradation = $this->computeDegradation($isScore, $oosScore);

$wfResult = WalkForwardResult::create([
                    'walk_forward_run_id' => $wfRun->id,
                    'rank' => $rank,
                'parameters' => $backtestRun->strategy_inputs,
                'is_final_capital' => $backtestRun->final_capital,
                'is_statistics' => $backtestRun->statistics,
                'is_score' => $isScore,
                'oos_final_capital' => $oosResult['final_capital'],
                'oos_statistics' => $oosResult['statistics'],
                'oos_score' => $oosScore,
                'score_degradation' => $scoreDegradation,
            ]);

            $results[] = $wfResult;

            $completed++;
            if ($progressCallback !== null) {
                $progressCallback($completed, $totalTests);
            }
        }

        return $results;
    }

    private function computeDegradation(float $isScore, float $oosScore): float
    {
        if ($isScore == 0.0) {
            return 0.0;
        }

        return (($isScore - $oosScore) / abs($isScore)) * 100;
    }

    /**
     * @param  WalkForwardResult[]  $topResults
     */
    private function finalizeWithBestResult(
        WalkForwardRun $wfRun,
        array $topResults,
        ObjectiveFunctionInterface $objective,
    ): void {
        if (empty($topResults)) {
            $wfRun->markAsFailed('No results generated from forward testing');

            return;
        }

        $bestByOos = null;
        $bestOosScore = -INF;

        foreach ($topResults as $result) {
            if ($result->oos_score > $bestOosScore) {
                $bestOosScore = $result->oos_score;
                $bestByOos = $result;
            }
        }

        $wfRun->markAsCompleted(
            $bestByOos->parameters,
            $bestByOos->is_statistics ?? [],
            $bestByOos->oos_statistics ?? []
        );

        Log::info('Walk-forward run completed', [
            'walk_forward_run_id' => $wfRun->id,
            'best_oos_score' => $bestOosScore,
            'best_is_score' => $bestByOos->is_score,
        ]);
    }

    private function detectDataStart(WalkForwardConfiguration $config): Carbon
    {
        return Carbon::now()->subYears(2);
    }

    private function detectDataEnd(WalkForwardConfiguration $config): Carbon
    {
        return Carbon::now();
    }
}
