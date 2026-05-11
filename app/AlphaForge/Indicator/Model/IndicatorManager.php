<?php

namespace App\AlphaForge\Indicator\Model;

use App\AlphaForge\Backtesting\Model\BacktestCursor;
use App\AlphaForge\Common\Model\Series;
use Ds\Map;
use Ds\Vector;

class IndicatorManager implements IndicatorManagerInterface
{
    /** @var array<string, IndicatorInterface> */
    private array $indicators = [];

    /** @var array<string, array<string, Series>> */
    private array $outputCache = [];

    public function __construct(
        private BacktestCursor $cursor,
        /** @var Map<string, Vector<array{timestamp: int|float, open: float, high: float, low: float, close: float, volume: float}>> */
        private Map $dataframes
    ) {}

    public function add(string $key, IndicatorInterface $indicator): void
    {
        $this->indicators[$key] = $indicator;
    }

    public function get(string $key): ?IndicatorInterface
    {
        return $this->indicators[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->indicators[$key]);
    }

    public function getOutputSeries(string $indicatorKey, string $seriesKey = 'value'): Series
    {
        if (! isset($this->outputCache[$indicatorKey][$seriesKey])) {
            throw new \InvalidArgumentException("Output series '{$seriesKey}' not found for indicator '{$indicatorKey}'");
        }

        return $this->outputCache[$indicatorKey][$seriesKey];
    }

    public function calculateBatch(): void
    {
        $primaryData = $this->dataframes->get('primary');
        \assert($primaryData !== null);

        foreach ($this->indicators as $key => $indicator) {
            $indicator->calculate($this->dataframes);
            $this->outputCache[$key] = $indicator->getAllOutputs();
        }
    }

    /**
     * @return array<string, array<string, array<array{timestamp: int|float, open: float, high: float, low: float, close: float, volume: float}>>>
     */
    public function getAllOutputDataForSave(): array
    {
        $result = [];

        foreach ($this->outputCache as $indicatorKey => $outputs) {
            foreach ($outputs as $seriesKey => $series) {
                $result[$indicatorKey][$seriesKey] = $series->toArray();
            }
        }

        return $result;
    }
}
