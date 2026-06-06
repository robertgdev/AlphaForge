<?php

namespace App\AlphaForge\Condition;

class JustBecameCondition extends AbstractCondition
{
    private ConditionInterface $condition;

    public function __construct(ConditionInterface $condition)
    {
        $this->condition = $condition;
    }

    public function evaluate(int $index): bool
    {
        if ($index < 1) {
            return false;
        }

        return $this->condition->evaluate($index) && ! $this->condition->evaluate($index - 1);
    }

    public function evaluateAll(int $length): array
    {
        $inner = $this->condition->evaluateAll($length);

        $results = [];
        $results[0] = false;

        for ($i = 1; $i < $length; $i++) {
            $results[$i] = $inner[$i] && ! $inner[$i - 1];
        }

        return $results;
    }
}
