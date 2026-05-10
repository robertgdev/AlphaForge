<?php

namespace App\AlphaForge\ExitRule;

class StaticTakeProfit implements PriceBasedExitRule
{
    public function evaluate(ExitContext $context): ?ExitTrigger
    {
        if ($context->position->takeProfit === null) {
            return null;
        }

        $tp = (float) $context->position->takeProfit;

        if ($context->position->direction === 'long' && $context->high >= $tp) {
            return new ExitTrigger('take_profit', $tp);
        }

        if ($context->position->direction === 'short' && $context->low <= $tp) {
            return new ExitTrigger('take_profit', $tp);
        }

        return null;
    }
}
