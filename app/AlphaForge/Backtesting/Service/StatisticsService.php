<?php

namespace App\AlphaForge\Backtesting\Service;

use App\AlphaForge\Common\Util\Math;
use App\AlphaForge\Order\Dto\PositionDto;
use Ds\Vector;

readonly class StatisticsService implements StatisticsServiceInterface
{
    private const MIN_OBSERVATIONS_FOR_RISK = 10;

    /**
     * Minimum annualized volatility required for meaningful risk metrics.
     *
     * Below this threshold, Sharpe/Sortino ratios are clamped to 0 — a
     * strategy with near-zero annualized volatility is effectively flat,
     * and dividing by minuscule numbers produces unstable results.
     *
     * Checked against annualized (not per-period) volatility so the guard
     * is data-type agnostic: daily, hourly, 1m, and renko all use the
     * same meaningful 0.1% annualized floor.
     */
    private const MIN_ANNUALIZED_VOLATILITY = '0.001';

    /**
     * Calculate comprehensive backtest statistics.
     *
     * @param  Vector<PositionDto>  $positions  All closed positions
     * @param  string  $initialCapital  Starting capital
     * @param  string  $finalCapital  Ending capital
     * @param  string  $riskFreeRate  Annual risk-free rate (e.g., "0.02" for 2%)
     * @param  int  $tradingDaysPerYear  Number of periods per year (e.g., 8760 for 1h bars, 365 for 1d bars)
     * @param  Vector<string>|null  $barEquityCurve  Bar-level equity curve for accurate periodic risk metrics
     * @return array<string, mixed> Comprehensive statistics array
     */
    public function calculate(
        Vector $positions,
        string $initialCapital,
        string $finalCapital,
        ?string $riskFreeRate = null,
        int $tradingDaysPerYear = 252,
        ?Vector $barEquityCurve = null,
    ): array {
        $riskFreeRate ??= '0.02';
        $closedPositions = $positions->filter(fn (PositionDto $p) => $p->exitTime !== null);

        if ($closedPositions->isEmpty()) {
            return $this->getEmptyStatistics();
        }

        $tradeEquityCurve = $this->buildEquityCurve($closedPositions, $initialCapital);

        $useBarCurve = $barEquityCurve !== null && $barEquityCurve->count() >= self::MIN_OBSERVATIONS_FOR_RISK;
        $riskEquityCurve = $useBarCurve ? $barEquityCurve : $tradeEquityCurve;
        $riskReturns = $this->calculateReturns($riskEquityCurve);

        $totalReturn = bcsub($finalCapital, $initialCapital, 12);
        $totalReturnPercent = Math::percentage($totalReturn, $initialCapital);

        $firstTrade = $closedPositions->first();
        $lastTrade = $closedPositions->last();
        $tradingDays = $firstTrade && $lastTrade && $firstTrade->exitTime && $lastTrade->exitTime
            ? $firstTrade->exitTime->diffInDays($lastTrade->exitTime)
            : 0;

        $winLoss = $this->calculateWinLossMetrics($closedPositions);

        $drawdown = $this->calculateDrawdown($riskEquityCurve);

        $riskMetrics = $this->calculateRiskMetrics(
            $riskReturns,
            $riskEquityCurve,
            $riskFreeRate,
            $tradingDaysPerYear
        );

        $tradeAnalysis = $this->analyzeTrades($closedPositions);

        $cagr = $this->calculateCAGR(
            $initialCapital,
            $finalCapital,
            $tradingDays,
            $tradingDaysPerYear
        );

        $alpha = '0';
        $beta = '0';

        $exposure = $this->calculateExposure($barEquityCurve);

        return [
            'initial_capital' => $initialCapital,
            'final_capital' => $finalCapital,
            'total_return' => $totalReturn,
            'total_return_percent' => $totalReturnPercent,

            'trading_days' => $tradingDays,
            'cagr' => $cagr,

            'total_trades' => $positions->count(),
            'winning_trades' => $winLoss['wins'],
            'losing_trades' => $winLoss['losses'],
            'win_rate' => $winLoss['win_rate'],

            'gross_profit' => $winLoss['gross_profit'],
            'gross_loss' => $winLoss['gross_loss'],
            'net_profit' => $winLoss['net_profit'],
            'profit_factor' => $winLoss['profit_factor'],
            'average_win' => $winLoss['average_win'],
            'average_loss' => $winLoss['average_loss'],
            'largest_win' => $winLoss['largest_win'],
            'largest_loss' => $winLoss['largest_loss'],

            'max_drawdown' => $drawdown['max_drawdown'],
            'max_drawdown_percent' => $drawdown['max_drawdown_percent'],
            'avg_drawdown' => $drawdown['avg_drawdown'],
            'max_drawdown_duration' => $drawdown['max_duration'],
            'avg_drawdown_duration' => $drawdown['avg_duration'],

            'sharpe_ratio' => $riskMetrics['sharpe_ratio'],
            'sortino_ratio' => $riskMetrics['sortino_ratio'],
            'calmar_ratio' => $riskMetrics['calmar_ratio'],
            'volatility' => $riskMetrics['volatility'],

            'alpha' => $alpha,
            'beta' => $beta,

            'time_in_market_percent' => $exposure['time_in_market_percent'],
            'idle_capital_percent' => $exposure['idle_capital_percent'],

            'average_trade_duration' => $tradeAnalysis['avg_duration'],
            'median_trade_duration' => $tradeAnalysis['median_duration'],
            'min_trade_duration' => $tradeAnalysis['min_duration'],
            'max_trade_duration' => $tradeAnalysis['max_duration'],
            'max_consecutive_wins' => $tradeAnalysis['max_consecutive_wins'],
            'max_consecutive_losses' => $tradeAnalysis['max_consecutive_losses'],
            'expectancy' => $tradeAnalysis['expectancy'],

            'long_trades' => $tradeAnalysis['long_trades'],
            'short_trades' => $tradeAnalysis['short_trades'],
            'long_win_rate' => $tradeAnalysis['long_win_rate'],
            'short_win_rate' => $tradeAnalysis['short_win_rate'],
        ];
    }

    /**
     * Build equity curve from positions.
     *
     * @param  Vector<PositionDto>  $positions
     * @return Vector<string> Equity values
     */
    private function buildEquityCurve(Vector $positions, string $initialCapital): Vector
    {
        $equity = new Vector;
        $equity->push($initialCapital);

        $currentEquity = $initialCapital;
        foreach ($positions as $position) {
            $currentEquity = bcadd($currentEquity, $position->realizedPnl, 12);
            $equity->push($currentEquity);
        }

        return $equity;
    }

    /**
     * Calculate period returns from equity curve.
     *
     * Skips periods where equity stayed the same (no open position) —
     * these flat bars contribute no signal and would dilute the mean
     * return to near zero, producing misleadingly negative Sharpe/Sortino
     * on strategies with low market exposure.
     *
     * @param  Vector<string>  $equityCurve
     * @return Vector<string> Non-zero period returns
     */
    private function calculateReturns(Vector $equityCurve): Vector
    {
        $returns = new Vector;

        for ($i = 1; $i < $equityCurve->count(); $i++) {
            $prevEquity = $equityCurve->get($i - 1);
            $currEquity = $equityCurve->get($i);

            if (bccomp($prevEquity, '0', 12) === 0) {
                continue;
            }

            if (bccomp($prevEquity, $currEquity, 12) === 0) {
                continue;
            }

            $return = bcdiv(
                bcsub($currEquity, $prevEquity, 12),
                $prevEquity,
                12
            );
            $returns->push($return);
        }

        return $returns;
    }

    /**
     * Calculate win/loss metrics.
     *
     * @param  Vector<PositionDto>  $positions
     * @return array<string, mixed>
     */
    private function calculateWinLossMetrics(Vector $positions): array
    {
        $wins = 0;
        $losses = 0;
        $grossProfit = '0';
        $grossLoss = '0';
        $largestWin = '0';
        $largestLoss = '0';

        foreach ($positions as $position) {
            $pnl = $position->realizedPnl;
            $comparison = bccomp($pnl, '0', 12);

            if ($comparison > 0) {
                $wins++;
                $grossProfit = bcadd($grossProfit, $pnl, 12);
                if (bccomp($pnl, $largestWin, 12) > 0) {
                    $largestWin = $pnl;
                }
            } elseif ($comparison < 0) {
                $losses++;
                $grossLoss = bcadd($grossLoss, $pnl, 12);
                if (bccomp($pnl, $largestLoss, 12) < 0) {
                    $largestLoss = $pnl;
                }
            }
        }

        $total = $wins + $losses;
        $winRate = $total > 0 ? bcdiv((string) $wins, (string) $total, 6) : '0';
        $netProfit = bcadd($grossProfit, $grossLoss, 12);

        $profitFactor = '0';
        if (bccomp($grossLoss, '0', 12) !== 0) {
            $profitFactor = bcdiv($grossProfit, abs(bcadd('0', $grossLoss, 12)), 4);
        } elseif (bccomp($grossProfit, '0', 12) > 0) {
            $profitFactor = 'INF';
        }

        $averageWin = $wins > 0 ? bcdiv($grossProfit, (string) $wins, 12) : '0';
        $averageLoss = $losses > 0 ? bcdiv($grossLoss, (string) $losses, 12) : '0';

        return [
            'wins' => $wins,
            'losses' => $losses,
            'win_rate' => $winRate,
            'gross_profit' => $grossProfit,
            'gross_loss' => $grossLoss,
            'net_profit' => $netProfit,
            'profit_factor' => $profitFactor,
            'average_win' => $averageWin,
            'average_loss' => $averageLoss,
            'largest_win' => $largestWin,
            'largest_loss' => $largestLoss,
        ];
    }

    /**
     * Calculate drawdown metrics.
     *
     * @param  Vector<string>  $equityCurve
     * @return array<string, mixed>
     */
    private function calculateDrawdown(Vector $equityCurve): array
    {
        $maxDrawdown = '0';
        $maxDrawdownPercent = '0';
        $currentDrawdown = '0';
        $drawdowns = new Vector;
        $drawdownDurations = [];
        $maxDuration = 0;
        $currentDuration = 0;
        $peak = $equityCurve->first();
        $peakIndex = 0;

        for ($i = 1; $i < $equityCurve->count(); $i++) {
            $equity = $equityCurve->get($i);

            if (bccomp($equity, $peak, 12) > 0) {
                $peak = $equity;
                $peakIndex = $i;

                if ($currentDuration > 0) {
                    $drawdowns->push($currentDrawdown);
                    $drawdownDurations[] = $currentDuration;
                    if ($currentDuration > $maxDuration) {
                        $maxDuration = $currentDuration;
                    }
                }
                $currentDrawdown = '0';
                $currentDuration = 0;
            } else {
                $drawdown = bcsub($peak, $equity, 12);
                $drawdownPercent = bccomp($peak, '0', 12) !== 0
                    ? abs(bcdiv($drawdown, $peak, 6))
                    : '0';

                $currentDrawdown = $drawdown;
                $currentDuration++;

                if (bccomp($drawdown, $maxDrawdown, 12) > 0) {
                    $maxDrawdown = $drawdown;
                    $maxDrawdownPercent = $drawdownPercent;
                }
            }
        }

        $avgDrawdown = '0';
        if ($drawdowns->count() > 0) {
            $sum = '0';
            foreach ($drawdowns as $dd) {
                $sum = bcadd($sum, $dd, 12);
            }
            $avgDrawdown = bcdiv($sum, (string) $drawdowns->count(), 12);
        }

        $avgDrawdownDuration = 0;
        if (! empty($drawdownDurations)) {
            $avgDrawdownDuration = (int) (array_sum($drawdownDurations) / count($drawdownDurations));
        }

        return [
            'max_drawdown' => $maxDrawdown,
            'max_drawdown_percent' => $maxDrawdownPercent,
            'avg_drawdown' => $avgDrawdown,
            'max_duration' => $maxDuration,
            'avg_duration' => $avgDrawdownDuration,
        ];
    }

    /**
     * Calculate risk-adjusted metrics using bar-periodic returns.
     *
     * Requires at least MIN_OBSERVATIONS_FOR_RISK data points for statistical significance.
     * The tradingDaysPerYear parameter must match the actual bar frequency
     * (e.g., 8760 for 1h bars, 365 for daily bars, 252 for daily trading bars).
     *
     * @param  Vector<string>  $returns  Bar-periodic returns
     * @param  Vector<string>  $equityCurve
     * @param  string  $riskFreeRate  Annual risk-free rate
     * @param  int  $periodsPerYear  Number of observation periods per year
     * @return array<string, mixed>
     */
    private function calculateRiskMetrics(
        Vector $returns,
        Vector $equityCurve,
        string $riskFreeRate,
        int $periodsPerYear
    ): array {
        if ($returns->count() < self::MIN_OBSERVATIONS_FOR_RISK) {
            return [
                'sharpe_ratio' => '0',
                'sortino_ratio' => '0',
                'calmar_ratio' => '0',
                'volatility' => '0',
            ];
        }

        $avgReturn = Math::mean($returns->toArray(), 12);

        $volatility = Math::standardDeviation($returns->toArray(), 12);

        $annualizedVolatility = bcmul(
            $volatility,
            bcsqrt((string) $periodsPerYear, 12),
            12
        );

        $periodRFR = bcdiv($riskFreeRate, (string) $periodsPerYear, 12);

        $sharpeRatio = '0';
        if (bccomp($annualizedVolatility, self::MIN_ANNUALIZED_VOLATILITY, 12) >= 0) {
            $excessReturn = bcsub($avgReturn, $periodRFR, 12);
            $sharpeRatio = bcdiv($excessReturn, $volatility, 6);
            $sharpeRatio = bcmul(
                $sharpeRatio,
                bcsqrt((string) $periodsPerYear, 6),
                6
            );
        }

        $downsideReturns = new Vector;
        foreach ($returns as $return) {
            if (bccomp($return, '0', 12) < 0) {
                $downsideReturns->push($return);
            }
        }

        $sortinoRatio = '0';
        if ($downsideReturns->count() > 0) {
            $downsideDeviation = Math::standardDeviation($downsideReturns->toArray(), 12);
            $annualizedDownsideDeviation = bcmul(
                $downsideDeviation,
                bcsqrt((string) $periodsPerYear, 12),
                12
            );
            if (bccomp($annualizedDownsideDeviation, self::MIN_ANNUALIZED_VOLATILITY, 12) >= 0) {
                $excessReturn = bcsub($avgReturn, $periodRFR, 12);
                $sortinoRatio = bcdiv($excessReturn, $downsideDeviation, 6);
                $sortinoRatio = bcmul(
                    $sortinoRatio,
                    bcsqrt((string) $periodsPerYear, 6),
                    6
                );
            }
        }

        $calmarRatio = '0';
        $drawdown = $this->calculateDrawdown($equityCurve);
        if (bccomp($drawdown['max_drawdown_percent'], '0', 12) !== 0) {
            $initialEquity = $equityCurve->first();
            $finalEquity = $equityCurve->last();
            if (bccomp($initialEquity, '0', 12) !== 0) {
                $totalReturn = bcdiv(
                    bcsub($finalEquity, $initialEquity, 12),
                    $initialEquity,
                    6
                );
                $calmarRatio = bcdiv(
                    $totalReturn,
                    $drawdown['max_drawdown_percent'],
                    6
                );
            }
        }

        return [
            'sharpe_ratio' => $sharpeRatio,
            'sortino_ratio' => $sortinoRatio,
            'calmar_ratio' => $calmarRatio,
            'volatility' => $annualizedVolatility,
        ];
    }

    /**
     * Calculate CAGR (Compound Annual Growth Rate).
     *
     * Annualizes using calendar days elapsed between first and last trade.
     * A 365.25-day year is used for consistency regardless of bar frequency.
     */
    private function calculateCAGR(
        string $initialCapital,
        string $finalCapital,
        int $tradingDays,
        int $tradingDaysPerYear
    ): string {
        if ($tradingDays <= 0 || bccomp($initialCapital, '0', 12) <= 0) {
            return '0';
        }

        $years = bcdiv((string) $tradingDays, '365.25', 12);

        if (bccomp($years, '0', 12) <= 0) {
            return '0';
        }

        $ratio = bcdiv($finalCapital, $initialCapital, 12);

        if (bccomp($ratio, '0', 12) <= 0) {
            return '0';
        }

        $lnRatio = log((float) $ratio);
        $cagr = exp($lnRatio / (float) $years) - 1;

        return number_format($cagr, 6, '.', '');
    }

    /**
     * Calculate time-in-market and idle capital from bar equity curve.
     *
     * @param  Vector<string>|null  $barEquityCurve
     * @return array{time_in_market_percent: string, idle_capital_percent: string}
     */
    private function calculateExposure(?Vector $barEquityCurve): array
    {
        if ($barEquityCurve === null || $barEquityCurve->count() < 2) {
            return ['time_in_market_percent' => '0', 'idle_capital_percent' => '0'];
        }

        $activeBars = 0;
        $totalBars = $barEquityCurve->count() - 1;

        for ($i = 1; $i < $barEquityCurve->count(); $i++) {
            if (bccomp($barEquityCurve->get($i), $barEquityCurve->get($i - 1), 12) !== 0) {
                $activeBars++;
            }
        }

        $timeInMarket = bcdiv((string) ($activeBars * 100), (string) $totalBars, 4);

        return [
            'time_in_market_percent' => $timeInMarket,
            'idle_capital_percent' => bcsub('100', $timeInMarket, 4),
        ];
    }

    /**
     * Analyze trade patterns.
     *
     * @param  Vector<PositionDto>  $positions
     * @return array<string, mixed>
     */
    private function analyzeTrades(Vector $positions): array
    {
        $totalDuration = 0;
        $maxConsecutiveWins = 0;
        $maxConsecutiveLosses = 0;
        $currentConsecutiveWins = 0;
        $currentConsecutiveLosses = 0;
        $longTrades = 0;
        $shortTrades = 0;
        $longWins = 0;
        $shortWins = 0;
        $durations = [];

        foreach ($positions as $position) {
            $duration = (int) $position->entryTime->diffInSeconds($position->exitTime);
            $totalDuration += $duration;
            $durations[] = $duration;

            if ($position->direction === 'long') {
                $longTrades++;
                if (bccomp($position->realizedPnl, '0', 12) > 0) {
                    $longWins++;
                }
            } else {
                $shortTrades++;
                if (bccomp($position->realizedPnl, '0', 12) > 0) {
                    $shortWins++;
                }
            }

            $comparison = bccomp($position->realizedPnl, '0', 12);
            if ($comparison > 0) {
                $currentConsecutiveWins++;
                $currentConsecutiveLosses = 0;
                if ($currentConsecutiveWins > $maxConsecutiveWins) {
                    $maxConsecutiveWins = $currentConsecutiveWins;
                }
            } elseif ($comparison < 0) {
                $currentConsecutiveLosses++;
                $currentConsecutiveWins = 0;
                if ($currentConsecutiveLosses > $maxConsecutiveLosses) {
                    $maxConsecutiveLosses = $currentConsecutiveLosses;
                }
            }
        }

        $avgDuration = $positions->count() > 0
            ? (int) ($totalDuration / $positions->count())
            : 0;

        $medianDuration = 0;
        $minDuration = 0;
        $maxDuration = 0;
        if (! empty($durations)) {
            sort($durations);
            $minDuration = $durations[0];
            $maxDuration = $durations[count($durations) - 1];
            $mid = (int) floor(count($durations) / 2);
            if (count($durations) % 2 === 0) {
                $medianDuration = (int) (($durations[$mid - 1] + $durations[$mid]) / 2);
            } else {
                $medianDuration = $durations[$mid];
            }
        }

        $winLoss = $this->calculateWinLossMetrics($positions);
        $lossRate = bcsub('1', $winLoss['win_rate'], 6);
        $avgLossAbs = abs($winLoss['average_loss']);
        $expectancy = bcsub(
            bcmul($winLoss['win_rate'], $winLoss['average_win'], 12),
            bcmul($lossRate, $avgLossAbs, 12),
            12
        );

        return [
            'avg_duration' => $avgDuration,
            'median_duration' => $medianDuration,
            'min_duration' => $minDuration,
            'max_duration' => $maxDuration,
            'max_consecutive_wins' => $maxConsecutiveWins,
            'max_consecutive_losses' => $maxConsecutiveLosses,
            'expectancy' => $expectancy,
            'long_trades' => $longTrades,
            'short_trades' => $shortTrades,
            'long_win_rate' => $longTrades > 0 ? bcdiv((string) $longWins, (string) $longTrades, 6) : '0',
            'short_win_rate' => $shortTrades > 0 ? bcdiv((string) $shortWins, (string) $shortTrades, 6) : '0',
        ];
    }

    /**
     * Get empty statistics array.
     *
     * @return array<string, mixed>
     */
    private function getEmptyStatistics(): array
    {
        return [
            'initial_capital' => '0',
            'final_capital' => '0',
            'total_return' => '0',
            'total_return_percent' => '0',
            'trading_days' => 0,
            'cagr' => '0',
            'total_trades' => 0,
            'winning_trades' => 0,
            'losing_trades' => 0,
            'win_rate' => '0',
            'gross_profit' => '0',
            'gross_loss' => '0',
            'net_profit' => '0',
            'profit_factor' => '0',
            'average_win' => '0',
            'average_loss' => '0',
            'largest_win' => '0',
            'largest_loss' => '0',
            'max_drawdown' => '0',
            'max_drawdown_percent' => '0',
            'avg_drawdown' => '0',
            'max_drawdown_duration' => 0,
            'avg_drawdown_duration' => 0,
            'sharpe_ratio' => '0',
            'sortino_ratio' => '0',
            'calmar_ratio' => '0',
            'volatility' => '0',
            'alpha' => '0',
            'beta' => '0',
            'time_in_market_percent' => '0',
            'idle_capital_percent' => '0',
            'average_trade_duration' => 0,
            'median_trade_duration' => 0,
            'min_trade_duration' => 0,
            'max_trade_duration' => 0,
            'max_consecutive_wins' => 0,
            'max_consecutive_losses' => 0,
            'expectancy' => '0',
            'long_trades' => 0,
            'short_trades' => 0,
            'long_win_rate' => '0',
            'short_win_rate' => '0',
        ];
    }
}
