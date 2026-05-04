<?php

namespace App\AlphaForge\Backtesting\Service;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Model\OhlcvSeries;
use Carbon\Carbon;
use Ds\Vector;

readonly class MultiTimeframeDataService implements MultiTimeframeDataServiceInterface
{
    /**
     * Resample OHLCV data to a higher timeframe.
     *
     * @param  OhlcvSeries  $source  The source OHLCV data
     * @param  TimeframeEnum  $targetTimeframe  The target timeframe
     * @return OhlcvSeries The resampled OHLCV data
     */
    public function resample(OhlcvSeries $source, TimeframeEnum $targetTimeframe): OhlcvSeries
    {
        $sourceTimeframe = $source->getTimeframe();
        $sourceMinutes = $this->getTimeframeMinutes($sourceTimeframe);
        $targetMinutes = $this->getTimeframeMinutes($targetTimeframe);

        // Validate that target is higher than source
        if ($targetMinutes <= $sourceMinutes) {
            throw new \InvalidArgumentException(
                'Target timeframe must be higher than source timeframe. '.
                "Source: {$sourceTimeframe->value}, Target: {$targetTimeframe->value}"
            );
        }

        // Check if target is a multiple of source
        if ($targetMinutes % $sourceMinutes !== 0) {
            throw new \InvalidArgumentException(
                'Target timeframe must be a multiple of source timeframe. '.
                "Source: {$sourceMinutes} minutes, Target: {$targetMinutes} minutes"
            );
        }

        $ratio = (int) ($targetMinutes / $sourceMinutes);

        $timestamps = $source->getTimestamps();
        $opens = $source->getOpens();
        $highs = $source->getHighs();
        $lows = $source->getLows();
        $closes = $source->getCloses();
        $volumes = $source->getVolumes();

        $resampledTimestamps = [];
        $resampledOpens = [];
        $resampledHighs = [];
        $resampledLows = [];
        $resampledCloses = [];
        $resampledVolumes = [];

        $count = $timestamps->count();
        $i = 0;

        while ($i < $count) {
            // Determine the boundary of the current target period
            $currentTimestamp = Carbon::createFromTimestamp($timestamps->get($i));
            $periodStart = $this->getPeriodStart($currentTimestamp, $targetTimeframe);

            // Collect all bars within this period
            $periodOpens = [];
            $periodHighs = [];
            $periodLows = [];
            $periodCloses = [];
            $periodVolumes = [];
            $periodTimestamp = $periodStart->timestamp;

            for ($j = 0; $j < $ratio && $i < $count; $j++) {
                $barTimestamp = Carbon::createFromTimestamp($timestamps->get($i));
                $barPeriodStart = $this->getPeriodStart($barTimestamp, $targetTimeframe);

                // Check if this bar belongs to the same period
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
                $resampledOpens[] = $periodOpens[0]; // First open
                $resampledHighs[] = max($periodHighs); // Highest high
                $resampledLows[] = min($periodLows); // Lowest low
                $resampledCloses[] = end($periodCloses); // Last close
                $resampledVolumes[] = array_sum($periodVolumes); // Sum of volumes
            }
        }

        return new OhlcvSeries(
            $source->getSymbol(),
            $targetTimeframe,
            new Vector($resampledTimestamps),
            new Vector($resampledOpens),
            new Vector($resampledHighs),
            new Vector($resampledLows),
            new Vector($resampledCloses),
            new Vector($resampledVolumes)
        );
    }

    /**
     * Aggregate multiple OHLCV series into a multi-timeframe container.
     *
     * @param  OhlcvSeries  $baseSeries  The base (lowest) timeframe series
     * @param  array<TimeframeEnum>  $higherTimeframes  Higher timeframes to generate
     * @return array<TimeframeEnum, OhlcvSeries> Map of timeframe to OHLCV series
     */
    public function aggregate(OhlcvSeries $baseSeries, array $higherTimeframes): array
    {
        $result = [$baseSeries->getTimeframe() => $baseSeries];

        foreach ($higherTimeframes as $timeframe) {
            $result[$timeframe] = $this->resample($baseSeries, $timeframe);
        }

        return $result;
    }

    /**
     * Get the number of minutes for a timeframe.
     */
    private function getTimeframeMinutes(TimeframeEnum $timeframe): int
    {
        return match ($timeframe) {
            TimeframeEnum::MINUTE_1 => 1,
            TimeframeEnum::MINUTE_3 => 3,
            TimeframeEnum::MINUTE_5 => 5,
            TimeframeEnum::MINUTE_15 => 15,
            TimeframeEnum::MINUTE_30 => 30,
            TimeframeEnum::HOUR_1 => 60,
            TimeframeEnum::HOUR_2 => 120,
            TimeframeEnum::HOUR_4 => 240,
            TimeframeEnum::HOUR_6 => 360,
            TimeframeEnum::HOUR_8 => 480,
            TimeframeEnum::HOUR_12 => 720,
            TimeframeEnum::DAY_1 => 1440,
            TimeframeEnum::DAY_3 => 4320,
            TimeframeEnum::WEEK_1 => 10080,
            TimeframeEnum::MONTH_1 => 43200, // Approximate
        };
    }

    /**
     * Get the start of the period for a given timestamp and timeframe.
     */
    private function getPeriodStart(Carbon $timestamp, TimeframeEnum $timeframe): Carbon
    {
        $copy = $timestamp->copy();

        return match ($timeframe) {
            TimeframeEnum::MINUTE_1 => $copy->startOfMinute(),
            TimeframeEnum::MINUTE_3 => $copy->startOfMinute()->setMinute((int) (floor($copy->minute / 3) * 3)),
            TimeframeEnum::MINUTE_5 => $copy->startOfMinute()->setMinute((int) (floor($copy->minute / 5) * 5)),
            TimeframeEnum::MINUTE_15 => $copy->startOfMinute()->setMinute((int) (floor($copy->minute / 15) * 15)),
            TimeframeEnum::MINUTE_30 => $copy->startOfMinute()->setMinute((int) (floor($copy->minute / 30) * 30)),
            TimeframeEnum::HOUR_1 => $copy->startOfHour(),
            TimeframeEnum::HOUR_2 => $copy->startOfHour()->setHour((int) (floor($copy->hour / 2) * 2)),
            TimeframeEnum::HOUR_4 => $copy->startOfHour()->setHour((int) (floor($copy->hour / 4) * 4)),
            TimeframeEnum::HOUR_6 => $copy->startOfHour()->setHour((int) (floor($copy->hour / 6) * 6)),
            TimeframeEnum::HOUR_8 => $copy->startOfHour()->setHour((int) (floor($copy->hour / 8) * 8)),
            TimeframeEnum::HOUR_12 => $copy->startOfHour()->setHour((int) (floor($copy->hour / 12) * 12)),
            TimeframeEnum::DAY_1 => $copy->startOfDay(),
            TimeframeEnum::DAY_3 => $copy->startOfDay(), // Simplified - would need more complex logic
            TimeframeEnum::WEEK_1 => $copy->startOfWeek(),
            TimeframeEnum::MONTH_1 => $copy->startOfMonth(),
        };
    }
}
