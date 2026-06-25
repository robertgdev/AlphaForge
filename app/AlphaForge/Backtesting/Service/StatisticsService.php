<?php

namespace App\AlphaForge\Backtesting\Service;

use RobertGDev\AlphaforgeStatistics\Statistics\StatisticsService as PackageStatisticsService;
use RobertGDev\AlphaforgeStatistics\Statistics\StatisticsServiceInterface as PackageStatisticsServiceInterface;
use App\AlphaForge\Backtesting\Adapter\TradeInputAdapter;
use Ds\Vector;

readonly class StatisticsService implements StatisticsServiceInterface
{
    private PackageStatisticsServiceInterface $packageService;

    public function __construct()
    {
        $this->packageService = new PackageStatisticsService;
    }

    public function calculate(
        Vector $positions,
        string $initialCapital,
        string $finalCapital,
        ?string $riskFreeRate = null,
        int $tradingDaysPerYear = 252,
        ?Vector $barEquityCurve = null,
    ): array {
        return $this->packageService->calculate(
            TradeInputAdapter::fromPositions($positions),
            $initialCapital,
            $finalCapital,
            $riskFreeRate,
            $tradingDaysPerYear,
            $barEquityCurve,
        );
    }
}