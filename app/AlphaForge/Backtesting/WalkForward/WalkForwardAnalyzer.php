<?php

namespace App\AlphaForge\Backtesting\WalkForward;

use App\AlphaForge\Backtesting\Model\WalkForwardResult;
use App\AlphaForge\Backtesting\Model\WalkForwardRun;
use App\AlphaForge\Backtesting\Optimization\MarketDataLoader;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use RobertGDev\AlphaforgeStatistics\WalkForward\WalkForwardAnalyzer as PackageAnalyzer;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class WalkForwardAnalyzer
{
    private const LOW_TRADE_CNT_THRESHOLD = 30;
    private const MEANINGFUL_RETURN_PCT = 10.0;

    public function __construct(
        private readonly ?MarketDataLoader $marketDataLoader = null,
    ) {}

    public function analyze(WalkForwardRun $wfRun, int $minTrades = 0): WalkForwardAnalysis
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, WalkForwardResult> $results */
        $results = $wfRun->results()->orderBy('rank')->get();

        if ($results->isEmpty()) {
            return new WalkForwardAnalysis(
                walkForwardRun: $wfRun,
                results: [],
                oosIsRatio: 0.0,
                robustCount: 0,
                robustRatio: 0.0,
                beatBuyHoldCount: 0,
                beatBuyHoldRatio: 0.0,
                returnGt10Count: 0,
                returnGt10Ratio: 0.0,
                sharpeBeatBenchmarkCount: 0,
                sharpeBeatBenchmarkRatio: 0.0,
                medianIsScore: 0.0,
                medianOosScore: 0.0,
                avgDegradation: 0.0,
                medianDegradation: 0.0,
                bestOosRank: null,
                bestOosResult: null,
                stabilityClassification: 'likely_overfit',
                stabilityInterpretation: 'No results available for analysis.',
                rankCorrelation: null,
                rankStabilityLabel: 'unstable',
                reliableCount: 0,
                reliableRatio: 0.0,
                minTrades: $minTrades,
                boundaryWarnings: [],
                lowTradeWarning: false,
                benchmarkReturn: 0.0,
                benchmarkMaxDrawdown: 0.0,
                benchmarkSharpe: 0.0,
                benchmarkHasData: false,
                timeInMarket: 0.0,
                exposureAdjustedTarget: 0.0,
                captureRatio: 0.0,
                marketCapture: 0.0,
                capitalEfficiency: 0.0,
            );
        }

        $profitableOos = $results->filter(fn (WalkForwardResult $r) => ($r->oos_score ?? 0) > 0);

        /** @var list<float> $isScores */
        $isScores = $results->map(fn (WalkForwardResult $r) => (float) ($r->is_score ?? 0.0))->values()->all();
        /** @var list<float> $oosScores */
        $oosScores = $results->map(fn (WalkForwardResult $r) => (float) ($r->oos_score ?? 0.0))->values()->all();
        /** @var list<float> $degradations */
        $degradations = $results->map(fn (WalkForwardResult $r) => (float) ($r->score_degradation ?? 0.0))->values()->all();

        $medianIsScore = PackageAnalyzer::median($isScores);
        $medianOosScore = PackageAnalyzer::median($oosScores);

        $avgIsScore = array_sum($isScores) / count($isScores);
        $avgOosScore = array_sum($oosScores) / count($oosScores);

        $oosIsRatio = $avgIsScore != 0.0
            ? ($avgOosScore / $avgIsScore) * 100
            : 0.0;

        $avgDegradation = array_sum($degradations) / count($degradations);
        $medianDegradation = PackageAnalyzer::median($degradations);

        $oosReturns = $results->map(fn (WalkForwardResult $r) => (float) ($r->oos_statistics['total_return_percent'] ?? 0))->values()->all();
        $oosSharpes = $results->map(fn (WalkForwardResult $r) => (float) ($r->oos_statistics['sharpe_ratio'] ?? 0))->values()->all();
        $oosMaxDds = $results->map(fn (WalkForwardResult $r) => (float) ($r->oos_statistics['max_drawdown_percent'] ?? 0))->values()->all();

        $medianOosReturn = PackageAnalyzer::median($oosReturns);
        $medianOosSharpe = PackageAnalyzer::median($oosSharpes);
        $medianOosMaxDd = PackageAnalyzer::median($oosMaxDds);

        /** @var WalkForwardResult|null $bestOosResult */
        $bestOosResult = $results->first(fn (WalkForwardResult $r) => $r->rank === (
            $results->sortByDesc('oos_score')->first()?->rank
        ));

        $robustRatioPct = $results->count() > 0 ? $profitableOos->count() / $results->count() * 100 : 0.0;

        $rankCorrelation = PackageAnalyzer::spearmanRankCorrelation($isScores, $oosScores);
        $rankStabilityLabel = PackageAnalyzer::classifyRankStability($rankCorrelation);

        $stabilityClassification = PackageAnalyzer::classifyRobustness($oosIsRatio, $robustRatioPct, $rankCorrelation);
        $stabilityInterpretation = PackageAnalyzer::interpretClassification($stabilityClassification);

        $reliableCount = 0;
        if ($minTrades > 0) {
            $reliableCount = $results->filter(function (WalkForwardResult $r) use ($minTrades) {
                $oosTrades = (int) ($r->oos_statistics['total_trades'] ?? 0);

                return ($r->oos_score ?? 0) > 0 && $oosTrades >= $minTrades;
            })->count();
        }

        $reliableRatio = $results->count() > 0 ? $reliableCount / $results->count() : 0.0;

        $lowTradeWarning = false;
        if ($bestOosResult !== null) {
            $oosTrades = (int) ($bestOosResult->oos_statistics['total_trades'] ?? 0);
            $isTrades = (int) ($bestOosResult->is_statistics['total_trades'] ?? 0);
            if ($oosTrades < self::LOW_TRADE_CNT_THRESHOLD || $isTrades < self::LOW_TRADE_CNT_THRESHOLD) {
                $lowTradeWarning = true;
            }
        }

        try {
            $parameterRanges = $wfRun->parameter_ranges ?? [];
        } catch (\BadMethodCallException) { /** @phpstan-ignore catch.neverThrown */
            $parameterRanges = [];
        }
        $boundaryWarnings = PackageAnalyzer::boundaryWarnings(
            $results->take(10)->map(fn (WalkForwardResult $r) => ['parameters' => $r->parameters])->all(),
            $parameterRanges
        );

        $benchmark = $this->computeBenchmark($wfRun);

        $beatBuyHoldCount = 0;
        $returnGt10Count = 0;
        $sharpeBeatBenchmarkCount = 0;
        $totalResults = $results->count();

        if ($benchmark['has_data']) {
            foreach ($results as $r) {
                $oosReturn = (float) ($r->oos_statistics['total_return_percent'] ?? 0);
                $oosSharpe = (float) ($r->oos_statistics['sharpe_ratio'] ?? 0);
                if ($oosReturn > $benchmark['return']) {
                    $beatBuyHoldCount++;
                }
                if ($oosSharpe > $benchmark['sharpe']) {
                    $sharpeBeatBenchmarkCount++;
                }
            }
        }

        foreach ($results as $r) {
            $oosReturn = (float) ($r->oos_statistics['total_return_percent'] ?? 0);
            if ($oosReturn > self::MEANINGFUL_RETURN_PCT) {
                $returnGt10Count++;
            }
        }

        $timeInMarket = $bestOosResult !== null
            ? (float) ($bestOosResult->oos_statistics['time_in_market_percent'] ?? 0)
            : 0.0;
        $exposureAdjustedTarget = 0.0;
        $captureRatio = 0.0;
        $marketCapture = 0.0;
        $capitalEfficiency = 0.0;

        if ($benchmark['has_data'] && $timeInMarket > 0) {
            $exposureAdjustedTarget = $benchmark['return'] * ($timeInMarket / 100);
            $captureRatio = $exposureAdjustedTarget > 0.001
                ? (($bestOosResult !== null ? (float) ($bestOosResult->oos_statistics['total_return_percent'] ?? 0) : 0.0) / $exposureAdjustedTarget) * 100
                : 0.0;
        }

        if ($benchmark['has_data'] && $benchmark['return'] != 0.0 && $bestOosResult !== null) {
            $strategyReturn = (float) ($bestOosResult->oos_statistics['total_return_percent'] ?? 0);
            $marketCapture = ($strategyReturn / $benchmark['return']) * 100;
            if ($timeInMarket > 0) {
                $capitalEfficiency = ($marketCapture / $timeInMarket) * 100;
            }
        }

        $bestOosStats = $bestOosResult !== null ? $bestOosResult->oos_statistics : null;
        $oosIsRatioWarning = PackageAnalyzer::detectInflatedOosIsRatio($oosIsRatio, $avgIsScore, $avgOosScore, $bestOosStats);

        $economicPerformance = PackageAnalyzer::classifyEconomicPerformance($bestOosStats, $benchmark);
        $economicInterpretation = PackageAnalyzer::interpretEconomicPerformance($economicPerformance, $bestOosStats, $benchmark);

        $suspiciousSharpe = PackageAnalyzer::detectSuspiciousSharpe($bestOosStats);

        /** @var list<WalkForwardResult> $resultList */
        $resultList = $results->all();

        return new WalkForwardAnalysis(
            walkForwardRun: $wfRun,
            results: $resultList,
            oosIsRatio: $oosIsRatio,
            robustCount: $profitableOos->count(),
            robustRatio: $results->count() > 0 ? $profitableOos->count() / $results->count() : 0.0,
            beatBuyHoldCount: $beatBuyHoldCount,
            beatBuyHoldRatio: $totalResults > 0 ? $beatBuyHoldCount / $totalResults : 0.0,
            returnGt10Count: $returnGt10Count,
            returnGt10Ratio: $totalResults > 0 ? $returnGt10Count / $totalResults : 0.0,
            sharpeBeatBenchmarkCount: $sharpeBeatBenchmarkCount,
            sharpeBeatBenchmarkRatio: $totalResults > 0 ? $sharpeBeatBenchmarkCount / $totalResults : 0.0,
            medianIsScore: $medianIsScore,
            medianOosScore: $medianOosScore,
            avgDegradation: $avgDegradation,
            medianDegradation: $medianDegradation,
            bestOosRank: $bestOosResult?->rank,
            bestOosResult: $bestOosResult,
            oosIsRatioWarning: $oosIsRatioWarning,
            stabilityClassification: $stabilityClassification,
            stabilityInterpretation: $stabilityInterpretation,
            economicPerformance: $economicPerformance,
            economicInterpretation: $economicInterpretation,
            rankCorrelation: $rankCorrelation,
            rankStabilityLabel: $rankStabilityLabel,
            reliableCount: $reliableCount,
            reliableRatio: $reliableRatio,
            minTrades: $minTrades,
            boundaryWarnings: $boundaryWarnings,
            lowTradeWarning: $lowTradeWarning,
            suspiciousSharpe: $suspiciousSharpe,
            benchmarkReturn: $benchmark['return'] ?? 0.0,
            benchmarkMaxDrawdown: $benchmark['max_drawdown'] ?? 0.0,
            benchmarkSharpe: $benchmark['sharpe'] ?? 0.0,
            benchmarkHasData: $benchmark['has_data'] ?? false,
            timeInMarket: $timeInMarket,
            exposureAdjustedTarget: $exposureAdjustedTarget,
            captureRatio: $captureRatio,
            marketCapture: $marketCapture,
            capitalEfficiency: $capitalEfficiency,
            medianOosReturn: $medianOosReturn,
            medianOosSharpe: $medianOosSharpe,
            medianOosMaxDd: $medianOosMaxDd,
        );
    }

    /**
     * Delegate to package boundaryWarnings. Kept as private for test compatibility.
     *
     * @param  Collection<int, WalkForwardResult>  $results
     * @param  array<string, array{min: float|int, max: float|int}>  $parameterRanges
     * @return array<int, array{param: string, direction: string, boundary: float, pct: float}>
     */
    private function boundaryWarnings($results, array $parameterRanges): array
    {
        return PackageAnalyzer::boundaryWarnings(
            $results->take(10)->map(fn (WalkForwardResult $r) => ['parameters' => $r->parameters])->all(),
            $parameterRanges
        );
    }

    /**
     * @param  array<string, mixed>  $benchmark
     */
    private function classifyEconomicPerformance(?WalkForwardResult $bestOosResult, array $benchmark): string
    {
        return PackageAnalyzer::classifyEconomicPerformance(
            $bestOosResult !== null ? $bestOosResult->oos_statistics : null,
            $benchmark
        );
    }

    private function detectSuspiciousSharpe(?WalkForwardResult $bestOosResult): bool
    {
        return PackageAnalyzer::detectSuspiciousSharpe(
            $bestOosResult !== null ? $bestOosResult->oos_statistics : null
        );
    }

    /**
     * Compute buy-and-hold benchmark over the OOS period.
     *
     * Compares the strategy's OOS result to what a simple buy-and-hold
     * investment would have returned over the same date range.
     *
     * @return array{return: float, max_drawdown: float, sharpe: float, has_data: bool}
     */
    private function computeBenchmark(WalkForwardRun $wfRun): array
    {
        if ($this->marketDataLoader === null) {
            return ['return' => 0.0, 'max_drawdown' => 0.0, 'sharpe' => 0.0, 'has_data' => false];
        }

        $symbols = $wfRun->symbols;
        $oosStart = $wfRun->oos_start_date;
        $oosEnd = $wfRun->oos_end_date;

        if (empty($symbols) || $oosStart === null || $oosEnd === null) {
            return ['return' => 0.0, 'max_drawdown' => 0.0, 'sharpe' => 0.0, 'has_data' => false];
        }

        $timeframe = TimeframeEnum::tryFrom($wfRun->timeframe);
        if ($timeframe === null) {
            return ['return' => 0.0, 'max_drawdown' => 0.0, 'sharpe' => 0.0, 'has_data' => false];
        }

        try {
            $oosStartCarbon = $oosStart instanceof Carbon ? $oosStart : Carbon::instance($oosStart);
            $oosEndCarbon = $oosEnd instanceof Carbon ? $oosEnd : Carbon::instance($oosEnd);

            $snapshot = $this->marketDataLoader->load(
                [$symbols[0]],
                $timeframe,
                $wfRun->exchange,
                $oosStartCarbon,
                $oosEndCarbon,
                dataType: $wfRun->data_type ?? 'ohlcv',
                brickSize: $wfRun->brick_size,
                atrPeriod: $wfRun->atr_period,
            );

            $signalData = $snapshot->signalData;
            if (! isset($signalData[$symbols[0]]['data'])) {
                return ['return' => 0.0, 'max_drawdown' => 0.0, 'sharpe' => 0.0, 'has_data' => false];
            }

            $closes = $signalData[$symbols[0]]['data']['close'] ?? [];

            if (count($closes) < 2) {
                return ['return' => 0.0, 'max_drawdown' => 0.0, 'sharpe' => 0.0, 'has_data' => false];
            }

            $firstClose = (float) $closes[0];
            $lastClose = (float) $closes[count($closes) - 1];

            if ($firstClose <= 0) {
                return ['return' => 0.0, 'max_drawdown' => 0.0, 'sharpe' => 0.0, 'has_data' => false];
            }

            $returnPct = (($lastClose - $firstClose) / $firstClose) * 100;

            $maxDrawdownPct = 0.0;
            $peak = $firstClose;
            foreach ($closes as $close) {
                $close = (float) $close;
                if ($close > $peak) {
                    $peak = $close;
                }
                $dd = ($peak > 0) ? (($peak - $close) / $peak) * 100 : 0.0;
                if ($dd > $maxDrawdownPct) {
                    $maxDrawdownPct = $dd;
                }
            }

            $returns = [];
            for ($i = 1; $i < count($closes); $i++) {
                $prev = (float) $closes[$i - 1];
                $curr = (float) $closes[$i];
                if ($prev > 0) {
                    $returns[] = ($curr - $prev) / $prev;
                }
            }

            $avgReturn = count($returns) > 0 ? array_sum($returns) / count($returns) : 0.0;
            $variance = 0.0;
            foreach ($returns as $r) {
                $variance += ($r - $avgReturn) ** 2;
            }
            $stdDev = count($returns) > 1 ? sqrt($variance / (count($returns) - 1)) : 0.0;

            $periodsPerYear = 31536000 / $timeframe->toSeconds();
            $annualizedStdDev = $stdDev * sqrt($periodsPerYear);
            $annualizedReturn = $avgReturn * $periodsPerYear;

            $sharpe = $annualizedStdDev > 0.001 ? $annualizedReturn / $annualizedStdDev : 0.0;

            return [
                'return' => round($returnPct, 2),
                'max_drawdown' => round($maxDrawdownPct, 2),
                'sharpe' => round($sharpe, 2),
                'has_data' => true,
            ];
        } catch (\Throwable) {
            return ['return' => 0.0, 'max_drawdown' => 0.0, 'sharpe' => 0.0, 'has_data' => false];
        }
    }
}
