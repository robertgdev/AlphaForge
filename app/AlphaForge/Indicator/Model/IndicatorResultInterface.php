<?php

namespace App\AlphaForge\Indicator\Model;

use App\AlphaForge\TimeSeries\TimeSeriesInterface;

interface IndicatorResultInterface
{
    public function get(string $key): TimeSeriesInterface;

    public function all(): array;

    public function outputKeys(): array;
}
