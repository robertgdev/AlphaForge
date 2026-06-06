<?php

namespace App\AlphaForge\Condition;

class WithinLastCondition extends AbstractCondition
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
        $start = max(0, $index - $this->bars + 1);

        for ($i = $start; $i <= $index; $i++) {
            if ($this->condition->evaluate($i)) {
                return true;
            }
        }

        return false;
    }

    public function evaluateAll(int $length): array
    {
        $inner = $this->condition->evaluateAll($length);

        $results = [];
        $trueCount = 0;

        for ($i = 0; $i < $length; $i++) {
            if ($inner[$i]) {
                $trueCount++;
            }

            $exitIdx = $i - $this->bars;
            if ($exitIdx >= 0 && $inner[$exitIdx]) {
                $trueCount--;
            }

            $results[$i] = $trueCount > 0;
        }

        return $results;
    }
}
