<?php

namespace App\AlphaForge\Backtesting\Optimization;

use App\AlphaForge\Common\Enum\TimeframeEnum;

readonly class MarketDataSnapshot
{
    /**
     * @param array<string, array{data: array, symbol: string, timeframe: TimeframeEnum}> $signalData
     * @param array<string, array{data: array, symbol: string, timeframe: TimeframeEnum}>|null $executionData
     */
    public function __construct(
        public array $signalData,
        public ?array $executionData = null,
    ) {}
}
