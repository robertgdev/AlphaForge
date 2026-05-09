<?php

namespace App\AlphaForge\Data\Service;

use App\AlphaForge\Data\Exception\DataFileNotFoundException;
use Carbon\Carbon;
use Carbon\CarbonTimeZone;

use function Safe\filesize;

readonly class DataInspectionService
{
    private CarbonTimeZone $utcZone;

    public function __construct(
        private BinaryStorageInterface $binaryStorage,
        private string $baseDataPath,
    ) {
        $this->utcZone = new CarbonTimeZone('UTC');
    }

    /**
     * Inspects a data file and returns a structured array with metadata, samples, and validation results.
     *
     * @throws DataFileNotFoundException
     */
    public function inspect(string $exchangeId, string $symbol, string $timeframe): array
    {
        $filePath = $this->generateFilePath($exchangeId, $symbol, $timeframe);

        if (! file_exists($filePath)) {
            throw new DataFileNotFoundException("Data file not found at path: {$filePath}");
        }

        $header = $this->binaryStorage->readHeader($filePath);
        $recordCount = $header['numRecords'];
        $head = [];
        $tail = [];

        if ($recordCount > 0) {
            $headCount = min(5, $recordCount);
            for ($i = 0; $i < $headCount; $i++) {
                $record = $this->binaryStorage->readRecordByIndex($filePath, $i);
                if ($record !== null) {
                    $head[] = $this->formatRecord($record);
                }
            }

            if ($recordCount > 10) {
                $tailStart = max($headCount, $recordCount - 5);
                for ($i = $tailStart; $i < $recordCount; $i++) {
                    $record = $this->binaryStorage->readRecordByIndex($filePath, $i);
                    if ($record !== null) {
                        $tail[] = $this->formatRecord($record);
                    }
                }
            }
        }

        $validation = $this->validateDataConsistency($filePath, $header);

        /** @psalm-suppress MixedArgument */
        $fileSize = @filesize($filePath);

        return [
            'filePath' => $filePath,
            'fileSize' => $fileSize !== false ? $fileSize : 0,
            'header' => $header,
            'sample' => [
                'head' => $head,
                'tail' => $tail,
            ],
            'validation' => $validation,
        ];
    }

    private function generateFilePath(string $exchangeId, string $symbol, string $timeframe): string
    {
        $sanitizedSymbol = str_replace('/', '_', $symbol);

        return sprintf(
            '%s/%s/%s/%s/ohlcv.stchx',
            rtrim($this->baseDataPath, '/'),
            strtolower($exchangeId),
            strtoupper($sanitizedSymbol),
            $timeframe
        );
    }

    /**
     * @param  array{timestamp: int|float, open: float, high: float, low: float, close: float, volume: float}  $record
     * @return array{timestamp: int|float, utc: string, open: float, high: float, low: float, close: float, volume: float}
     */
    private function formatRecord(array $record): array
    {
        return [
            'timestamp' => $record['timestamp'],
            'utc' => Carbon::createFromTimestamp($record['timestamp'], $this->utcZone)->format('Y-m-d H:i:s'),
            'open' => (float) $record['open'],
            'high' => (float) $record['high'],
            'low' => (float) $record['low'],
            'close' => (float) $record['close'],
            'volume' => (float) $record['volume'],
        ];
    }

    private function timeframeToSeconds(string $timeframe): ?int
    {
        $unit = substr($timeframe, -1);
        $value = (int) substr($timeframe, 0, -1);

        if ($value <= 0) {
            return null;
        }

        return match ($unit) {
            'm' => $value * 60,
            'h' => $value * 3600,
            'd' => $value * 86400,
            'w' => $value * 604800,
            default => null,
        };
    }

    /**
     * @param  array{numRecords: int, timeframe: string, timestamp: int|float, open: float, high: float, low: float, close: float, volume: float}  $header
     */
    private function validateDataConsistency(string $filePath, array $header): array
    {
        $recordCount = $header['numRecords'];
        $timeframe = $header['timeframe'];

        if ($recordCount < 2) {
            return ['status' => 'skipped', 'message' => 'Need at least 2 records to check for gaps.'];
        }

        // Branch to a date-aware check for monthly data
        if (str_ends_with($timeframe, 'M')) {
            return $this->validateMonthlyGaps($filePath, (int) $timeframe);
        }

        // Use fixed-second interval check for all other timeframes
        $expectedInterval = $this->timeframeToSeconds($timeframe);
        if ($expectedInterval === null) {
            return ['status' => 'skipped', 'message' => "Gap validation for timeframe '{$timeframe}' is not supported."];
        }

        $records = $this->binaryStorage->readRecordsSequentially($filePath);
        $previousTimestamp = null;
        $gaps = [];
        $duplicates = [];
        $outOfOrder = [];
        $index = 0;

        foreach ($records as $record) {
            /** @var int|float $currentTimestamp */
            $currentTimestamp = $record['timestamp'];

            if ($previousTimestamp !== null) {
                $diff = $currentTimestamp - $previousTimestamp;

                if ($diff <= 0) {
                    if ($diff === 0) {
                        $duplicates[] = ['index' => $index, 'timestamp' => $currentTimestamp];
                    } else {
                        $outOfOrder[] = ['index' => $index, 'previous' => $previousTimestamp, 'current' => $currentTimestamp];
                    }
                } elseif ($diff !== $expectedInterval) {
                    $gaps[] = ['index' => $index, 'previous' => $previousTimestamp, 'current' => $currentTimestamp, 'diff' => $diff, 'expected' => $expectedInterval];
                }
            }
            $previousTimestamp = $currentTimestamp;
            $index++;
        }

        $totalIssues = count($gaps) + count($duplicates) + count($outOfOrder);

        return [
            'status' => $totalIssues > 0 ? 'failed' : 'passed',
            'message' => $totalIssues > 0 ? "Found {$totalIssues} issue(s)." : 'Data appears consistent.',
            'gaps' => $gaps,
            'duplicates' => $duplicates,
            'outOfOrder' => $outOfOrder,
        ];
    }

    /**
     * @param  array{numRecords: int, timeframe: string, timestamp: int|float, open: float, high: float, low: float, close: float, volume: float}  $header
     */
    private function validateMonthlyGaps(string $filePath, int $monthStep): array
    {
        $records = $this->binaryStorage->readRecordsSequentially($filePath);
        $previousDateTime = null;
        $gaps = [];
        $duplicates = [];
        $index = 0;

        foreach ($records as $record) {
            $currentDateTime = Carbon::createFromTimestamp((int) $record['timestamp'], $this->utcZone);

            if ($previousDateTime !== null) {
                $expectedDateTime = $previousDateTime->copy()->addMonths($monthStep);

                // Check for duplicates
                if ($currentDateTime->format('Y-m') === $previousDateTime->format('Y-m')) {
                    $duplicates[] = ['index' => $index, 'month' => $currentDateTime->format('Y-m')];
                }
                // Check for gaps
                elseif ($currentDateTime->format('Y-m') !== $expectedDateTime->format('Y-m')) {
                    $gaps[] = [
                        'index' => $index,
                        'previous' => $previousDateTime->format('Y-m'),
                        'current' => $currentDateTime->format('Y-m'),
                        'expected' => $expectedDateTime->format('Y-m'),
                    ];
                }
            }

            $previousDateTime = $currentDateTime;
            $index++;
        }

        $totalIssues = count($gaps) + count($duplicates);

        return [
            'status' => $totalIssues > 0 ? 'failed' : 'passed',
            'message' => $totalIssues > 0 ? "Found {$totalIssues} issue(s) in monthly data." : 'Monthly data appears consistent.',
            'gaps' => $gaps,
            'duplicates' => $duplicates,
            'outOfOrder' => [],
        ];
    }
}
