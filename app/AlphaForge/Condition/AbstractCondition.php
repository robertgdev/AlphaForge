<?php

namespace App\AlphaForge\Condition;

abstract class AbstractCondition implements ConditionInterface
{
    public function evaluateAll(int $length): array
    {
        $results = [];
        for ($i = 0; $i < $length; $i++) {
            $results[$i] = $this->evaluate($i);
        }

        return $results;
    }

    public function and(ConditionInterface $other): ConditionInterface
    {
        return new LogicalCondition($this, $other, 'and');
    }

    public function or(ConditionInterface $other): ConditionInterface
    {
        return new LogicalCondition($this, $other, 'or');
    }

    public function not(): ConditionInterface
    {
        return new NotCondition($this);
    }
}
