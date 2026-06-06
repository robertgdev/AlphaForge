<?php

namespace App\AlphaForge\TimeSeries;

use App\AlphaForge\Condition\ConditionInterface;

interface TimeSeriesInterface
{
    public function get(int $index): ?float;

    public function toArray(): array;

    public function count(): int;

    public function crossesAbove(TimeSeriesInterface $other): ConditionInterface;

    public function crossesBelow(TimeSeriesInterface $other): ConditionInterface;

    public function isAbove(TimeSeriesInterface|float $other): ConditionInterface;

    public function isBelow(TimeSeriesInterface|float $other): ConditionInterface;

    public function isAtLeast(TimeSeriesInterface|float $other): ConditionInterface;

    public function isAtMost(TimeSeriesInterface|float $other): ConditionInterface;

    public function isRising(int $period = 1): ConditionInterface;

    public function isFalling(int $period = 1): ConditionInterface;
}
