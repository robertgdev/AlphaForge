<?php

namespace App\AlphaForge\Backtesting\Service;

use App\AlphaForge\Order\Dto\PositionDto;
use Ds\Vector;

interface StatisticsServiceInterface
{
    /**
     * Calculate comprehensive backtest statistics.
     *
     * @param  Vector<PositionDto>  $positions  All closed positions
     * @param  string  $initialCapital  Starting capital
     * @param  string  $finalCapital  Ending capital
     * @param  string  $riskFreeRate  Annual risk-free rate (e.g., "0.02" for 2%)
     * @param  int  $tradingDaysPerYear  Number of periods per year for the bar data (e.g., 8760 for 1h, 365 for 1d)
     * @param  Vector<string>|null  $barEquityCurve  Bar-level equity curve (optional; falls back to trade-level if null)
     * @return array<string, mixed> Comprehensive statistics array
     */
    public function calculate(
        Vector $positions,
        string $initialCapital,
        string $finalCapital,
        ?string $riskFreeRate = null,
        int $tradingDaysPerYear = 252,
        ?Vector $barEquityCurve = null,
    ): array;
}