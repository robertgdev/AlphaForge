<?php

namespace App\AlphaForge\Backtesting\Service;

use RobertGDev\AlphaforgeStatistics\Series\SeriesMetricService as PackageSeriesMetricService;
use RobertGDev\AlphaforgeStatistics\Series\SeriesMetricServiceInterface as PackageSeriesMetricServiceInterface;
use App\AlphaForge\Common\Model\Series;

readonly class SeriesMetricService implements SeriesMetricServiceInterface
{
    private PackageSeriesMetricServiceInterface $packageService;

    public function __construct()
    {
        $this->packageService = new PackageSeriesMetricService;
    }

    public function calculate(Series $series): array
    {
        return $this->packageService->calculate($series->getVector()->toArray());
    }

    public function sharpeRatioFromReturns(array $returns): float
    {
        return $this->packageService->sharpeRatioFromReturns($returns);
    }

    public function sortinoRatioFromReturns(array $returns): float
    {
        return $this->packageService->sortinoRatioFromReturns($returns);
    }

    public function maxDrawdownFromReturns(array $returns): float
    {
        return $this->packageService->maxDrawdownFromReturns($returns);
    }

    public function performanceStabilityFromTrades(array $trades): float
    {
        return $this->packageService->performanceStabilityFromTrades($trades);
    }

    public function tradeWinLossStats(array $returns): array
    {
        return $this->packageService->tradeWinLossStats($returns);
    }
}