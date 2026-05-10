<?php

namespace App\AlphaForge\Condition;

use App\AlphaForge\TimeSeries\TimeSeriesInterface;

class CrossCondition extends AbstractCondition
{
    private TimeSeriesInterface $a;

    private TimeSeriesInterface $b;

    private string $direction;

    public function __construct(TimeSeriesInterface $a, TimeSeriesInterface $b, string $direction)
    {
        $this->a = $a;
        $this->b = $b;
        $this->direction = $direction;
    }

    public function evaluate(int $index): bool
    {
        if ($index < 1) {
            return false;
        }

        $aPrev = $this->a->get($index - 1);
        $aCurr = $this->a->get($index);
        $bPrev = $this->b->get($index - 1);
        $bCurr = $this->b->get($index);

        if ($aPrev === null || $aCurr === null || $bPrev === null || $bCurr === null) {
            return false;
        }

        if ($this->direction === 'above') {
            return $aPrev <= $bPrev && $aCurr > $bCurr;
        }

        return $aPrev >= $bPrev && $aCurr < $bCurr;
    }

    public function evaluateAll(int $length): array
    {
        $results = [];
        $aArr = $this->a->toArray();
        $bArr = $this->b->toArray();

        for ($i = 0; $i < $length; $i++) {
            if ($i < 1) {
                $results[$i] = false;

                continue;
            }

            $aPrev = $aArr[$i - 1] ?? null;
            $aCurr = $aArr[$i] ?? null;
            $bPrev = $bArr[$i - 1] ?? null;
            $bCurr = $bArr[$i] ?? null;

            if ($aPrev === null || $aCurr === null || $bPrev === null || $bCurr === null) {
                $results[$i] = false;

                continue;
            }

            if ($this->direction === 'above') {
                $results[$i] = $aPrev <= $bPrev && $aCurr > $bCurr;
            } else {
                $results[$i] = $aPrev >= $bPrev && $aCurr < $bCurr;
            }
        }

        return $results;
    }
}
