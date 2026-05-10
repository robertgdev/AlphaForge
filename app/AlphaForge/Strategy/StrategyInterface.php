<?php

namespace App\AlphaForge\Strategy;

use App\AlphaForge\ExitRule\ExitRuleSet;

interface StrategyInterface
{
    /**
     * Configure the strategy with runtime parameters.
     *
     * @param  array  $runtimeParameters  Strategy-specific parameters
     */
    public function configure(array $runtimeParameters): void;

    /**
     * Called once before the backtest loop starts.
     *
     * Use this to compute indicators and define entry/exit conditions.
     *
     * @param  array  $data  Contains 'ohlcv', 'multi_timeframe', etc.
     */
    public function initialize(array $data): void;

    /**
     * Called on each bar to generate trading signals.
     *
     * @param  array  $data  Contains 'symbol', 'ohlcv', 'cursor', 'portfolio', 'multi_timeframe'
     * @return array Array of OrderSignal objects
     */
    public function onBar(array $data): array;

    /**
     * Get the exit rule set for this strategy.
     *
     * Return null to use the legacy static SL/TP check.
     * Return an ExitRuleSet to fully replace legacy SL/TP with custom exit rules.
     */
    public function getExitRules(): ?ExitRuleSet;
}
