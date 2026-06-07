<?php

namespace App\AlphaForge\Strategy\Concretes;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Strategy\Attribute\AsStrategy;
use App\AlphaForge\Strategy\Attribute\Input;

#[AsStrategy(
    alias: 'ema_crossover',
    name: 'EMA Crossover',
    description: 'Exponential Moving Average crossover strategy. More responsive than SMA crossover — buys when fast EMA crosses above slow EMA, sells when it crosses below.',
    timeframe: TimeframeEnum::H1,
    requiredMarketData: [TimeframeEnum::H1]
)]
class EmaCrossoverStrategy extends BaseSignalStrategy
{
    #[Input(
        description: 'Fast EMA period (shorter timeframe)',
        min: 3,
        max: 30,
        step: 2
    )]
    private int $fastPeriod = 10;

    #[Input(
        description: 'Slow EMA period (longer timeframe)',
        min: 15,
        max: 100,
        step: 5
    )]
    private int $slowPeriod = 30;

    #[Input(
        description: 'Stop loss percentage from entry price',
        min: 0.5,
        max: 20.0,
        step: 0.5
    )]
    private float $stopLossPercent = 3.0;

    #[Input(
        description: 'Take profit percentage from entry price',
        min: 1.0,
        max: 50.0,
        step: 2.0
    )]
    private float $takeProfitPercent = 6.0;

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
        return 'EMA Crossover';
    }

    protected function computeSignals(OhlcvSeries $ohlcv): void
    {
        $fast = $this->ctx->ema($this->fastPeriod);
        $slow = $this->ctx->ema($this->slowPeriod);

        $this->entryCondition = $fast->crossesAbove($slow);
        $this->exitCondition = $fast->crossesBelow($slow);
    }
}
