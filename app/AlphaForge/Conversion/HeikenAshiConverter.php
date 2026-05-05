<?php

namespace App\AlphaForge\Conversion;

use App\AlphaForge\Data\Exception\StorageException;
use App\AlphaForge\Data\Service\BinaryStorage;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Services\MarketDataFileService;
use Generator;

final class HeikenAshiConverter
{
    public function __construct(
        private readonly BinaryStorageInterface $binaryStorage,
        private readonly MarketDataFileService $fileService,
        private readonly string $marketDataPath
    ) {}

    public function convert(
        string $exchange,
        string $market,
        string $timeframe,
        ?callable $progressCallback = null
    ): string {
        $sourcePath = $this->fileService->generateFilePath($exchange, $market, $timeframe, 'ohlcv');

        if (! file_exists($sourcePath)) {
            throw new StorageException("OHLC file not found: {$sourcePath}");
        }

        $header = $this->binaryStorage->readHeader($sourcePath);
        $totalRecords = $header['numRecords'];

        if ($totalRecords === 0) {
            throw new StorageException("OHLC file contains no records: {$sourcePath}");
        }

        $destPath = $this->generateHeikenAshiFilePath($exchange, $market, $timeframe);

        $this->binaryStorage->createFile($destPath, $header['symbol'], '', BinaryStorage::DATA_TYPE_HEIKEN_ASHI);

        $heikenAshiCandles = $this->convertOhlcvToHeikenAshi(
            $sourcePath,
            $totalRecords,
            $progressCallback
        );

        $this->binaryStorage->appendRecords($destPath, $heikenAshiCandles);

        return $destPath;
    }

    public function convertIncremental(
        string $exchange,
        string $market,
        string $timeframe,
        ?callable $progressCallback = null
    ): int {
        $destPath = $this->generateHeikenAshiFilePath($exchange, $market, $timeframe);

        if (! file_exists($destPath)) {
            $this->convert($exchange, $market, $timeframe, $progressCallback);

            return -1;
        }

        $destHeader = $this->binaryStorage->readHeader($destPath);

        if ($destHeader['numRecords'] === 0) {
            $this->convert($exchange, $market, $timeframe, $progressCallback);

            return -1;
        }

        $lastRecord = $this->binaryStorage->readRecordByIndex($destPath, $destHeader['numRecords'] - 1);

        if ($lastRecord === null) {
            $this->convert($exchange, $market, $timeframe, $progressCallback);

            return -1;
        }

        $lastTimestamp = $lastRecord['timestamp'];
        $previousHaOpen = $lastRecord['open'];
        $previousHaClose = $lastRecord['close'];

        $sourcePath = $this->fileService->generateFilePath($exchange, $market, $timeframe, 'ohlcv');

        $sourceRecords = iterator_to_array(
            $this->binaryStorage->readRecordsByTimestampRange($sourcePath, $lastTimestamp, PHP_INT_MAX)
        );

        if (count($sourceRecords) <= 1) {
            return 0;
        }

        $totalRecords = count($sourceRecords);
        $processedCount = 0;
        $convertedCandles = [];

        foreach ($sourceRecords as $record) {
            $processedCount++;

            if ($progressCallback !== null && $processedCount % 100 === 0) {
                $progressCallback($processedCount, $totalRecords);
            }

            $haClose = ($record['open'] + $record['high'] + $record['low'] + $record['close']) / 4;
            $haOpen = ($previousHaOpen + $previousHaClose) / 2;
            $haHigh = max($record['high'], $haOpen, $haClose);
            $haLow = min($record['low'], $haOpen, $haClose);

            $candle = [
                'timestamp' => $record['timestamp'],
                'open' => $haOpen,
                'high' => $haHigh,
                'low' => $haLow,
                'close' => $haClose,
                'volume' => $record['volume'],
            ];

            if ($record['timestamp'] === $lastTimestamp) {
                $this->binaryStorage->overwriteLastRecord($destPath, $candle);
            } else {
                $convertedCandles[] = $candle;
            }

            $previousHaOpen = $haOpen;
            $previousHaClose = $haClose;
        }

        if ($progressCallback !== null) {
            $progressCallback($totalRecords, $totalRecords);
        }

        $newRecordsCount = count($convertedCandles);

        if ($newRecordsCount > 0) {
            $this->binaryStorage->appendRecords($destPath, $convertedCandles);
            $this->binaryStorage->updateRecordCount($destPath, $destHeader['numRecords'] + $newRecordsCount);
        }

        return $newRecordsCount;
    }

    public function generateHeikenAshiFilePath(
        string $exchange,
        string $market,
        string $timeframe
    ): string {
        $sanitizedSymbol = str_replace('/', '_', strtoupper($market));

        return sprintf(
            '%s/%s/%s/%s/heikenashi.stchx',
            rtrim($this->marketDataPath, '/'),
            strtolower($exchange),
            $sanitizedSymbol,
            $timeframe
        );
    }

    private function convertOhlcvToHeikenAshi(
        string $sourcePath,
        int $totalRecords,
        ?callable $progressCallback = null
    ): Generator {
        $records = $this->binaryStorage->readRecordsSequentially($sourcePath);

        $previousHaOpen = null;
        $previousHaClose = null;
        $processedCount = 0;

        foreach ($records as $record) {
            $processedCount++;

            if ($progressCallback !== null && $processedCount % 100 === 0) {
                $progressCallback($processedCount, $totalRecords);
            }

            $haClose = ($record['open'] + $record['high'] + $record['low'] + $record['close']) / 4;

            if ($previousHaOpen === null) {
                $haOpen = ($record['open'] + $record['close']) / 2;
            } else {
                $haOpen = ($previousHaOpen + $previousHaClose) / 2;
            }

            $haHigh = max($record['high'], $haOpen, $haClose);

            $haLow = min($record['low'], $haOpen, $haClose);

            yield [
                'timestamp' => $record['timestamp'],
                'open' => $haOpen,
                'high' => $haHigh,
                'low' => $haLow,
                'close' => $haClose,
                'volume' => $record['volume'],
            ];

            $previousHaOpen = $haOpen;
            $previousHaClose = $haClose;
        }

        if ($progressCallback !== null) {
            $progressCallback($totalRecords, $totalRecords);
        }
    }

    public function getOhlcvFileInfo(string $exchange, string $market, string $timeframe): array
    {
        $sourcePath = $this->fileService->generateFilePath($exchange, $market, $timeframe, 'ohlcv');

        if (! file_exists($sourcePath)) {
            throw new StorageException("OHLC file not found: {$sourcePath}");
        }

        return $this->binaryStorage->readHeader($sourcePath);
    }

    public function heikenAshiFileExists(string $exchange, string $market, string $timeframe): bool
    {
        $path = $this->generateHeikenAshiFilePath($exchange, $market, $timeframe);

        return file_exists($path);
    }

    public function readHeikenAshiHeader(string $filePath): array
    {
        return $this->binaryStorage->readHeader($filePath);
    }
}
