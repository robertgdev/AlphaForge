<?php

namespace App\AlphaForge\Conversion;

use App\AlphaForge\Data\Exception\StorageException;
use App\AlphaForge\Data\Service\BinaryStorage;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Services\MarketDataFileService;
use Generator;

/**
 * Service for converting OHLC data to Heiken-Ashi candles.
 */
final class HeikenAshiConverter
{
    /**
     * @param  BinaryStorageInterface  $binaryStorage  The binary storage service for reading/writing files
     * @param  MarketDataFileService  $fileService  The file service for generating file paths
     * @param  string  $marketDataPath  The base path for market data storage
     */
    public function __construct(
        private readonly BinaryStorageInterface $binaryStorage,
        private readonly MarketDataFileService $fileService,
        private readonly string $marketDataPath
    ) {}

    /**
     * Convert OHLC data to Heiken-Ashi candles.
     *
     * @param  string  $exchange  The exchange identifier
     * @param  string  $market  The market symbol
     * @param  string  $timeframe  The timeframe
     * @param  callable|null  $progressCallback  Optional callback for progress updates (receives current, total)
     * @return string The path to the generated Heiken-Ashi file
     *
     * @throws StorageException If the OHLC file cannot be read or the Heiken-Ashi file cannot be written
     */
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

    /**
     * Generate the file path for a Heiken-Ashi data file.
     *
     * @param  string  $exchange  The exchange identifier
     * @param  string  $market  The market symbol
     * @param  string  $timeframe  The timeframe
     * @return string The full path to the Heiken-Ashi file
     */
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

    /**
     * Convert OHLC data to Heiken-Ashi candles.
     *
     * Heiken-Ashi calculation:
     * - HA Close = (Open + High + Low + Close) / 4
     * - HA Open = (Previous HA Open + Previous HA Close) / 2
     * - HA High = Max(High, HA Open, HA Close)
     * - HA Low = Min(Low, HA Open, HA Close)
     *
     * @param  string  $sourcePath  The path to the OHLC file
     * @param  int  $totalRecords  Total number of OHLC records
     * @param  callable|null  $progressCallback  Optional progress callback
     * @return Generator Yields Heiken-Ashi candle records
     *
     * @throws StorageException If the file cannot be read
     */
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

    /**
     * Get information about an OHLC file.
     *
     * @param  string  $exchange  The exchange identifier
     * @param  string  $market  The market symbol
     * @param  string  $timeframe  The timeframe
     * @return array The header information from the OHLC file
     *
     * @throws StorageException If the file cannot be read
     */
    public function getOhlcvFileInfo(string $exchange, string $market, string $timeframe): array
    {
        $sourcePath = $this->fileService->generateFilePath($exchange, $market, $timeframe, 'ohlcv');

        if (! file_exists($sourcePath)) {
            throw new StorageException("OHLC file not found: {$sourcePath}");
        }

        return $this->binaryStorage->readHeader($sourcePath);
    }

    /**
     * Check if a Heiken-Ashi file already exists.
     *
     * @param  string  $exchange  The exchange identifier
     * @param  string  $market  The market symbol
     * @param  string  $timeframe  The timeframe
     * @return bool True if the file exists
     */
    public function heikenAshiFileExists(string $exchange, string $market, string $timeframe): bool
    {
        $path = $this->generateHeikenAshiFilePath($exchange, $market, $timeframe);

        return file_exists($path);
    }

    /**
     * Read the header from a Heiken-Ashi file.
     *
     * Delegates to the unified BinaryStorage::readHeader() which returns
     * all header fields including dataType and brickSize.
     *
     * @param  string  $filePath  The path to the Heiken-Ashi file
     * @return array The header information
     *
     * @throws StorageException If the file cannot be read
     */
    public function readHeikenAshiHeader(string $filePath): array
    {
        return $this->binaryStorage->readHeader($filePath);
    }
}
