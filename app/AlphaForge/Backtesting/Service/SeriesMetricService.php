<?php

namespace App\AlphaForge\Backtesting\Service;

use App\AlphaForge\Common\Model\Series;
use Ds\Vector;

readonly class SeriesMetricService implements SeriesMetricServiceInterface
{
    /**
     * Calculate various metrics for a time series.
     *
     * @param  Series  $series  The time series data
     * @return array<string, mixed> Calculated metrics
     */
    public function calculate(Series $series): array
    {
        $values = $series->getVector();

        if ($values->isEmpty()) {
            return $this->getEmptyMetrics();
        }

        return [
            'count' => $values->count(),
            'min' => $this->calculateMin($values),
            'max' => $this->calculateMax($values),
            'mean' => $this->calculateMean($values),
            'median' => $this->calculateMedian($values),
            'std_dev' => $this->calculateStdDev($values),
            'variance' => $this->calculateVariance($values),
            'sum' => $this->calculateSum($values),
            'range' => $this->calculateRange($values),
            'quartiles' => $this->calculateQuartiles($values),
            'skewness' => $this->calculateSkewness($values),
            'kurtosis' => $this->calculateKurtosis($values),
        ];
    }

    /**
     * Calculate minimum value.
     */
    private function calculateMin(Vector $values): string
    {
        $min = $values->first();
        foreach ($values as $value) {
            if (bccomp($value, $min, 12) < 0) {
                $min = $value;
            }
        }

        return $min;
    }

    /**
     * Calculate maximum value.
     */
    private function calculateMax(Vector $values): string
    {
        $max = $values->first();
        foreach ($values as $value) {
            if (bccomp($value, $max, 12) > 0) {
                $max = $value;
            }
        }

        return $max;
    }

    /**
     * Calculate mean (average).
     */
    private function calculateMean(Vector $values): string
    {
        $sum = '0';
        foreach ($values as $value) {
            $sum = bcadd($sum, $value, 12);
        }

        return bcdiv($sum, (string) $values->count(), 12);
    }

    /**
     * Calculate median.
     */
    private function calculateMedian(Vector $values): string
    {
        $sorted = $values->toArray();
        sort($sorted, SORT_NUMERIC);

        $count = count($sorted);
        $mid = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return bcdiv(
                bcadd((string) $sorted[$mid - 1], (string) $sorted[$mid], 12),
                '2',
                12
            );
        }

        return (string) $sorted[$mid];
    }

    /**
     * Calculate standard deviation.
     */
    private function calculateStdDev(Vector $values): string
    {
        $variance = $this->calculateVariance($values);

        return bcsqrt($variance, 12);
    }

    /**
     * Calculate variance.
     */
    private function calculateVariance(Vector $values): string
    {
        $mean = $this->calculateMean($values);
        $squaredDiffs = '0';

        foreach ($values as $value) {
            $diff = bcsub($value, $mean, 12);
            $squaredDiffs = bcadd($squaredDiffs, bcmul($diff, $diff, 12), 12);
        }

        return bcdiv($squaredDiffs, (string) $values->count(), 12);
    }

    /**
     * Calculate sum.
     */
    private function calculateSum(Vector $values): string
    {
        $sum = '0';
        foreach ($values as $value) {
            $sum = bcadd($sum, $value, 12);
        }

        return $sum;
    }

    /**
     * Calculate range (max - min).
     */
    private function calculateRange(Vector $values): string
    {
        return bcsub($this->calculateMax($values), $this->calculateMin($values), 12);
    }

    /**
     * Calculate quartiles (Q1, Q2, Q3).
     */
    private function calculateQuartiles(Vector $values): array
    {
        $sorted = $values->toArray();
        sort($sorted, SORT_NUMERIC);

        $count = count($sorted);

        return [
            'q1' => $this->calculatePercentile($sorted, 25),
            'q2' => $this->calculatePercentile($sorted, 50),
            'q3' => $this->calculatePercentile($sorted, 75),
        ];
    }

    /**
     * Calculate a specific percentile.
     */
    private function calculatePercentile(array $sorted, float $percentile): string
    {
        $count = count($sorted);
        $index = ($percentile / 100) * ($count - 1);

        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $fraction = $index - $lower;

        if ($lower === $upper) {
            return (string) $sorted[$lower];
        }

        return bcadd(
            bcmul((string) $sorted[$lower], bcsub('1', (string) $fraction, 12), 12),
            bcmul((string) $sorted[$upper], (string) $fraction, 12),
            12
        );
    }

    /**
     * Calculate skewness (measure of asymmetry).
     */
    private function calculateSkewness(Vector $values): string
    {
        $count = $values->count();
        if ($count < 3) {
            return '0';
        }

        $mean = $this->calculateMean($values);
        $stdDev = $this->calculateStdDev($values);

        if (bccomp($stdDev, '0', 12) === 0) {
            return '0';
        }

        $cubedStdDev = bcmul($stdDev, bcmul($stdDev, $stdDev, 12), 12);
        $sumCubedDiffs = '0';

        foreach ($values as $value) {
            $diff = bcsub($value, $mean, 12);
            $cubedDiff = bcmul($diff, bcmul($diff, $diff, 12), 12);
            $sumCubedDiffs = bcadd($sumCubedDiffs, $cubedDiff, 12);
        }

        return bcdiv(
            bcdiv($sumCubedDiffs, $cubedStdDev, 12),
            (string) $count,
            6
        );
    }

    /**
     * Calculate kurtosis (measure of "tailedness").
     */
    private function calculateKurtosis(Vector $values): string
    {
        $count = $values->count();
        if ($count < 4) {
            return '0';
        }

        $mean = $this->calculateMean($values);
        $stdDev = $this->calculateStdDev($values);

        if (bccomp($stdDev, '0', 12) === 0) {
            return '0';
        }

        $fourthPowerStdDev = bcmul(
            bcmul($stdDev, $stdDev, 12),
            bcmul($stdDev, $stdDev, 12),
            12
        );
        $sumFourthDiffs = '0';

        foreach ($values as $value) {
            $diff = bcsub($value, $mean, 12);
            $squaredDiff = bcmul($diff, $diff, 12);
            $fourthDiff = bcmul($squaredDiff, $squaredDiff, 12);
            $sumFourthDiffs = bcadd($sumFourthDiffs, $fourthDiff, 12);
        }

        return bcdiv(
            bcdiv($sumFourthDiffs, $fourthPowerStdDev, 12),
            (string) $count,
            6
        );
    }

    /**
     * Get empty metrics array.
     */
    private function getEmptyMetrics(): array
    {
        return [
            'count' => 0,
            'min' => '0',
            'max' => '0',
            'mean' => '0',
            'median' => '0',
            'std_dev' => '0',
            'variance' => '0',
            'sum' => '0',
            'range' => '0',
            'quartiles' => [
                'q1' => '0',
                'q2' => '0',
                'q3' => '0',
            ],
            'skewness' => '0',
            'kurtosis' => '0',
        ];
    }

    /**
     * Calculate annualized Sharpe ratio from a flat array of periodic returns.
     *
     * @param  array<int, float>  $returns
     */
    public function sharpeRatioFromReturns(array $returns): float
    {
        if (count($returns) < 2) {
            return 0.0;
        }

        $mean = array_sum($returns) / count($returns);

        $variance = 0.0;
        foreach ($returns as $r) {
            $variance += ($r - $mean) ** 2;
        }

        $stdDev = sqrt($variance / (count($returns) - 1));

        if ($stdDev < 1e-15) {
            return 0.0;
        }

        return $mean / $stdDev;
    }

    /**
     * Calculate Sortino ratio from a flat array of periodic returns.
     *
     * Uses only downside deviation (returns below zero) in the denominator.
     *
     * @param  array<int, float>  $returns
     */
    public function sortinoRatioFromReturns(array $returns): float
    {
        $mean = count($returns) > 0 ? array_sum($returns) / count($returns) : 0.0;

        $downsideReturns = array_filter($returns, fn ($r) => $r < 0);

        if (count($downsideReturns) < 2) {
            return 0.0;
        }

        $downsideMean = array_sum($downsideReturns) / count($downsideReturns);
        $downsideVariance = 0.0;
        foreach ($downsideReturns as $r) {
            $downsideVariance += ($r - $downsideMean) ** 2;
        }

        $downsideStdDev = sqrt($downsideVariance / (count($downsideReturns) - 1));

        if ($downsideStdDev < 1e-15) {
            return 0.0;
        }

        return $mean / $downsideStdDev;
    }

    /**
     * Calculate maximum drawdown from an array of returns.
     *
     * @param  array<int, float>  $returns
     */
    public function maxDrawdownFromReturns(array $returns): float
    {
        $cumulativeReturn = 1.0;
        $peak = 1.0;
        $maxDrawdown = 0.0;

        foreach ($returns as $r) {
            $cumulativeReturn *= (1 + $r);
            $peak = max($peak, $cumulativeReturn);
            $drawdown = ($peak - $cumulativeReturn) / $peak;
            $maxDrawdown = max($maxDrawdown, $drawdown);
        }

        return $maxDrawdown;
    }

    /**
     * Calculate performance stability — fraction of trading days with positive net return.
     *
     * @param  array<int, array{timestamp: int, pnl: float}>  $trades
     */
    public function performanceStabilityFromTrades(array $trades): float
    {
        $periodReturns = [];

        foreach ($trades as $trade) {
            $date = date('Y-m-d', $trade['timestamp']);
            if (! isset($periodReturns[$date])) {
                $periodReturns[$date] = 0.0;
            }
            $periodReturns[$date] += $trade['pnl'];
        }

        $positivePeriods = 0;
        $totalPeriods = count($periodReturns);

        foreach ($periodReturns as $return) {
            if ($return > 0) {
                $positivePeriods++;
            }
        }

        return $totalPeriods > 0 ? $positivePeriods / $totalPeriods : 0.0;
    }

    /**
     * Calculate basic win/loss statistics from an array of returns.
     *
     * @param  array<int, float>  $returns
     * @return array{total_trades: int, winning_trades: int, losing_trades: int, win_rate: float, expected_value: float}
     */
    public function tradeWinLossStats(array $returns): array
    {
        if (empty($returns)) {
            return [
                'total_trades' => 0,
                'winning_trades' => 0,
                'losing_trades' => 0,
                'win_rate' => 0.0,
                'expected_value' => 0.0,
            ];
        }

        $total = count($returns);
        $wins = count(array_filter($returns, fn ($r) => $r > 0));
        $losses = count(array_filter($returns, fn ($r) => $r < 0));

        return [
            'total_trades' => $total,
            'winning_trades' => $wins,
            'losing_trades' => $losses,
            'win_rate' => $total > 0 ? (float) $wins / $total : 0.0,
            'expected_value' => $total > 0 ? array_sum($returns) / $total : 0.0,
        ];
    }
}
