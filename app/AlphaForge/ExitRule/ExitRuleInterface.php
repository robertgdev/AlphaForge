<?php

namespace App\AlphaForge\ExitRule;

interface ExitRuleInterface
{
    public function evaluate(ExitContext $context): ?ExitTrigger;
}
