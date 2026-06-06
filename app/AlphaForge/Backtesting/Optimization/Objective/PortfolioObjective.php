<?php

namespace App\AlphaForge\Backtesting\Optimization\Objective;

class PortfolioObjective implements ObjectiveFunctionInterface
{
    /**
     * @param  array<string, array<string, mixed>>  $symbolStatistics  Per-symbol statistics keyed by symbol
     */
    public function score(array $symbolStatistics): float
    {
        $symbols = array_keys($symbolStatistics);
        $n = count($symbols);

        if ($n === 0) {
            return 0.0;
        }

        $totalReturn = 0.0;
        $totalSharpe = 0.0;
        $totalWinRate = 0.0;
        $totalDrawdown = 0.0;
        $totalTrades = 0;
        $validSymbols = 0;
        $symbolReturns = [];

        $minTradesPerSymbol = (int) ($symbolStatistics['_config']['min_trades_per_symbol'] ?? 5);

        foreach ($symbols as $symbol) {
            $stats = $symbolStatistics[$symbol];
            $trades = (int) ($stats['total_trades'] ?? 0);

            if ($trades < $minTradesPerSymbol) {
                continue;
            }

            $totalReturn += (float) ($stats['total_return_percent'] ?? 0);
            $totalSharpe += max(0, (float) ($stats['sharpe_ratio'] ?? 0));
            $totalWinRate += (float) ($stats['win_rate'] ?? 0);
            $totalDrawdown += abs((float) ($stats['max_drawdown_percent'] ?? 0));
            $totalTrades += $trades;
            $symbolReturns[] = (float) ($stats['total_return_percent'] ?? 0);
            $validSymbols++;
        }

        if ($validSymbols === 0) {
            return 0.0;
        }

        $avgReturn = $totalReturn / $validSymbols;
        $avgSharpe = $totalSharpe / $validSymbols;
        $avgWinRate = $totalWinRate / $validSymbols;
        $avgDrawdown = $totalDrawdown / $validSymbols;

        // Base score: weighted combination of key metrics
        $score = ($avgSharpe * 0.40)
            + (($avgReturn / max(1, $avgDrawdown)) * 0.30)
            + (($avgWinRate / 100) * 0.20)
            + (min(1.0, $totalTrades / 50) * 0.10);

        // Correlation penalty: penalize if symbols are too correlated
        if ($n >= 2) {
            $correlationPenalty = $this->correlationPenalty($symbolReturns);
            $score = $score * (1.0 - $correlationPenalty);
        }

        // Diversity bonus: reward consistent performance across all symbols
        $participationRate = $validSymbols / $n;
        $score *= $participationRate;

        return round(max(0.0, $score), 6);
    }

    public function label(): string
    {
        return 'portfolio_score';
    }

    /**
     * Compute a correlation-based penalty from per-symbol return values.
     *
     * Uses a simplified version of pairwise correlation strength.
     * Penalty increases as returns become more correlated.
     * Returns 0.0 = perfectly diversified, 1.0 = perfectly correlated.
     *
     * @param  list<float>  $returns
     */
    public function correlationPenalty(array $returns): float
    {
        $n = count($returns);
        if ($n < 2) {
            return 0.0;
        }

        $mean = array_sum($returns) / $n;

        $variance = 0.0;
        foreach ($returns as $r) {
            $variance += ($r - $mean) ** 2;
        }
        $variance /= $n;

        $std = sqrt($variance);
        if ($std < 0.0001) {
            return 0.0;
        }

        // Normalize returns to z-scores and compute spread
        // If z-scores are tightly clustered → high correlation → higher penalty
        $maxAbsZ = 0.0;
        foreach ($returns as $r) {
            $maxAbsZ = max($maxAbsZ, abs(($r - $mean) / $std));
        }

        // Penalty increases when z-scores are tightly clustered (all returns similar = correlated)
        // Wide spread = diversified = low penalty
        $penalty = max(0.0, 1.0 - ($maxAbsZ * 0.5));

        return round(max(0.0, min(0.5, $penalty)), 4);
    }
}
