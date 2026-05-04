<?php

namespace App\AlphaForge\Common\Model;

use App\AlphaForge\Backtesting\Model\BacktestCursor;
use Ds\Map;

class MultiTimeframeOhlcvSeries
{
    private OhlcvSeries $primary;

    private Map $secondary;

    public function __construct(array $primaryData, Map $secondaryData, BacktestCursor $cursor)
    {
        $this->primary = new OhlcvSeries($primaryData, $cursor);
        $this->secondary = $secondaryData;
    }

    public function getPrimary(): OhlcvSeries
    {
        return $this->primary;
    }

    public function getSecondary(string $timeframe): ?OhlcvSeries
    {
        if (! $this->secondary->hasKey($timeframe)) {
            return null;
        }

        $data = $this->secondary->get($timeframe);

        // If it's already an OhlcvSeries, return it
        if ($data instanceof OhlcvSeries) {
            return $data;
        }

        // Otherwise, create one from the array data
        return new OhlcvSeries($data, new BacktestCursor);
    }

    public function getAllTimeframes(): array
    {
        return $this->secondary->keys()->toArray();
    }

    // Delegate to primary for convenience
    public function getOpen(): Series
    {
        return $this->primary->getOpen();
    }

    public function getHigh(): Series
    {
        return $this->primary->getHigh();
    }

    public function getLow(): Series
    {
        return $this->primary->getLow();
    }

    public function getClose(): Series
    {
        return $this->primary->getClose();
    }

    public function getVolume(): Series
    {
        return $this->primary->getVolume();
    }

    public function getTimestamp(): Series
    {
        return $this->primary->getTimestamp();
    }

    public function getHlc3(): Series
    {
        return $this->primary->getHlc3();
    }

    // Magic property access for convenience
    public function __get(string $name): mixed
    {
        return match ($name) {
            'open' => $this->primary->getOpen(),
            'high' => $this->primary->getHigh(),
            'low' => $this->primary->getLow(),
            'close' => $this->primary->getClose(),
            'volume' => $this->primary->getVolume(),
            'timestamp' => $this->primary->getTimestamp(),
            'hlc3' => $this->primary->getHlc3(),
            default => throw new \InvalidArgumentException("Property {$name} does not exist on MultiTimeframeOhlcvSeries"),
        };
    }
}
