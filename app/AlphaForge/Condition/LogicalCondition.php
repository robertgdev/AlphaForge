<?php

namespace App\AlphaForge\Condition;

class LogicalCondition extends AbstractCondition
{
    private ConditionInterface $left;

    private ConditionInterface $right;

    private string $op;

    public function __construct(ConditionInterface $left, ConditionInterface $right, string $op)
    {
        $this->left = $left;
        $this->right = $right;
        $this->op = $op;
    }

    public function evaluate(int $index): bool
    {
        if ($this->op === 'and') {
            return $this->left->evaluate($index) && $this->right->evaluate($index);
        }

        return $this->left->evaluate($index) || $this->right->evaluate($index);
    }

    public function evaluateAll(int $length): array
    {
        $leftResults = $this->left->evaluateAll($length);
        $rightResults = $this->right->evaluateAll($length);

        $results = [];
        for ($i = 0; $i < $length; $i++) {
            if ($this->op === 'and') {
                $results[$i] = $leftResults[$i] && $rightResults[$i];
            } else {
                $results[$i] = $leftResults[$i] || $rightResults[$i];
            }
        }

        return $results;
    }
}
