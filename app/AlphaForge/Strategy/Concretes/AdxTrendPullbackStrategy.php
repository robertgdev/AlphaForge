<?php

namespace App\AlphaForge\Strategy\Concretes;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Strategy\Attribute\AsStrategy;
use App\AlphaForge\Strategy\Attribute\Input;

#[AsStrategy(
    alias: 'adx_trend_pullback',
    name: 'ADX Trend Pullback',
    description: 'Trend-following pullback strategy. Uses ADX to confirm trending market, enters on RSI pullback/dip within the trend, and exits when the trend weakens or RSI reaches overbought.',
    timeframe: TimeframeEnum::H1,
    requiredMarketData: [TimeframeEnum::H1]
)]
class AdxTrendPullbackStrategy extends BaseSignalStrategy
{
    #[Input(
        description: 'ADX lookback period',
        min: 10,
        max: 30,
        step: 2
    )]
    private int $adxPeriod = 14;

    #[Input(
        description: 'ADX trend confirmation threshold',
        min: 15,
        max: 40,
        step: 5
    )]
    private float $adxThreshold = 25.0;

    #[Input(
        description: 'RSI lookback period',
        min: 5,
        max: 30,
        step: 1
    )]
    private int $rsiPeriod = 14;

    #[Input(
        description: 'RSI pullback buy zone (must be below this)',
        min: 20,
        max: 50,
        step: 5
    )]
    private float $rsiPullbackLevel = 40.0;

    #[Input(
        description: 'RSI overbought exit level',
        min: 60,
        max: 85,
        step: 5
    )]
    private float $rsiOverboughtLevel = 70.0;

    #[Input(
        description: 'Stop loss percentage from entry price',
        min: 0.5,
        max: 20.0,
        step: 0.5
    )]
    private float $stopLossPercent = 4.0;

    #[Input(
        description: 'Take profit percentage from entry price',
        min: 1.0,
        max: 50.0,
        step: 2.0
    )]
    private float $takeProfitPercent = 8.0;

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
        return max($this->adxPeriod, $this->rsiPeriod);
    }

    protected function strategyName(): string
    {
        return 'ADX Trend Pullback';
    }

    protected function computeSignals(OhlcvSeries $ohlcv): void
    {
        $adx = $this->ctx->adx($this->adxPeriod);
        $rsi = $this->ctx->rsi($this->rsiPeriod);

        $this->entryCondition = $adx->isAbove($this->adxThreshold)
            ->and($rsi->isBelow($this->rsiPullbackLevel))
            ->and($rsi->isRising());

        $this->exitCondition = $adx->isBelow($this->adxThreshold)
            ->or($rsi->isAbove($this->rsiOverboughtLevel));
    }
}
