<?php

namespace App\AlphaForge\Backtesting\Dto;

use App\AlphaForge\Common\Enum\TimeframeEnum;

class BacktestConfiguration
{
    /**
     * @param  string[]  $symbols
     * @param  array<string, mixed>  $strategyInputs
     * @param  array<string, mixed>  $commissionConfig
     * @param  TimeframeEnum|null  $executionTimeframe  Lower timeframe for order/position execution (null = same as signal timeframe)
     */
    public function __construct(
        public string $strategyAlias,
        public array $symbols,
        public TimeframeEnum $timeframe,
        public string $dataSourceExchangeId,
        public float|string $initialCapital,
        public string $stakeCurrency,
        public array $strategyInputs = [],
        public array $commissionConfig = ['type' => 'percentage', 'rate' => 0.001],
        public ?\DateTimeImmutable $startDate = null,
        public ?\DateTimeImmutable $endDate = null,
        public ?TimeframeEnum $executionTimeframe = null
    ) {}
}
