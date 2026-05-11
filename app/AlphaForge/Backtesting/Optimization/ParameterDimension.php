<?php

namespace App\AlphaForge\Backtesting\Optimization;

readonly class ParameterDimension
{
    public function __construct(
        public string $name,
        public float $min,
        public float $max,
        public float $step = 1.0,
        public string $type = 'int',
    ) {}

    public function values(): array
    {
        $values = [];
        for ($v = $this->min; $v <= $this->max + ($this->step * 0.001); $v += $this->step) {
            $values[] = $this->type === 'float' ? (float) $v : (int) $v;
        }

        return $values;
    }

    public function randomValue(): int|float
    {
        $values = $this->values();

        return $values[array_rand($values)];
    }

    public function clamp(int|float $value): int|float
    {
        $clamped = max($this->min, min($this->max, $value));

        return $this->type === 'float' ? (float) $clamped : (int) round($clamped);
    }

    public function count(): int
    {
        return count($this->values());
    }
}
