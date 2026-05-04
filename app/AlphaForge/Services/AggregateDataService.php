<?php

namespace App\AlphaForge\Services;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use InvalidArgumentException;

class AggregateDataService
{
    public function __construct(
        private BinaryStorageInterface $binaryStorage
    ) {}

    public function aggregateData(
        string $sourcePath,
        string $targetPath,
        string $symbol,
        TimeframeEnum $sourceTimeframe,
        TimeframeEnum $targetTimeframe
    ): int {
        $targetSeconds = $targetTimeframe->toSeconds();
        $sourceSeconds = $sourceTimeframe->toSeconds();

        $sourceRecords = iterator_to_array($this->binaryStorage->readRecordsSequentially($sourcePath));

        if (empty($sourceRecords)) {
            throw new InvalidArgumentException('Source file contains no records.');
        }

        $this->binaryStorage->createFile($targetPath, $symbol, $targetTimeframe->value);

        $aggregatedBars = [];
        /** @var array{timestamp: int, open: numeric-string, high: numeric-string, low: numeric-string, close: numeric-string, volume: numeric-string}|null $currentBar */
        $currentBar = null;

        /** @var array{timestamp: int, open: numeric-string, high: numeric-string, low: numeric-string, close: numeric-string, volume: numeric-string} $record */
        foreach ($sourceRecords as $record) {
            $timestamp = $record['timestamp'];

            $alignedTimestamp = (int) floor($timestamp / $targetSeconds) * $targetSeconds;

            if ($currentBar === null || $currentBar['timestamp'] !== $alignedTimestamp) {
                if ($currentBar !== null) {
                    $aggregatedBars[] = $currentBar;
                }

                $currentBar = [
                    'timestamp' => $alignedTimestamp,
                    'open' => $record['open'],
                    'high' => $record['high'],
                    'low' => $record['low'],
                    'close' => $record['close'],
                    'volume' => $record['volume'],
                ];
            } else {
                $currentBar['high'] = bccomp($record['high'], $currentBar['high'], 12) > 0
                    ? $record['high']
                    : $currentBar['high'];
                $currentBar['low'] = bccomp($record['low'], $currentBar['low'], 12) < 0
                    ? $record['low']
                    : $currentBar['low'];
                $currentBar['close'] = $record['close'];
                $currentBar['volume'] = bcadd($currentBar['volume'], $record['volume'], 12);
            }
        }

        if ($currentBar !== null) {
            $aggregatedBars[] = $currentBar;
        }

        return $this->binaryStorage->appendRecords($targetPath, $aggregatedBars);
    }
}
