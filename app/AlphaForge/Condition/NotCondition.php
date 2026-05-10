<?php

namespace App\AlphaForge\Condition;

class NotCondition extends AbstractCondition
{
    private ConditionInterface $inner;

    public function __construct(ConditionInterface $inner)
    {
        $this->inner = $inner;
    }

    public function evaluate(int $index): bool
    {
        return ! $this->inner->evaluate($index);
    }

    public function evaluateAll(int $length): array
    {
        $innerResults = $this->inner->evaluateAll($length);

        return array_map(static fn (bool $v): bool => ! $v, $innerResults);
    }
}
