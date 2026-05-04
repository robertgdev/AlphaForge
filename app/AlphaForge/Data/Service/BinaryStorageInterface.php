<?php

namespace App\AlphaForge\Data\Service;

interface BinaryStorageInterface
{
    public function getTempFilePath(string $finalPath): string;

    public function getMergedTempFilePath(string $finalPath): string;

    public function atomicRename(string $sourcePath, string $destinationPath): void;

    public function createFile(string $filePath, string $symbol, string $timeframe, int $dataType = 1, float $brickSize = 0.0): void;

    public function appendRecords(string $filePath, iterable $records): int;

    public function readHeader(string $filePath): array;

    public function readRecordByIndex(string $filePath, int $index): ?array;

    public function readRecordsSequentially(string $filePath): \Generator;

    public function mergeAndWrite(string $originalPath, string $newDataPath, string $outputPath): int;

    public function readRecordsByTimestampRange(string $filePath, int $startTimestamp, int $endTimestamp): \Generator;

    public function updateRecordCount(string $filePath, int $recordCount): void;

    /**
     * Streams records from a generator to a file, periodically updating the header's record count.
     *
     * @param  string  $filePath  The path to the binary file.
     * @param  iterable  $records  The records to stream.
     * @param  int  $commitInterval  The number of records to write before updating the header.
     * @return int The total number of records written.
     */
    public function streamAndCommitRecords(string $filePath, iterable $records, int $commitInterval = 5000): int;
}
