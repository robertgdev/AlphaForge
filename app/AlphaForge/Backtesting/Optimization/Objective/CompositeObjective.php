<?php

namespace App\AlphaForge\Backtesting\Optimization\Objective;

class CompositeObjective implements ObjectiveFunctionInterface
{
    /**
     * @param  ObjectiveWeight[]  $weights
     */
    public function __construct(
        private readonly array $weights,
        private readonly string $label = 'composite',
    ) {}

    public function score(array $statistics): float
    {
        $score = 0.0;
        foreach ($this->weights as $weight) {
            $value = (float) ($statistics[$weight->metric] ?? 0);

            if ($weight->minClamp !== null && $value < $weight->minClamp) {
                $value = $weight->minClamp;
            }
            if ($weight->maxClamp !== null && $value > $weight->maxClamp) {
                $value = $weight->maxClamp;
            }

            $score += $weight->coefficient * $value;
        }

        return $score;
    }

    public function label(): string
    {
        return $this->label;
    }
}
