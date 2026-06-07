<?php

namespace App\AlphaForge\Strategy;

use App\AlphaForge\ExitRule\ExitRuleSet;
use App\AlphaForge\Order\Dto\OrderSignal;
use App\AlphaForge\Strategy\Dto\BarData;
use App\AlphaForge\Strategy\Dto\InitializeData;

interface StrategyInterface
{
    /**
     * Configure the strategy with runtime parameters.
     *
     * @param  array<string, mixed>  $runtimeParameters  Strategy-specific parameters
     */
    public function configure(array $runtimeParameters): void;

    /**
     * Called once before the backtest loop starts.
     *
     * Use this to compute indicators and define entry/exit conditions.
     */
    public function initialize(InitializeData $data): void;

    /**
     * Called on each bar to generate trading signals.
     *
     * @return array<int, OrderSignal>
     */
    public function onBar(BarData $data): array;

    /**
     * Get the exit rule set for this strategy.
     *
     * Return null to use the legacy static SL/TP check.
     * Return an ExitRuleSet to fully replace legacy SL/TP with custom exit rules.
     */
    public function getExitRules(): ?ExitRuleSet;
}
