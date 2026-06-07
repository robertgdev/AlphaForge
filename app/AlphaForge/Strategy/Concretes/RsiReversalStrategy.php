<?php

namespace App\AlphaForge\Strategy\Concretes;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Strategy\Attribute\AsStrategy;
use App\AlphaForge\Strategy\Attribute\Input;

#[AsStrategy(
    alias: 'rsi_reversal',
    name: 'RSI Reversal',
    description: 'RSI overbought/oversold reversal strategy. Buys when RSI recovers from oversold, sells when RSI retreats from overbought.',
    timeframe: TimeframeEnum::H1,
    requiredMarketData: [TimeframeEnum::H1]
)]
class RsiReversalStrategy extends BaseSignalStrategy
{
    #[Input(
        description: 'RSI lookback period',
        min: 5,
        max: 30,
        step: 1
    )]
    private int $rsiPeriod = 14;

    #[Input(
        description: 'RSI oversold threshold for buy signal',
        min: 10,
        max: 40,
        step: 5
    )]
    private float $oversoldThreshold = 30.0;

    #[Input(
        description: 'RSI overbought threshold for sell signal',
        min: 60,
        max: 90,
        step: 5
    )]
    private float $overboughtThreshold = 70.0;

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
        return $this->rsiPeriod;
    }

    protected function strategyName(): string
    {
        return 'RSI Reversal';
    }

    protected function computeSignals(OhlcvSeries $ohlcv): void
    {
        $rsi = $this->ctx->rsi($this->rsiPeriod);

        $this->entryCondition = $rsi->isBelow($this->oversoldThreshold)->and($rsi->isRising());
        $this->exitCondition = $rsi->isAbove($this->overboughtThreshold)->and($rsi->isFalling());
    }
}
