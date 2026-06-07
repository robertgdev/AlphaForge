<?php

namespace App\AlphaForge\Backtesting\Service;

use App\AlphaForge\Common\Model\Series;

interface SeriesMetricServiceInterface
{
    /**
     * Calculate various metrics for a time series.
     *
     * @param  Series  $series  The time series data
     * @return array<string, mixed> Calculated metrics
     */
    public function calculate(Series $series): array;

    /**
     * Calculate annualized Sharpe ratio from a flat array of periodic returns.
     *
     * @param  array<int, float>  $returns  Trade or period returns
     */
    public function sharpeRatioFromReturns(array $returns): float;

    /**
     * Calculate Sortino ratio from a flat array of periodic returns.
     *
     * Uses only downside deviation (returns below zero) in the denominator.
     *
     * @param  array<int, float>  $returns  Trade or period returns
     */
    public function sortinoRatioFromReturns(array $returns): float;

    /**
     * Calculate maximum drawdown from an array of returns.
     *
     * @param  array<int, float>  $returns  Returns used to build a cumulative equity curve
     */
    public function maxDrawdownFromReturns(array $returns): float;

    /**
     * Calculate performance stability — fraction of trading days with positive net return.
     *
     * @param  array<int, array{timestamp: int, pnl: float}>  $trades
     */
    public function performanceStabilityFromTrades(array $trades): float;

    /**
     * Calculate basic win/loss statistics from an array of returns.
     *
     * @param  array<int, float>  $returns
     * @return array{total_trades: int, winning_trades: int, losing_trades: int, win_rate: float, expected_value: float}
     */
    public function tradeWinLossStats(array $returns): array;
}
