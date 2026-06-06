<?php

namespace App\AlphaForge\TimeSeries;

use App\AlphaForge\Condition\ComparisonCondition;
use App\AlphaForge\Condition\CrossCondition;
use App\AlphaForge\Condition\TrendCondition;

class ArrayTimeSeries implements TimeSeriesInterface
{
    /** @var array<int, float|null> */
    private array $data;

    /**
     * @param  array<int, float|int|null>  $data
     */
    public function __construct(array $data)
    {
        $this->data = array_map(
            static fn ($v): ?float => $v === null ? null : (float) $v,
            $data
        );
    }

    public function get(int $index): ?float
    {
        return $this->data[$index] ?? null;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function crossesAbove(TimeSeriesInterface $other): CrossCondition
    {
        return new CrossCondition($this, $other, 'above');
    }

    public function crossesBelow(TimeSeriesInterface $other): CrossCondition
    {
        return new CrossCondition($this, $other, 'below');
    }

    public function isAbove(TimeSeriesInterface|float $other): ComparisonCondition
    {
        return new ComparisonCondition($this, $other, '>');
    }

    public function isBelow(TimeSeriesInterface|float $other): ComparisonCondition
    {
        return new ComparisonCondition($this, $other, '<');
    }

    public function isAtLeast(TimeSeriesInterface|float $other): ComparisonCondition
    {
        return new ComparisonCondition($this, $other, '>=');
    }

    public function isAtMost(TimeSeriesInterface|float $other): ComparisonCondition
    {
        return new ComparisonCondition($this, $other, '<=');
    }

    public function isRising(int $period = 1): TrendCondition
    {
        return new TrendCondition($this, $period, 'rising');
    }

    public function isFalling(int $period = 1): TrendCondition
    {
        return new TrendCondition($this, $period, 'falling');
    }
}
