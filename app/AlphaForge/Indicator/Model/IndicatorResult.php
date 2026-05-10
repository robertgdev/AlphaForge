<?php

namespace App\AlphaForge\Indicator\Model;

use App\AlphaForge\TimeSeries\TimeSeriesInterface;

class IndicatorResult implements IndicatorResultInterface
{
    /** @var array<string, TimeSeriesInterface> */
    private array $series;

    /**
     * @param  array<string, TimeSeriesInterface>  $series
     */
    public function __construct(array $series)
    {
        $this->series = $series;
    }

    public function get(string $key): TimeSeriesInterface
    {
        if (! isset($this->series[$key])) {
            throw new \InvalidArgumentException("Unknown output key: '{$key}'. Available: ".implode(', ', array_keys($this->series)));
        }

        return $this->series[$key];
    }

    public function all(): array
    {
        return $this->series;
    }

    public function outputKeys(): array
    {
        return array_keys($this->series);
    }
}
