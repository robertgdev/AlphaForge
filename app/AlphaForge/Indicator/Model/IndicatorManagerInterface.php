<?php

namespace App\AlphaForge\Indicator\Model;

use App\AlphaForge\Common\Model\Series;

interface IndicatorManagerInterface
{
    /**
     * Add an indicator to the manager.
     */
    public function add(string $key, IndicatorInterface $indicator): void;

    /**
     * Get an indicator by key.
     */
    public function get(string $key): ?IndicatorInterface;

    /**
     * Check if an indicator exists.
     */
    public function has(string $key): bool;

    /**
     * Get the output series for a specific indicator.
     */
    public function getOutputSeries(string $indicatorKey, string $seriesKey = 'value'): Series;

    /**
     * Calculate all indicators in batch.
     */
    public function calculateBatch(): void;

    /**
     * Get all output data for saving.
     */
    public function getAllOutputDataForSave(): array;
}
