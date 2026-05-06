<?php

namespace App\AlphaForge\Backtesting\Service;

use App\AlphaForge\Backtesting\Model\BacktestCursor;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Model\OhlcvSeries;
use Carbon\Carbon;

readonly class MultiTimeframeDataService implements MultiTimeframeDataServiceInterface
{
    public function resample(OhlcvSeries $source, TimeframeEnum $targetTimeframe): OhlcvSeries
    {
        $sourceTimeframe = $source->getTimeframe();
        $sourceMinutes = $this->getTimeframeMinutes($sourceTimeframe);
        $targetMinutes = $this->getTimeframeMinutes($targetTimeframe);

        if ($targetMinutes <= $sourceMinutes) {
            throw new \InvalidArgumentException(
                'Target timeframe must be higher than source timeframe. '.
                "Source: {$sourceTimeframe->value}, Target: {$targetTimeframe->value}"
            );
        }

        if ($targetMinutes % $sourceMinutes !== 0) {
            throw new \InvalidArgumentException(
                'Target timeframe must be a multiple of source timeframe. '.
                "Source: {$sourceMinutes} minutes, Target: {$targetMinutes} minutes"
            );
        }

        $ratio = (int) ($targetMinutes / $sourceMinutes);

        $timestamps = $source->getTimestamps()->getVector();
        $opens = $source->getOpens()->getVector();
        $highs = $source->getHighs()->getVector();
        $lows = $source->getLows()->getVector();
        $closes = $source->getCloses()->getVector();
        $volumes = $source->getVolumes()->getVector();

        $resampledTimestamps = [];
        $resampledOpens = [];
        $resampledHighs = [];
        $resampledLows = [];
        $resampledCloses = [];
        $resampledVolumes = [];

        $count = $timestamps->count();
        $i = 0;

        while ($i < $count) {
            $currentTimestamp = Carbon::createFromTimestamp($timestamps->get($i));
            $periodStart = $this->getPeriodStart($currentTimestamp, $targetTimeframe);

            $periodOpens = [];
            $periodHighs = [];
            $periodLows = [];
            $periodCloses = [];
            $periodVolumes = [];
            $periodTimestamp = $periodStart->timestamp;

            for ($j = 0; $j < $ratio && $i < $count; $j++) {
                $barTimestamp = Carbon::createFromTimestamp($timestamps->get($i));
                $barPeriodStart = $this->getPeriodStart($barTimestamp, $targetTimeframe);

                if ($barPeriodStart->timestamp !== $periodTimestamp) {
                    break;
                }

                $periodOpens[] = $opens->get($i);
                $periodHighs[] = $highs->get($i);
                $periodLows[] = $lows->get($i);
                $periodCloses[] = $closes->get($i);
                $periodVolumes[] = $volumes->get($i);

                $i++;
            }

            if (! empty($periodOpens)) {
                $resampledTimestamps[] = $periodTimestamp;
                $resampledOpens[] = $periodOpens[0];
                $resampledHighs[] = max($periodHighs);
                $resampledLows[] = min($periodLows);
                $resampledCloses[] = end($periodCloses);
                $resampledVolumes[] = array_sum($periodVolumes);
            }
        }

        $marketData = [
            'timestamp' => $resampledTimestamps,
            'open' => $resampledOpens,
            'high' => $resampledHighs,
            'low' => $resampledLows,
            'close' => $resampledCloses,
            'volume' => $resampledVolumes,
        ];

        return new OhlcvSeries(
            $marketData,
            new BacktestCursor,
            $source->getSymbol(),
            $targetTimeframe
        );
    }

    public function aggregate(OhlcvSeries $baseSeries, array $higherTimeframes): array
    {
        $baseKey = $baseSeries->getTimeframe()?->value;
        $result = $baseKey !== null ? [$baseKey => $baseSeries] : ['base' => $baseSeries];

        foreach ($higherTimeframes as $timeframe) {
            $result[$timeframe->value] = $this->resample($baseSeries, $timeframe);
        }

        return $result;
    }

    private function getTimeframeMinutes(TimeframeEnum $timeframe): int
    {
        return (int) ($timeframe->toSeconds() / 60);
    }

    private function getPeriodStart(Carbon $timestamp, TimeframeEnum $timeframe): Carbon
    {
        $copy = $timestamp->copy();

        return match ($timeframe) {
            TimeframeEnum::S1 => $copy->startOfSecond(),
            TimeframeEnum::M1 => $copy->startOfMinute(),
            TimeframeEnum::M5 => $copy->startOfMinute()->setMinute((int) (floor($copy->minute / 5) * 5)),
            TimeframeEnum::M15 => $copy->startOfMinute()->setMinute((int) (floor($copy->minute / 15) * 15)),
            TimeframeEnum::M30 => $copy->startOfMinute()->setMinute((int) (floor($copy->minute / 30) * 30)),
            TimeframeEnum::H1 => $copy->startOfHour(),
            TimeframeEnum::H2 => $copy->startOfHour()->setHour((int) (floor($copy->hour / 2) * 2)),
            TimeframeEnum::H4 => $copy->startOfHour()->setHour((int) (floor($copy->hour / 4) * 4)),
            TimeframeEnum::H6 => $copy->startOfHour()->setHour((int) (floor($copy->hour / 6) * 6)),
            TimeframeEnum::H8 => $copy->startOfHour()->setHour((int) (floor($copy->hour / 8) * 8)),
            TimeframeEnum::H12 => $copy->startOfHour()->setHour((int) (floor($copy->hour / 12) * 12)),
            TimeframeEnum::D1 => $copy->startOfDay(),
            TimeframeEnum::D3 => $copy->startOfDay(),
            TimeframeEnum::W1 => $copy->startOfWeek(),
            TimeframeEnum::MN1 => $copy->startOfMonth(),
        };
    }
}
