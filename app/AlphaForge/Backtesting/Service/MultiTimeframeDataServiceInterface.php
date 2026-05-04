<?php

namespace App\AlphaForge\Backtesting\Service;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Model\OhlcvSeries;

interface MultiTimeframeDataServiceInterface
{
    /**
     * Resample OHLCV data to a higher timeframe.
     *
     * @param  OhlcvSeries  $source  The source OHLCV data
     * @param  TimeframeEnum  $targetTimeframe  The target timeframe
     * @return OhlcvSeries The resampled OHLCV data
     */
    public function resample(OhlcvSeries $source, TimeframeEnum $targetTimeframe): OhlcvSeries;

    /**
     * Aggregate multiple OHLCV series into a multi-timeframe container.
     *
     * @param  OhlcvSeries  $baseSeries  The base (lowest) timeframe series
     * @param  array<TimeframeEnum>  $higherTimeframes  Higher timeframes to generate
     * @return array<TimeframeEnum, OhlcvSeries> Map of timeframe to OHLCV series
     */
    public function aggregate(OhlcvSeries $baseSeries, array $higherTimeframes): array;
}
