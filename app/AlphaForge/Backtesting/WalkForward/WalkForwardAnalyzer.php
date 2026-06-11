<?php

namespace App\AlphaForge\Backtesting\WalkForward;

use App\AlphaForge\Backtesting\Model\WalkForwardResult;
use App\AlphaForge\Backtesting\Model\WalkForwardRun;
use App\AlphaForge\Backtesting\Optimization\MarketDataLoader;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class WalkForwardAnalyzer
{
    private const LOW_TRADE_CNT_THRESHOLD = 30;
    private const SMALL_RETURN_THRESHOLD = 2.0;
    private const HIGH_SHARPE_THRESHOLD = 5.0;          // Sharpe above this with low return triggers suspicion
    private const OOS_IS_RATIO_INFLATED_THRESHOLD = 120.0; // Ratio above this with tiny returns is misleading
    private const MEANINGFUL_RETURN_PCT = 10.0;         // Threshold for economically meaningful return

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
            );
        }

        $profitableOos = $results->filter(fn (WalkForwardResult $r) => ($r->oos_score ?? 0) > 0);

        /** @var list<float> $isScores */
        $isScores = $results->map(fn (WalkForwardResult $r) => (float) ($r->is_score ?? 0.0))->values()->all();
        /** @var list<float> $oosScores */
        $oosScores = $results->map(fn (WalkForwardResult $r) => (float) ($r->oos_score ?? 0.0))->values()->all();
        /** @var list<float> $degradations */
        $degradations = $results->map(fn (WalkForwardResult $r) => (float) ($r->score_degradation ?? 0.0))->values()->all();

        $medianIsScore = $this->median($isScores);
        $medianOosScore = $this->median($oosScores);

        $avgIsScore = array_sum($isScores) / count($isScores);
        $avgOosScore = array_sum($oosScores) / count($oosScores);

        $oosIsRatio = $avgIsScore != 0.0
            ? ($avgOosScore / $avgIsScore) * 100
            : 0.0;

        $avgDegradation = array_sum($degradations) / count($degradations);
        $medianDegradation = $this->median($degradations);

        /** @var WalkForwardResult|null $bestOosResult */
        $bestOosResult = $results->first(fn (WalkForwardResult $r) => $r->rank === (
            $results->sortByDesc('oos_score')->first()?->rank
        ));

        $robustRatioPct = $results->count() > 0 ? $profitableOos->count() / $results->count() * 100 : 0.0;

        $rankCorrelation = $this->spearmanRankCorrelation($results);
        $rankStabilityLabel = $this->classifyRankStability($rankCorrelation);

        $stabilityClassification = $this->classifyRobustness($oosIsRatio, $robustRatioPct, $rankCorrelation);
        $stabilityInterpretation = $this->interpretClassification($stabilityClassification);

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
        } catch (\BadMethodCallException) {
            $parameterRanges = [];
        }
        $boundaryWarnings = $this->boundaryWarnings($results, $parameterRanges);

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

        if ($benchmark['has_data'] && $timeInMarket > 0) {
            $exposureAdjustedTarget = $benchmark['return'] * ($timeInMarket / 100);
            $captureRatio = $exposureAdjustedTarget > 0.001
                ? (($bestOosResult !== null ? (float) ($bestOosResult->oos_statistics['total_return_percent'] ?? 0) : 0.0) / $exposureAdjustedTarget) * 100
                : 0.0;
        }

        $oosIsRatioWarning = $this->detectInflatedOosIsRatio($oosIsRatio, $avgIsScore, $avgOosScore, $bestOosResult);

        $economicPerformance = $this->classifyEconomicPerformance($bestOosResult, $benchmark);
        $economicInterpretation = $this->interpretEconomicPerformance($economicPerformance, $bestOosResult, $benchmark);

        $suspiciousSharpe = $this->detectSuspiciousSharpe($bestOosResult);

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
        );
    }

    /**
     * Flag the OOS/IS ratio as inflated when returns are near zero.
     * A ratio can explode when both numerator and denominator are tiny.
     */
    private function detectInflatedOosIsRatio(float $oosIsRatio, float $avgIsScore, float $avgOosScore, ?WalkForwardResult $bestOosResult): bool
    {
        if ($oosIsRatio < self::OOS_IS_RATIO_INFLATED_THRESHOLD) {
            return false;
        }

        $strategyReturn = abs((float) ($bestOosResult->oos_statistics['total_return_percent'] ?? 0));

        if ($strategyReturn < self::SMALL_RETURN_THRESHOLD) {
            return true;
        }

        if (abs($avgIsScore) < 0.01 && abs($avgOosScore) < 0.01) {
            return true;
        }

        return false;
    }

    /**
     * Classify economic performance relative to buy-and-hold benchmark.
     */
    private function classifyEconomicPerformance(?WalkForwardResult $bestOosResult, array $benchmark): string
    {
        if ($bestOosResult === null || ! ($benchmark['has_data'] ?? false)) {
            return 'unknown';
        }

        $strategyReturn = abs((float) ($bestOosResult->oos_statistics['total_return_percent'] ?? 0));
        $benchmarkReturn = abs((float) ($benchmark['return'] ?? 0));

        if ($strategyReturn < self::SMALL_RETURN_THRESHOLD) {
            return 'poor';
        }

        if ($benchmarkReturn <= 0) {
            if ($strategyReturn >= self::SMALL_RETURN_THRESHOLD) {
                return 'strong';
            }

            return 'moderate';
        }

        $ratio = $strategyReturn / $benchmarkReturn;

        if ($ratio >= 0.5) {
            return 'strong';
        }
        if ($ratio >= 0.1) {
            return 'moderate';
        }

        return 'poor';
    }

    private function interpretEconomicPerformance(string $performance, ?WalkForwardResult $bestOosResult, array $benchmark): string
    {
        if ($performance === 'unknown') {
            return 'insufficient data to assess economic performance';
        }

        if ($bestOosResult === null || ! ($benchmark['has_data'] ?? false)) {
            return 'insufficient data to assess economic performance';
        }

        $strategyReturn = (float) ($bestOosResult->oos_statistics['total_return_percent'] ?? 0);
        $benchmarkReturn = (float) ($benchmark['return'] ?? 0);

        return match ($performance) {
            'strong' => sprintf('strategy return (%s%%) is on par with or exceeds buy-and-hold (%s%%)',
                number_format($strategyReturn, 2), number_format($benchmarkReturn, 2)),
            'moderate' => sprintf('strategy return (%s%%) captures some of the market move but trails buy-and-hold (%s%%)',
                number_format($strategyReturn, 2), number_format($benchmarkReturn, 2)),
            'poor' => sprintf('strategy return (%s%%) is negligible relative to buy-and-hold (%s%%); economic performance is unattractive',
                number_format($strategyReturn, 2), number_format($benchmarkReturn, 2)),
            default => 'unknown',
        };
    }

    /**
     * Detect suspiciously high Sharpe ratio alongside tiny absolute returns.
     */
    private function detectSuspiciousSharpe(?WalkForwardResult $bestOosResult): bool
    {
        if ($bestOosResult === null) {
            return false;
        }

        $sharpe = (float) ($bestOosResult->oos_statistics['sharpe_ratio'] ?? 0);
        $returnPct = abs((float) ($bestOosResult->oos_statistics['total_return_percent'] ?? 0));

        return $sharpe > self::HIGH_SHARPE_THRESHOLD && $returnPct < self::SMALL_RETURN_THRESHOLD;
    }

    /**
     * Multi-factor robustness classification using rank correlation, WFE, and profitable ratio.
     */
    private function classifyRobustness(float $oosIsRatio, float $robustRatioPercent, ?float $spearman): string
    {
        $spearman = $spearman ?? 0.0;

        if ($spearman > 0.8 && $oosIsRatio > 70 && $robustRatioPercent > 70) {
            return 'excellent';
        }

        if ($spearman > 0.6 && $oosIsRatio > 50 && $robustRatioPercent > 50) {
            return 'good';
        }

        if ($spearman > 0.4 && $oosIsRatio > 30 && $robustRatioPercent > 30) {
            return 'moderate';
        }

        if ($spearman < 0.2 || $oosIsRatio < 10 || $robustRatioPercent < 10) {
            return 'likely_overfit';
        }

        return 'weak';
    }

    private function interpretClassification(string $classification): string
    {
        return match ($classification) {
            'excellent' => 'strong evidence of generalization; IS rank strongly predicts OOS performance',
            'good' => 'parameters generalize well to unseen data',
            'moderate' => 'partial generalization; some parameters carry over; treat with caution',
            'weak' => 'limited generalization; results should be treated with caution',
            'likely_overfit' => 'parameters do not generalize; optimization results are likely overfit',
            default => 'unknown classification',
        };
    }

    /**
     * Check if top-N parameters cluster near search boundaries.
     *
     * @param  Collection<int, WalkForwardResult>  $results
     * @param  array<string, mixed>  $parameterRanges
     * @return array<int, array{param: string, direction: string, boundary: float, pct: float}>
     */
    private function boundaryWarnings($results, array $parameterRanges): array
    {
        if (empty($parameterRanges) || $results->isEmpty()) {
            return [];
        }

        $warnings = [];
        $totalResults = $results->count();
        $topN = min($totalResults, 10);

        foreach ($parameterRanges as $param => $range) {
            if (! is_array($range) || ! isset($range['min'], $range['max'])) {
                continue;
            }

            $min = (float) $range['min'];
            $max = (float) $range['max'];
            $span = $max - $min;

            if ($span <= 0) {
                continue;
            }

            $nearMin = 0;
            $nearMax = 0;

            for ($i = 0; $i < $topN; $i++) {
                $result = $results->get($i);
                if (! $result || ! isset($result->parameters[$param])) {
                    continue;
                }

                $val = (float) $result->parameters[$param];
                $pct = ($val - $min) / $span;

                if ($pct <= 0.10) {
                    $nearMin++;
                }
                if ($pct >= 0.90) {
                    $nearMax++;
                }
            }

            $minPct = ($nearMin / $topN) * 100;
            $maxPct = ($nearMax / $topN) * 100;

            if ($minPct >= 50) {
                $warnings[] = [
                    'param' => $param,
                    'direction' => 'min',
                    'boundary' => $min,
                    'pct' => round($minPct, 0),
                ];
            }

            if ($maxPct >= 50) {
                $warnings[] = [
                    'param' => $param,
                    'direction' => 'max',
                    'boundary' => $max,
                    'pct' => round($maxPct, 0),
                ];
            }
        }

        return $warnings;
    }

    private function classifyRankStability(?float $correlation): string
    {
        if ($correlation === null) {
            return 'unstable';
        }

        if ($correlation > 0.7) {
            return 'stable';
        }

        if ($correlation > 0.3) {
            return 'moderate';
        }

        return 'unstable';
    }

    /**
     * @param  Collection<int, WalkForwardResult>  $results
     */
    private function spearmanRankCorrelation(Collection $results): ?float
    {
        if ($results->count() < 2) {
            return null;
        }

        $oosScores = $results->map(fn (WalkForwardResult $r) => $r->oos_score ?? 0.0)->toArray();

        $allSame = count(array_unique($oosScores)) === 1;
        if ($allSame) {
            return 0.0;
        }

        $isRanks = $this->rankValues($results->map(fn (WalkForwardResult $r) => $r->is_score ?? 0.0)->toArray());
        $oosRanks = $this->rankValues($oosScores);

        return $this->pearsonCorrelation($isRanks, $oosRanks);
    }

    private function rankValues(array $values): array
    {
        $n = count($values);
        $indexed = [];
        foreach ($values as $i => $v) {
            $indexed[] = ['index' => $i, 'value' => $v];
        }

        usort($indexed, fn ($a, $b) => $a['value'] <=> $b['value']);

        $ranks = array_fill(0, $n, 0.0);
        $i = 0;
        while ($i < $n) {
            $j = $i;
            while ($j < $n - 1 && $indexed[$j + 1]['value'] == $indexed[$j]['value']) {
                $j++;
            }
            $avgRank = ($i + $j) / 2.0 + 1.0;
            for ($k = $i; $k <= $j; $k++) {
                $ranks[$indexed[$k]['index']] = $avgRank;
            }
            $i = $j + 1;
        }

        return $ranks;
    }

    private function pearsonCorrelation(array $x, array $y): float
    {
        $n = count($x);
        if ($n === 0) {
            return 0.0;
        }

        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $numerator = 0.0;
        $denomX = 0.0;
        $denomY = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;
            $numerator += $dx * $dy;
            $denomX += $dx * $dx;
            $denomY += $dy * $dy;
        }

        $denominator = sqrt($denomX * $denomY);
        if ($denominator == 0.0) {
            return 0.0;
        }

        return $numerator / $denominator;
    }

    private function median(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        $sorted = $values;
        sort($sorted);
        $count = count($sorted);
        $mid = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ($sorted[$mid - 1] + $sorted[$mid]) / 2.0;
        }

        return $sorted[$mid];
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
