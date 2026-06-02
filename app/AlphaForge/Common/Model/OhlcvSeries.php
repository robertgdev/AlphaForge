<?php

namespace App\AlphaForge\Common\Model;

use App\AlphaForge\Backtesting\Model\BacktestCursor;
use App\AlphaForge\Common\Enum\OhlcvEnum;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use Ds\Vector;

class OhlcvSeries
{
    private Series $open;

    private Series $high;

    private Series $low;

    private Series $close;

    private Series $volume;

    private Series $timestamp;

    private ?Series $hlc3 = null;

    private BacktestCursor $cursor;

    private ?string $symbol;

    private ?TimeframeEnum $timeframe;

    public function __construct(array $marketData, BacktestCursor $cursor, ?string $symbol = null, ?TimeframeEnum $timeframe = null)
    {
        $this->cursor = $cursor;
        $this->symbol = $symbol;
        $this->timeframe = $timeframe;
        $this->open = new Series($marketData[OhlcvEnum::Open->value] ?? [], $cursor);
        $this->high = new Series($marketData[OhlcvEnum::High->value] ?? [], $cursor);
        $this->low = new Series($marketData[OhlcvEnum::Low->value] ?? [], $cursor);
        $this->close = new Series($marketData[OhlcvEnum::Close->value] ?? [], $cursor);
        $this->volume = new Series($marketData[OhlcvEnum::Volume->value] ?? [], $cursor);
        $this->timestamp = new Series($marketData[OhlcvEnum::Timestamp->value] ?? [], $cursor);
    }

    public function getOpen(): Series
    {
        return $this->open;
    }

    public function getHigh(): Series
    {
        return $this->high;
    }

    public function getLow(): Series
    {
        return $this->low;
    }

    public function getClose(): Series
    {
        return $this->close;
    }

    public function getVolume(): Series
    {
        return $this->volume;
    }

    public function getTimestamp(): Series
    {
        return $this->timestamp;
    }

    public function getTimestamps(): Series
    {
        return $this->timestamp;
    }

    public function getOpens(): Series
    {
        return $this->open;
    }

    public function getHighs(): Series
    {
        return $this->high;
    }

    public function getLows(): Series
    {
        return $this->low;
    }

    public function getCloses(): Series
    {
        return $this->close;
    }

    public function getVolumes(): Series
    {
        return $this->volume;
    }

    public function getHlc3(): Series
    {
        if ($this->hlc3 === null) {
            $this->calculateHlc3();
        }

        return $this->hlc3;
    }

    public function getSymbol(): ?string
    {
        return $this->symbol;
    }

    public function getTimeframe(): ?TimeframeEnum
    {
        return $this->timeframe;
    }

    public function slice(int $start, int $length): self
    {
        $marketData = [
            'timestamp' => array_slice($this->timestamp->toArray(), $start, $length),
            'open' => array_slice($this->open->toArray(), $start, $length),
            'high' => array_slice($this->high->toArray(), $start, $length),
            'low' => array_slice($this->low->toArray(), $start, $length),
            'close' => array_slice($this->close->toArray(), $start, $length),
            'volume' => array_slice($this->volume->toArray(), $start, $length),
        ];

        return new self($marketData, $this->cursor);
    }

    private function calculateHlc3(): void
    {
        $hlc3Values = new Vector;
        $count = $this->close->count();

        for ($i = 0; $i < $count; $i++) {
            $h = $this->high->getVector()->get($i);
            $l = $this->low->getVector()->get($i);
            $c = $this->close->getVector()->get($i);

            if ($h === null || $l === null || $c === null) {
                $hlc3Values->push(null);
            } else {
                $sum = bcadd((string) $h, (string) $l);
                $sum = bcadd($sum, (string) $c);
                $hlc3 = bcdiv($sum, '3');
                $hlc3Values->push($hlc3);
            }
        }

        $this->hlc3 = new Series($hlc3Values, $this->cursor);
    }
}
