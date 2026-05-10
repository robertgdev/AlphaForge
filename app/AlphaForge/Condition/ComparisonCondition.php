<?php

namespace App\AlphaForge\Condition;

use App\AlphaForge\TimeSeries\TimeSeriesInterface;

class ComparisonCondition extends AbstractCondition
{
    private TimeSeriesInterface $left;

    private TimeSeriesInterface|float $right;

    private string $operator;

    public function __construct(TimeSeriesInterface $left, TimeSeriesInterface|float $right, string $operator)
    {
        $this->left = $left;
        $this->right = $right;
        $this->operator = $operator;
    }

    public function evaluate(int $index): bool
    {
        $leftVal = $this->left->get($index);

        if ($leftVal === null) {
            return false;
        }

        if (is_float($this->right)) {
            $rightVal = $this->right;
        } else {
            $rightVal = $this->right->get($index);
            if ($rightVal === null) {
                return false;
            }
        }

        return $this->compare($leftVal, $rightVal);
    }

    public function evaluateAll(int $length): array
    {
        $results = [];
        $leftArr = $this->left->toArray();

        if (is_float($this->right)) {
            $rightVal = $this->right;
            for ($i = 0; $i < $length; $i++) {
                $lv = $leftArr[$i] ?? null;
                $results[$i] = ($lv !== null) && $this->compare($lv, $rightVal);
            }
        } else {
            $rightArr = $this->right->toArray();
            for ($i = 0; $i < $length; $i++) {
                $lv = $leftArr[$i] ?? null;
                $rv = $rightArr[$i] ?? null;
                $results[$i] = ($lv !== null && $rv !== null) && $this->compare($lv, $rv);
            }
        }

        return $results;
    }

    private function compare(float $left, float $right): bool
    {
        return match ($this->operator) {
            '>' => $left > $right,
            '<' => $left < $right,
            '>=' => $left >= $right,
            '<=' => $left <= $right,
            default => false,
        };
    }
}
