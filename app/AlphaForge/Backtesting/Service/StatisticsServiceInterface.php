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
     * @param  int  $tradingDaysPerYear  Number of trading days per year (default: 252)
     * @return array<string, mixed> Comprehensive statistics array
     */
    public function calculate(
        Vector $positions,
        string $initialCapital,
        string $finalCapital,
        string $riskFreeRate = '0.02',
        int $tradingDaysPerYear = 252
    ): array;
}
