<?php

namespace App\AlphaForge\Indicator\Model;

use App\AlphaForge\Common\Model\Series;
use App\AlphaForge\Plot\PlotDefinition;

interface IndicatorInterface
{
    /**
     * Calculate the indicator values based on the provided dataframes map.
     *
     * @param  \Ds\Map<string, \Ds\Vector<array{timestamp: int|float, open: float, high: float, low: float, close: float, volume: float}>>  $dataframes
     */
    public function calculate(\Ds\Map $dataframes): void;

    /**
     * Get the output series for this indicator.
     */
    public function getOutputSeries(string $key = 'value'): Series;

    /**
     * Get all output series for this indicator.
     *
     * @return array<string, Series>
     */
    public function getAllOutputs(): array;

    /**
     * Get the plot definition for this indicator.
     */
    public function getPlotDefinition(): ?PlotDefinition;
}
