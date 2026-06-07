<?php

namespace App\AlphaForge\Strategy\Concretes;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Strategy\Attribute\AsStrategy;
use App\AlphaForge\Strategy\Attribute\Input;

#[AsStrategy(
    alias: 'stoch_reversal',
    name: 'Stochastic Reversal',
    description: 'Stochastic oscillator crossover strategy. Buys when %K crosses above %D, sells when %K crosses below %D.',
    timeframe: TimeframeEnum::H1,
    requiredMarketData: [TimeframeEnum::H1]
)]
class StochasticStrategy extends BaseSignalStrategy
{
    #[Input(
        description: 'Stochastic %K period (fast)',
        min: 5,
        max: 30,
        step: 1
    )]
    private int $fastKPeriod = 14;

    #[Input(
        description: 'Stochastic %K smoothing period',
        min: 1,
        max: 10,
        step: 1
    )]
    private int $slowKPeriod = 3;

    #[Input(
        description: 'Stochastic %D smoothing period',
        min: 1,
        max: 10,
        step: 1
    )]
    private int $slowDPeriod = 3;

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
        return $this->fastKPeriod + $this->slowKPeriod + $this->slowDPeriod;
    }

    protected function strategyName(): string
    {
        return 'Stochastic Reversal';
    }

    protected function computeSignals(OhlcvSeries $ohlcv): void
    {
        $stoch = $this->ctx->stoch(
            $this->fastKPeriod,
            $this->slowKPeriod,
            0,
            $this->slowDPeriod,
            0
        );

        $k = $stoch->get('slowK');
        $d = $stoch->get('slowD');

        $this->entryCondition = $k->crossesAbove($d);
        $this->exitCondition = $k->crossesBelow($d);
    }
}
