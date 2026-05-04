<?php

namespace App\AlphaForge\Backtesting\Service;

use App\AlphaForge\Common\Util\Math;
use App\AlphaForge\Order\Dto\PositionDto;
use Ds\Vector;

readonly class StatisticsService implements StatisticsServiceInterface
{
    /**
     * Calculate comprehensive backtest statistics.
     *
     * @param  Vector<PositionDto>  $positions  All closed positions
     * @param  string  $initialCapital  Starting capital
     * @param  string  $finalCapital  Ending capital
     * @param  string  $riskFreeRate  Annual risk-free rate (e.g., "0.02" for 2%)
     * @param  int  $tradingDaysPerYear  Number of trading days per year (default: 252)
     * @return array<string, mixed> Comprehensive statistics array
     */
    public function calculate(
        Vector $positions,
        string $initialCapital,
        string $finalCapital,
        string $riskFreeRate = '0.02',
        int $tradingDaysPerYear = 252
    ): array {
        // Filter to only closed positions (with exitTime set)
        $closedPositions = $positions->filter(fn (PositionDto $p) => $p->exitTime !== null);

        if ($closedPositions->isEmpty()) {
            return $this->getEmptyStatistics();
        }

        // Calculate equity curve
        $equityCurve = $this->buildEquityCurve($closedPositions, $initialCapital);

        // Calculate returns series
        $returns = $this->calculateReturns($equityCurve);

        // Basic metrics
        $totalReturn = bcsub($finalCapital, $initialCapital, 12);
        $totalReturnPercent = Math::percentage($totalReturn, $initialCapital);

        // Time-based metrics
        $firstTrade = $closedPositions->first();
        $lastTrade = $closedPositions->last();
        $tradingDays = $firstTrade && $lastTrade && $firstTrade->exitTime && $lastTrade->exitTime
            ? $firstTrade->exitTime->diffInDays($lastTrade->exitTime)
            : 0;

        // Win/Loss metrics
        $winLoss = $this->calculateWinLossMetrics($closedPositions);

        // Drawdown analysis
        $drawdown = $this->calculateDrawdown($equityCurve);

        // Risk metrics
        $riskMetrics = $this->calculateRiskMetrics(
            $returns,
            $equityCurve,
            $riskFreeRate,
            $tradingDaysPerYear
        );

        // Trade analysis
        $tradeAnalysis = $this->analyzeTrades($closedPositions);

        // CAGR calculation
        $cagr = $this->calculateCAGR(
            $initialCapital,
            $finalCapital,
            $tradingDays,
            $tradingDaysPerYear
        );

        // Alpha and Beta (if we had benchmark data)
        $alpha = '0';
        $beta = '0';

        return [
            // Capital metrics
            'initial_capital' => $initialCapital,
            'final_capital' => $finalCapital,
            'total_return' => $totalReturn,
            'total_return_percent' => $totalReturnPercent,

            // Time metrics
            'trading_days' => $tradingDays,
            'cagr' => $cagr,

            // Trade count metrics
            'total_trades' => $positions->count(),
            'winning_trades' => $winLoss['wins'],
            'losing_trades' => $winLoss['losses'],
            'win_rate' => $winLoss['win_rate'],

            // Profit metrics
            'gross_profit' => $winLoss['gross_profit'],
            'gross_loss' => $winLoss['gross_loss'],
            'net_profit' => $winLoss['net_profit'],
            'profit_factor' => $winLoss['profit_factor'],
            'average_win' => $winLoss['average_win'],
            'average_loss' => $winLoss['average_loss'],
            'largest_win' => $winLoss['largest_win'],
            'largest_loss' => $winLoss['largest_loss'],

            // Risk metrics
            'max_drawdown' => $drawdown['max_drawdown'],
            'max_drawdown_percent' => $drawdown['max_drawdown_percent'],
            'avg_drawdown' => $drawdown['avg_drawdown'],
            'max_drawdown_duration' => $drawdown['max_duration'],

            // Risk-adjusted metrics
            'sharpe_ratio' => $riskMetrics['sharpe_ratio'],
            'sortino_ratio' => $riskMetrics['sortino_ratio'],
            'calmar_ratio' => $riskMetrics['calmar_ratio'],
            'volatility' => $riskMetrics['volatility'],

            // Alpha/Beta (placeholder - requires benchmark data)
            'alpha' => $alpha,
            'beta' => $beta,

            // Trade analysis
            'average_trade_duration' => $tradeAnalysis['avg_duration'],
            'max_consecutive_wins' => $tradeAnalysis['max_consecutive_wins'],
            'max_consecutive_losses' => $tradeAnalysis['max_consecutive_losses'],
            'expectancy' => $tradeAnalysis['expectancy'],

            // Per-side analysis
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
     * @param  Vector<string>  $equityCurve
     * @return Vector<string> Returns
     */
    private function calculateReturns(Vector $equityCurve): Vector
    {
        $returns = new Vector;

        for ($i = 1; $i < $equityCurve->count(); $i++) {
            $prevEquity = $equityCurve->get($i - 1);
            $currEquity = $equityCurve->get($i);

            if (bccomp($prevEquity, '0', 12) === 0) {
                $returns->push('0');
            } else {
                $return = bcdiv(
                    bcsub($currEquity, $prevEquity, 12),
                    $prevEquity,
                    12
                );
                $returns->push($return);
            }
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

        // Profit factor (gross profit / abs(gross loss))
        $profitFactor = '0';
        if (bccomp($grossLoss, '0', 12) !== 0) {
            $profitFactor = bcdiv($grossProfit, abs(bcadd('0', $grossLoss, 12)), 4);
        } elseif (bccomp($grossProfit, '0', 12) > 0) {
            $profitFactor = 'INF';
        }

        // Averages
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
        $drawdownStart = 0;
        $maxDuration = 0;
        $currentDuration = 0;
        $peak = $equityCurve->first();
        $peakIndex = 0;

        for ($i = 1; $i < $equityCurve->count(); $i++) {
            $equity = $equityCurve->get($i);

            if (bccomp($equity, $peak, 12) > 0) {
                // New peak
                $peak = $equity;
                $peakIndex = $i;

                if ($currentDuration > 0) {
                    $drawdowns->push($currentDrawdown);
                    if ($currentDuration > $maxDuration) {
                        $maxDuration = $currentDuration;
                    }
                }
                $currentDrawdown = '0';
                $currentDuration = 0;
            } else {
                // In drawdown
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

        // Calculate average drawdown
        $avgDrawdown = '0';
        if ($drawdowns->count() > 0) {
            $sum = '0';
            foreach ($drawdowns as $dd) {
                $sum = bcadd($sum, $dd, 12);
            }
            $avgDrawdown = bcdiv($sum, (string) $drawdowns->count(), 12);
        }

        return [
            'max_drawdown' => $maxDrawdown,
            'max_drawdown_percent' => $maxDrawdownPercent,
            'avg_drawdown' => $avgDrawdown,
            'max_duration' => $maxDuration,
        ];
    }

    /**
     * Calculate risk-adjusted metrics.
     *
     * @param  Vector<string>  $returns
     * @param  Vector<string>  $equityCurve
     * @return array<string, mixed>
     */
    private function calculateRiskMetrics(
        Vector $returns,
        Vector $equityCurve,
        string $riskFreeRate,
        int $tradingDaysPerYear
    ): array {
        if ($returns->isEmpty()) {
            return [
                'sharpe_ratio' => '0',
                'sortino_ratio' => '0',
                'calmar_ratio' => '0',
                'volatility' => '0',
            ];
        }

        // Calculate average return
        $avgReturn = Math::mean($returns->toArray(), 12);

        // Calculate volatility (standard deviation of returns)
        $volatility = Math::standardDeviation($returns->toArray(), 12);

        // Annualized volatility
        $annualizedVolatility = bcmul(
            $volatility,
            bcsqrt((string) $tradingDaysPerYear, 12),
            12
        );

        // Daily risk-free rate
        $dailyRiskFreeRate = bcdiv($riskFreeRate, (string) $tradingDaysPerYear, 12);

        // Sharpe Ratio = (Average Return - Risk Free Rate) / Volatility
        $sharpeRatio = '0';
        if (bccomp($volatility, '0', 12) !== 0) {
            $excessReturn = bcsub($avgReturn, $dailyRiskFreeRate, 12);
            $sharpeRatio = bcdiv($excessReturn, $volatility, 6);

            // Annualize
            $sharpeRatio = bcmul(
                $sharpeRatio,
                bcsqrt((string) $tradingDaysPerYear, 6),
                6
            );
        }

        // Sortino Ratio (uses downside deviation)
        $downsideReturns = new Vector;
        foreach ($returns as $return) {
            if (bccomp($return, '0', 12) < 0) {
                $downsideReturns->push($return);
            }
        }

        $sortinoRatio = '0';
        if ($downsideReturns->count() > 0) {
            $downsideDeviation = Math::standardDeviation($downsideReturns->toArray(), 12);
            if (bccomp($downsideDeviation, '0', 12) !== 0) {
                $excessReturn = bcsub($avgReturn, $dailyRiskFreeRate, 12);
                $sortinoRatio = bcdiv($excessReturn, $downsideDeviation, 6);
                $sortinoRatio = bcmul(
                    $sortinoRatio,
                    bcsqrt((string) $tradingDaysPerYear, 6),
                    6
                );
            }
        }

        // Calmar Ratio (CAGR / Max Drawdown)
        $calmarRatio = '0';
        $drawdown = $this->calculateDrawdown($equityCurve);
        if (bccomp($drawdown['max_drawdown_percent'], '0', 12) !== 0) {
            // We need CAGR here, but we'll calculate it separately
            // For now, use total return / max drawdown
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

        // CAGR = (Final / Initial)^(365/tradingDays) - 1
        $years = bcdiv((string) $tradingDays, (string) $tradingDaysPerYear, 12);

        if (bccomp($years, '0', 12) <= 0) {
            return '0';
        }

        $ratio = bcdiv($finalCapital, $initialCapital, 12);

        // Use natural log and exp for power calculation
        // CAGR = exp(ln(ratio) / years) - 1
        $lnRatio = log((float) $ratio);
        $cagr = exp($lnRatio / (float) $years) - 1;

        return number_format($cagr, 6, '.', '');
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

        foreach ($positions as $position) {
            // Duration
            $duration = $position->entryTime->diffInSeconds($position->exitTime);
            $totalDuration += $duration;

            // Direction
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

            // Consecutive wins/losses
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

        // Average duration in seconds
        $avgDuration = $positions->count() > 0
            ? (int) ($totalDuration / $positions->count())
            : 0;

        // Expectancy = (Win Rate * Avg Win) - (Loss Rate * Abs(Avg Loss))
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
            'sharpe_ratio' => '0',
            'sortino_ratio' => '0',
            'calmar_ratio' => '0',
            'volatility' => '0',
            'alpha' => '0',
            'beta' => '0',
            'average_trade_duration' => 0,
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
