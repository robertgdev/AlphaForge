<?php

namespace App\AlphaForge\Strategy\Dto;

use App\AlphaForge\Backtesting\Model\BacktestCursor;
use App\AlphaForge\Common\Model\MultiTimeframeOhlcvSeries;
use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Order\Model\PortfolioManager;

final readonly class BarData
{
    public function __construct(
        public BacktestCursor $cursor,
        public OhlcvSeries $ohlcv,
        public PortfolioManager $portfolio,
        public string $symbol,
        public ?MultiTimeframeOhlcvSeries $multiTimeframe = null,
    ) {}
}
