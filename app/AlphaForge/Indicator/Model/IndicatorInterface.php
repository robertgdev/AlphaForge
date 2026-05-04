<?php

namespace App\AlphaForge\Indicator\Model;

use App\AlphaForge\Common\Model\Series;
use App\AlphaForge\Plot\PlotDefinition;

interface IndicatorInterface
{
    /**
     * Calculate the indicator values based on the provided OHLCV data.
     */
    public function calculate(array $data): void;

    /**
     * Get the output series for this indicator.
     */
    public function getOutputSeries(string $key = 'value'): Series;

    /**
     * Get all output series for this indicator.
     */
    public function getAllOutputs(): array;

    /**
     * Get the plot definition for this indicator.
     */
    public function getPlotDefinition(): ?PlotDefinition;
}
