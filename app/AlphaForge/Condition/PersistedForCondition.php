<?php

namespace App\AlphaForge\Condition;

class PersistedForCondition extends AbstractCondition
{
    private ConditionInterface $condition;

    private int $bars;

    public function __construct(ConditionInterface $condition, int $bars)
    {
        $this->condition = $condition;
        $this->bars = $bars;
    }

    public function evaluate(int $index): bool
    {
        $start = $index - $this->bars + 1;

        if ($start < 0) {
            return false;
        }

        for ($i = $start; $i <= $index; $i++) {
            if (! $this->condition->evaluate($i)) {
                return false;
            }
        }

        return true;
    }

    public function evaluateAll(int $length): array
    {
        $inner = $this->condition->evaluateAll($length);

        $results = [];
        $consecutive = 0;

        for ($i = 0; $i < $length; $i++) {
            if ($inner[$i]) {
                $consecutive++;
            } else {
                $consecutive = 0;
            }

            $results[$i] = $consecutive >= $this->bars;
        }

        return $results;
    }
}
