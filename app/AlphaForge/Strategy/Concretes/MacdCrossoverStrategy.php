<?php

namespace App\AlphaForge\Strategy\Concretes;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Strategy\Attribute\AsStrategy;
use App\AlphaForge\Strategy\Attribute\Input;

#[AsStrategy(
    alias: 'macd_crossover',
    name: 'MACD Crossover',
    description: 'Classic MACD crossover strategy. Buys when MACD line crosses above signal line, sells when it crosses below.',
    timeframe: TimeframeEnum::H1,
    requiredMarketData: [TimeframeEnum::H1]
)]
class MacdCrossoverStrategy extends BaseSignalStrategy
{
    #[Input(
        description: 'MACD fast EMA period',
        min: 5,
        max: 20,
        step: 1
    )]
    private int $fastPeriod = 12;

    #[Input(
        description: 'MACD slow EMA period',
        min: 15,
        max: 40,
        step: 1
    )]
    private int $slowPeriod = 26;

    #[Input(
        description: 'MACD signal line EMA period',
        min: 5,
        max: 15,
        step: 1
    )]
    private int $signalPeriod = 9;

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
        return max($this->slowPeriod + $this->signalPeriod, 50);
    }

    protected function strategyName(): string
    {
        return 'MACD Crossover';
    }

    protected function computeSignals(OhlcvSeries $ohlcv): void
    {
        $macd = $this->ctx->macd($this->fastPeriod, $this->slowPeriod, $this->signalPeriod);
        $macdLine = $macd->get('macd');
        $signalLine = $macd->get('signal');

        $this->entryCondition = $macdLine->crossesAbove($signalLine);
        $this->exitCondition = $macdLine->crossesBelow($signalLine);
    }
}
