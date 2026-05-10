<?php

namespace App\AlphaForge\Condition;

use App\AlphaForge\TimeSeries\TimeSeriesInterface;

class TrendCondition extends AbstractCondition
{
    private TimeSeriesInterface $series;

    private int $period;

    private string $direction;

    public function __construct(TimeSeriesInterface $series, int $period, string $direction)
    {
        $this->series = $series;
        $this->period = $period;
        $this->direction = $direction;
    }

    public function evaluate(int $index): bool
    {
        $prevIndex = $index - $this->period;

        if ($prevIndex < 0) {
            return false;
        }

        $curr = $this->series->get($index);
        $prev = $this->series->get($prevIndex);

        if ($curr === null || $prev === null) {
            return false;
        }

        if ($this->direction === 'rising') {
            return $curr > $prev;
        }

        return $curr < $prev;
    }

    public function evaluateAll(int $length): array
    {
        $results = [];
        $arr = $this->series->toArray();

        for ($i = 0; $i < $length; $i++) {
            $prevIndex = $i - $this->period;

            if ($prevIndex < 0) {
                $results[$i] = false;

                continue;
            }

            $curr = $arr[$i] ?? null;
            $prev = $arr[$prevIndex] ?? null;

            if ($curr === null || $prev === null) {
                $results[$i] = false;

                continue;
            }

            if ($this->direction === 'rising') {
                $results[$i] = $curr > $prev;
            } else {
                $results[$i] = $curr < $prev;
            }
        }

        return $results;
    }
}
