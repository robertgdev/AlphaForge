<?php

namespace App\AlphaForge\ExitRule;

class StaticStopLoss implements PriceBasedExitRule
{
    public function evaluate(ExitContext $context): ?ExitTrigger
    {
        if ($context->position->stopLoss === null) {
            return null;
        }

        $sl = (float) $context->position->stopLoss;

        if ($context->position->direction === 'long' && $context->low <= $sl) {
            return new ExitTrigger('stop_loss', $sl);
        }

        if ($context->position->direction === 'short' && $context->high >= $sl) {
            return new ExitTrigger('stop_loss', $sl);
        }

        return null;
    }
}
