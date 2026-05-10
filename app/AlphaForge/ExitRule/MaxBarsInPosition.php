<?php

namespace App\AlphaForge\ExitRule;

class MaxBarsInPosition implements ExitRuleInterface
{
    public function __construct(private int $maxBars) {}

    public function evaluate(ExitContext $context): ?ExitTrigger
    {
        if ($context->barsInPosition >= $this->maxBars) {
            return new ExitTrigger('max_bars', $context->close);
        }

        return null;
    }
}
