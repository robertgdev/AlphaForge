<?php

namespace App\AlphaForge\Strategy\Concretes;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Strategy\Attribute\AsStrategy;
use App\AlphaForge\Strategy\Attribute\Input;

#[AsStrategy(
    alias: 'breakout',
    name: 'Volatility Breakout',
    description: 'Breakout strategy that enters when price breaks above the highest high of the lookback period. Uses ATR-based exits and stop management.',
    timeframe: TimeframeEnum::H1,
    requiredMarketData: [TimeframeEnum::H1]
)]
class BreakoutStrategy extends BaseSignalStrategy
{
    #[Input(
        description: 'Lookback period for highest-high breakout',
        min: 10,
        max: 60,
        step: 5
    )]
    private int $lookback = 20;

    #[Input(
        description: 'ATR period for trailing stop',
        min: 5,
        max: 30,
        step: 1
    )]
    private int $atrPeriod = 14;

    #[Input(
        description: 'ATR multiplier for trailing stop distance',
        min: 1.0,
        max: 5.0,
        step: 0.5
    )]
    private float $atrMultiplier = 2.0;

    #[Input(
        description: 'Stop loss percentage from entry (fallback when ATR unavailable)',
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
    private float $takeProfitPercent = 12.0;

    /** @var array<int, float> */
    private array $atrValues = [];

    /** @var array<int, float> */
    private array $highPrices = [];

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
        return max($this->lookback, $this->atrPeriod) + 10;
    }

    protected function strategyName(): string
    {
        return 'Breakout';
    }

    protected function computeSignals(OhlcvSeries $ohlcv): void
    {
        $close = $this->ctx->priceSeries('close');

        $highSeries = $this->ctx->priceSeries('high');
        $highestHigh = $this->ctx->indicator('max', ['period' => $this->lookback], ['close' => $highSeries]);

        /** @var \App\AlphaForge\TimeSeries\TimeSeriesInterface $highestHigh */
        $this->entryCondition = $close->crossesAbove($highestHigh);

        $lowSeries = $this->ctx->priceSeries('low');
        $halfLookback = max(5, (int) ($this->lookback / 2));
        $lowestLow = $this->ctx->indicator('min', ['period' => $halfLookback], ['close' => $lowSeries]);

        /** @var \App\AlphaForge\TimeSeries\TimeSeriesInterface $lowestLow */
        $this->exitCondition = $close->crossesBelow($lowestLow);

        $atr = $this->ctx->atr($this->atrPeriod);
        $this->atrValues = $atr->toArray();
        $this->highPrices = $ohlcv->getHighs()->getVector()->toArray();
    }

    protected function calculateStopLoss(string $currentPrice, int $currentIndex): string
    {
        $atrVal = $this->atrValues[$currentIndex] ?? null;

        if ($atrVal !== null && $atrVal > 0) {
            $stopDistance = $atrVal * $this->atrMultiplier;

            return bcsub($currentPrice, (string) $stopDistance, 6);
        }

        return parent::calculateStopLoss($currentPrice, $currentIndex);
    }
}
