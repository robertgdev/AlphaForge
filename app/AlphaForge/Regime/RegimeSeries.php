<?php

namespace App\AlphaForge\Regime;

final class RegimeSeries
{
    /**
     * @param  array<int, string|null>  $regimes  Regime label per bar index (null = insufficient data)
     */
    public function __construct(
        private readonly array $regimes,
    ) {}

    public function get(int $index): ?string
    {
        return $this->regimes[$index] ?? null;
    }

    public function toArray(): array
    {
        return $this->regimes;
    }

    public function count(): int
    {
        return count($this->regimes);
    }

    /**
     * Returns the unique regime labels present in the data.
     *
     * @return array<int, string>
     */
    public function labels(): array
    {
        $unique = array_unique(array_values($this->regimes));
        $filtered = array_filter($unique, fn ($v) => $v !== null);
        sort($filtered);

        return $filtered;
    }
}
