<?php

namespace App\AlphaForge\Strategy\Concretes;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Strategy\Attribute\AsStrategy;
use App\AlphaForge\Strategy\Attribute\Input;

#[AsStrategy(
    alias: 'bb_reversal',
    name: 'Bollinger Band Reversal',
    description: 'Mean reversion strategy using Bollinger Bands. Buys when price breaks below lower band, sells when price crosses back above middle band.',
    timeframe: TimeframeEnum::H1,
    requiredMarketData: [TimeframeEnum::H1]
)]
class BollingerBandReversalStrategy extends BaseSignalStrategy
{
    #[Input(
        description: 'Bollinger Band period',
        min: 10,
        max: 50,
        step: 5
    )]
    private int $period = 20;

    #[Input(
        description: 'Standard deviation multiplier',
        min: 1.0,
        max: 4.0,
        step: 0.5
    )]
    private float $stdDev = 2.0;

    #[Input(
        description: 'Stop loss percentage from entry price',
        min: 0.5,
        max: 20.0,
        step: 0.5
    )]
    private float $stopLossPercent = 2.0;

    #[Input(
        description: 'Take profit percentage from entry price',
        min: 1.0,
        max: 50.0,
        step: 2.0
    )]
    private float $takeProfitPercent = 4.0;

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
        return $this->period;
    }

    protected function strategyName(): string
    {
        return 'Bollinger Band Reversal';
    }

    protected function computeSignals(OhlcvSeries $ohlcv): void
    {
        $close = $this->ctx->priceSeries('close');
        $bands = $this->ctx->bbands($this->period, $this->stdDev, $this->stdDev);
        $lowerBand = $bands->get('lower');
        $middleBand = $bands->get('middle');

        $this->entryCondition = $close->crossesBelow($lowerBand);
        $this->exitCondition = $close->crossesAbove($middleBand);
    }
}
