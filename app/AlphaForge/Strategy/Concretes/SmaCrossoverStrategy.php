<?php

namespace App\AlphaForge\Strategy\Concretes;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Strategy\Attribute\AsStrategy;
use App\AlphaForge\Strategy\Attribute\Input;

#[AsStrategy(
    alias: 'sma_crossover',
    name: 'SMA Crossover',
    description: 'Simple Moving Average crossover strategy. Buys when fast SMA crosses above slow SMA, sells when it crosses below.',
    timeframe: TimeframeEnum::H1,
    requiredMarketData: [TimeframeEnum::H1]
)]
class SmaCrossoverStrategy extends BaseSignalStrategy
{
    #[Input(
        description: 'Fast SMA period (shorter timeframe)',
        min: 5,
        max: 50,
        step: 5
    )]
    private int $fastPeriod = 10;

    #[Input(
        description: 'Slow SMA period (longer timeframe)',
        min: 20,
        max: 200,
        step: 10
    )]
    private int $slowPeriod = 50;

    #[Input(
        description: 'Stop loss percentage from entry price',
        min: 0.5,
        max: 20.0,
        step: 0.5
    )]
    private float $stopLossPercent = 5.0;

    #[Input(
        description: 'Take profit percentage from entry price',
        min: 1.0,
        max: 50.0,
        step: 2.0
    )]
    private float $takeProfitPercent = 10.0;

    protected function stopLossPercent(): float
    {
        return $this->stopLossPercent;
    }

    protected function takeProfitPercent(): float
    {
        return $this->takeProfitPercent;
    }

    protected function minBars(): int
    {
        return max($this->fastPeriod, $this->slowPeriod);
    }

    protected function strategyName(): string
    {
        return 'SMA Crossover';
    }

    protected function computeSignals(OhlcvSeries $ohlcv): void
    {
        $fast = $this->ctx->sma($this->fastPeriod);
        $slow = $this->ctx->sma($this->slowPeriod);

        $this->entryCondition = $fast->crossesAbove($slow);
        $this->exitCondition = $fast->crossesBelow($slow);
    }
}
