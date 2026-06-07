<?php

namespace App\AlphaForge\Strategy\Dto;

use App\AlphaForge\Common\Model\MultiTimeframeOhlcvSeries;
use App\AlphaForge\Common\Model\OhlcvSeries;

final readonly class InitializeData
{
    public function __construct(
        public OhlcvSeries $ohlcv,
        public string $initialCapital = '10000',
        public ?MultiTimeframeOhlcvSeries $multiTimeframe = null,
    ) {}
}
