<?php

namespace Tests\Unit\AlphaForge\Strategy\Concretes;

use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Strategy\Attribute\Input;
use App\AlphaForge\Strategy\Concretes\BaseSignalStrategy;

class TestSignalStrategy extends BaseSignalStrategy
{
    #[Input(description: 'Test int parameter')]
    private int $testPeriod = 10;

    #[Input(description: 'Test float parameter')]
    private float $testThreshold = 5.0;

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
        return $this->testPeriod;
    }

    protected function strategyName(): string
    {
        return 'Test Strategy';
    }

    protected function computeSignals(OhlcvSeries $ohlcv): void {}
}