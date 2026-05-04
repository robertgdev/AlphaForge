<?php

namespace App\AlphaForge\Strategy;

interface StrategyInterface
{
    /**
     * Configure the strategy with runtime parameters.
     *
     * @param  array  $runtimeParameters  Strategy-specific parameters
     */
    public function configure(array $runtimeParameters): void;

    /**
     * Called on each bar to generate trading signals.
     *
     * @param  array  $data  Contains 'symbol', 'ohlcv', 'cursor', 'portfolio', 'multi_timeframe'
     * @return array Array of OrderSignal objects
     */
    public function onBar(array $data): array;
}
