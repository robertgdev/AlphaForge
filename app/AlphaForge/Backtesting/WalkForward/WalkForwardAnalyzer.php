<?php

namespace App\AlphaForge\Backtesting\WalkForward;

use App\AlphaForge\Backtesting\Model\WalkForwardResult;
use App\AlphaForge\Backtesting\Model\WalkForwardRun;
use Illuminate\Support\Collection;

class WalkForwardAnalyzer
{
    private const LOW_TRADE_CNT_THRESHOLD = 30;

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
                avgDegradation: 0.0,
                medianDegradation: 0.0,
                bestOosRank: null,
                bestOosResult: null,
                classification: 'likely_overfit',
                interpretation: 'No results available for analysis.',
                rankCorrelation: null,
                rankStabilityLabel: 'unstable',
                reliableCount: 0,
                reliableRatio: 0.0,
                minTrades: $minTrades,
                boundaryWarnings: [],
                lowTradeWarning: false,
            );
        }

        $profitableOos = $results->filter(fn (WalkForwardResult $r) => ($r->oos_score ?? 0) > 0);

        /** @var list<float> $isScores */
        $isScores = $results->map(fn (WalkForwardResult $r) => (float) ($r->is_score ?? 0.0))->values()->all();
        /** @var list<float> $oosScores */
        $oosScores = $results->map(fn (WalkForwardResult $r) => (float) ($r->oos_score ?? 0.0))->values()->all();
        /** @var list<float> $degradations */
        $degradations = $results->map(fn (WalkForwardResult $r) => (float) ($r->score_degradation ?? 0.0))->values()->all();

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

        $classification = $this->classifyRobustness($oosIsRatio, $robustRatioPct, $rankCorrelation);
        $interpretation = $this->interpretClassification($classification);

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

        /** @var list<WalkForwardResult> $resultList */
        $resultList = $results->all();

        return new WalkForwardAnalysis(
            walkForwardRun: $wfRun,
            results: $resultList,
            oosIsRatio: $oosIsRatio,
            robustCount: $profitableOos->count(),
            robustRatio: $results->count() > 0 ? $profitableOos->count() / $results->count() : 0.0,
            avgDegradation: $avgDegradation,
            medianDegradation: $medianDegradation,
            bestOosRank: $bestOosResult?->rank,
            bestOosResult: $bestOosResult,
            classification: $classification,
            interpretation: $interpretation,
            rankCorrelation: $rankCorrelation,
            rankStabilityLabel: $rankStabilityLabel,
            reliableCount: $reliableCount,
            reliableRatio: $reliableRatio,
            minTrades: $minTrades,
            boundaryWarnings: $boundaryWarnings,
            lowTradeWarning: $lowTradeWarning,
        );
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
     * @param  \Illuminate\Support\Collection<int, WalkForwardResult>  $results
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
}
