<?php

namespace App\AlphaForge\Backtesting\MonteCarlo;

use App\AlphaForge\Backtesting\Model\BacktestRun;

class MonteCarloService
{
    /**
     * @param  array<int, string>  $tradePnlValues  Per-trade realized P&L strings
     * @param  string  $initialCapital  Initial capital for return calculations
     */
    public function __construct(
        private readonly array $tradePnlValues,
        private readonly string $initialCapital,
    ) {}

    public static function fromBacktestRunId(string $backtestId): self
    {
        $run = BacktestRun::find($backtestId);

        if (! $run) {
            throw new \InvalidArgumentException("Backtest run not found: {$backtestId}");
        }

        if (! $run->isCompleted()) {
            throw new \RuntimeException("Backtest run '{$backtestId}' is not completed.");
        }

        $stats = $run->statistics ?? [];
        $pnlValues = $stats['position_pnl_values'] ?? [];

        return new self($pnlValues, (string) $run->initial_capital);
    }

    public static function fromOptimizationTopN(string $optimizationId, int $rank = 1): self
    {
        $run = BacktestRun::where('optimization_id', $optimizationId)
            ->where('status', 'completed')
            ->orderBy('id', 'asc')
            ->skip($rank - 1)
            ->first();

        if (! $run) {
            throw new \InvalidArgumentException(
                "No backtest run found for optimization '{$optimizationId}' at rank {$rank}."
            );
        }

        return self::fromBacktestRunId($run->id);
    }

    /**
     * Run a Monte Carlo bootstrap analysis.
     *
     * Resamples trade P&L values with replacement to generate a distribution
     * of key performance metrics, providing confidence interval estimates.
     *
     * @param  int  $iterations  Number of bootstrap iterations (default: 1000)
     * @param  int  $seed  Random seed for reproducible results (null = random)
     */
    public function analyze(int $iterations = 1000, ?int $seed = null): MonteCarloReport
    {
        if ($seed !== null) {
            mt_srand($seed);
        }

        $totalTrades = count($this->tradePnlValues);

        if ($totalTrades === 0) {
            return new MonteCarloReport(
                totalTrades: 0,
                iterations: 0,
                metrics: [],
            );
        }

        $floatPnl = array_values(array_map(fn (string $v) => (float) $v, $this->tradePnlValues));
        $initialCapital = (float) $this->initialCapital;

        // Collect bootstrap metric distributions
        $distributions = [
            'total_return_pct' => [],
            'win_rate' => [],
            'max_drawdown_pct' => [],
            'profit_factor' => [],
            'avg_trade_pnl' => [],
            'positive_trades' => [],
        ];

        for ($i = 0; $i < $iterations; $i++) {
            $sample = $this->resample($floatPnl, $totalTrades);

            $equityCurve = $this->buildEquityCurve($sample, $initialCapital);
            $distributions['total_return_pct'][] = $this->totalReturnPct($initialCapital, $equityCurve[count($equityCurve) - 1]);
            $distributions['win_rate'][] = $this->winRate($sample);
            $distributions['max_drawdown_pct'][] = $this->maxDrawdownPct($equityCurve);
            $distributions['profit_factor'][] = $this->profitFactor($sample);
            $distributions['avg_trade_pnl'][] = $this->avgPnl($sample);
            $distributions['positive_trades'][] = $this->pctPositiveTrades($sample);
        }

        $metrics = [];
        foreach ($distributions as $metric => $values) {
            sort($values);
            $metrics[$metric] = new MonteCarloMetric(
                label: $this->metricLabel($metric),
                p5: $values[(int) floor($iterations * 0.05)] ?? $values[0],
                p25: $values[(int) floor($iterations * 0.25)] ?? $values[0],
                median: $values[(int) floor($iterations * 0.50)] ?? $values[0],
                p75: $values[(int) floor($iterations * 0.75)] ?? $values[count($values) - 1],
                p95: $values[(int) floor($iterations * 0.95)] ?? $values[count($values) - 1],
                probNegative: $this->probBelow($values, 0.0),
            );
        }

        return new MonteCarloReport(
            totalTrades: $totalTrades,
            iterations: $iterations,
            metrics: $metrics,
        );
    }

    /**
     * @param  list<float>  $pnlValues
     * @return list<float>
     */
    private function resample(array $pnlValues, int $size): array
    {
        $sample = [];
        for ($i = 0; $i < $size; $i++) {
            $sample[] = $pnlValues[array_rand($pnlValues)];
        }

        return $sample;
    }

    /**
     * @param  list<float>  $pnlValues
     * @return list<float>
     */
    private function buildEquityCurve(array $pnlValues, float $initialCapital): array
    {
        $curve = [$initialCapital];
        foreach ($pnlValues as $pnl) {
            $curve[] = end($curve) + $pnl;
        }

        return $curve;
    }

    private function totalReturnPct(float $initialCapital, float $finalCapital): float
    {
        return round((($finalCapital - $initialCapital) / $initialCapital) * 100, 4);
    }

    /**
     * @param  list<float>  $pnlValues
     */
    private function winRate(array $pnlValues): float
    {
        if (empty($pnlValues)) {
            return 0.0;
        }

        $wins = 0;
        foreach ($pnlValues as $pnl) {
            if ($pnl > 0) {
                $wins++;
            }
        }

        return round(($wins / count($pnlValues)) * 100, 2);
    }

    /**
     * @param  list<float>  $equityCurve
     */
    private function maxDrawdownPct(array $equityCurve): float
    {
        if (empty($equityCurve)) {
            return 0.0;
        }

        $peak = $equityCurve[0];
        $maxDD = 0.0;

        foreach ($equityCurve as $value) {
            if ($value > $peak) {
                $peak = $value;
            }

            $dd = ($peak > 0) ? ($peak - $value) / $peak : 0.0;
            if ($dd > $maxDD) {
                $maxDD = $dd;
            }
        }

        return round($maxDD * -100, 4);
    }

    /**
     * @param  list<float>  $pnlValues
     */
    private function profitFactor(array $pnlValues): float
    {
        $grossProfit = 0.0;
        $grossLoss = 0.0;

        foreach ($pnlValues as $pnl) {
            if ($pnl > 0) {
                $grossProfit += $pnl;
            } else {
                $grossLoss += abs($pnl);
            }
        }

        if ($grossLoss <= 0.0001) {
            return $grossProfit > 0 ? INF : 0.0;
        }

        return round($grossProfit / $grossLoss, 4);
    }

    /**
     * @param  list<float>  $pnlValues
     */
    private function avgPnl(array $pnlValues): float
    {
        if (empty($pnlValues)) {
            return 0.0;
        }

        return round(array_sum($pnlValues) / count($pnlValues), 4);
    }

    /**
     * @param  list<float>  $pnlValues
     */
    private function pctPositiveTrades(array $pnlValues): float
    {
        if (empty($pnlValues)) {
            return 0.0;
        }

        $positive = 0;
        foreach ($pnlValues as $pnl) {
            if ($pnl > 0) {
                $positive++;
            }
        }

        return round(($positive / count($pnlValues)) * 100, 2);
    }

    /**
     * @param  list<float>  $values
     */
    private function probBelow(array $values, float $threshold): float
    {
        if (empty($values)) {
            return 0.0;
        }

        $count = 0;
        foreach ($values as $v) {
            if ($v < $threshold) {
                $count++;
            }
        }

        return round(($count / count($values)) * 100, 2);
    }

    private function metricLabel(string $metric): string
    {
        return match ($metric) {
            'total_return_pct' => 'Total Return %',
            'win_rate' => 'Win Rate %',
            'max_drawdown_pct' => 'Max Drawdown %',
            'profit_factor' => 'Profit Factor',
            'avg_trade_pnl' => 'Avg Trade PnL',
            'positive_trades' => 'Positive Trades %',
            default => $metric,
        };
    }
}
