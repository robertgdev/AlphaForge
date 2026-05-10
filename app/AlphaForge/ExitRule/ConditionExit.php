<?php

namespace App\AlphaForge\ExitRule;

use App\AlphaForge\Condition\ConditionInterface;

class ConditionExit implements ExitRuleInterface
{
    public static function when(ConditionInterface $condition, string $tag = ''): self
    {
        return new self($condition, $tag);
    }

    public function __construct(
        private ConditionInterface $condition,
        private string $tag = '',
    ) {}

    public function evaluate(ExitContext $context): ?ExitTrigger
    {
        if ($this->condition->evaluate($context->barIndex)) {
            return new ExitTrigger('condition_exit', $context->close, $this->tag ?: null);
        }

        return null;
    }
}
