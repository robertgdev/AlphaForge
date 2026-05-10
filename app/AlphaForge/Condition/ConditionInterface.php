<?php

namespace App\AlphaForge\Condition;

interface ConditionInterface
{
    public function evaluate(int $index): bool;

    public function evaluateAll(int $length): array;

    public function and(ConditionInterface $other): ConditionInterface;

    public function or(ConditionInterface $other): ConditionInterface;

    public function not(): ConditionInterface;
}
