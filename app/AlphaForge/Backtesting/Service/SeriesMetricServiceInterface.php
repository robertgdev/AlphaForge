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
}
