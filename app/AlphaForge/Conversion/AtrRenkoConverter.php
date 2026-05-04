<?php

namespace App\AlphaForge\Conversion;

use App\AlphaForge\Data\Exception\StorageException;
use App\AlphaForge\Data\Service\BinaryStorage;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Services\MarketDataFileService;
use Generator;

/**
 * Service for converting OHLC data to ATR-based Renko bricks.
 *
 * Unlike the fixed-brick RenkoConverter, this uses the Average True Range (ATR)
 * indicator to determine a dynamic brick size. The ATR period is stored in the
 * header's brickSize field, and the actual ATR values can always be recomputed
 * from the source OHLC data given that period.
 */
final class AtrRenkoConverter
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
     * Convert OHLC data to ATR-based Renko bricks.
     *
     * @param  string  $exchange  The exchange identifier
     * @param  string  $market  The market symbol
     * @param  string  $timeframe  The timeframe
     * @param  int  $atrPeriod  The ATR period for dynamic brick sizing
     * @param  callable|null  $progressCallback  Optional callback for progress updates (receives current, total)
     * @return string The path to the generated ATR-Renko file
     *
     * @throws StorageException If the OHLC file cannot be read or the Renko file cannot be written
     */
    public function convert(
        string $exchange,
        string $market,
        string $timeframe,
        int $atrPeriod,
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

        if ($totalRecords < $atrPeriod) {
            throw new StorageException("OHLC file contains only {$totalRecords} records, which is fewer than the ATR period of {$atrPeriod}.");
        }

        $destPath = $this->generateAtrRenkoFilePath($exchange, $market, $timeframe, $atrPeriod);

        // brickSize header field stores the ATR period for ATR-Renko files
        $this->binaryStorage->createFile($destPath, $header['symbol'], '', BinaryStorage::DATA_TYPE_ATR_RENKO, (float) $atrPeriod);

        $renkoBricks = $this->convertOhlcvToAtrRenko(
            $sourcePath,
            $atrPeriod,
            $totalRecords,
            $progressCallback
        );

        $this->binaryStorage->appendRecords($destPath, $renkoBricks);

        return $destPath;
    }

    /**
     * Generate the file path for an ATR-Renko data file.
     *
     * @param  string  $exchange  The exchange identifier
     * @param  string  $market  The market symbol
     * @param  string  $timeframe  The timeframe
     * @param  int  $atrPeriod  The ATR period
     * @return string The full path to the ATR-Renko file
     */
    public function generateAtrRenkoFilePath(
        string $exchange,
        string $market,
        string $timeframe,
        int $atrPeriod
    ): string {
        $sanitizedSymbol = str_replace('/', '_', strtoupper($market));

        return sprintf(
            '%s/%s/%s/%s/renko_atr_%d.stchx',
            rtrim($this->marketDataPath, '/'),
            strtolower($exchange),
            $sanitizedSymbol,
            $timeframe,
            $atrPeriod
        );
    }

    /**
     * Convert OHLC data to ATR-based Renko bricks using the high-low method
     * with a dynamic brick size derived from the ATR indicator.
     *
     * Algorithm:
     *   1. Read all OHLC records into memory and re-index to 0-based
     *   2. Compute ATR series using trader_atr() (Wilders smoothing)
     *   3. Re-index ATR output to 0-based (trader_atr skips warmup keys)
     *   4. Pad the warmup gap: prepend the first valid ATR for the initial records
     *   5. Iterate records with corresponding ATR-derived brick size,
     *      applying the same high-low Renko logic as the fixed-brick converter
     *
     * @param  string  $sourcePath  The path to the OHLC file
     * @param  int  $atrPeriod  The ATR period
     * @param  int  $totalRecords  Total number of OHLC records
     * @param  callable|null  $progressCallback  Optional progress callback
     * @return Generator Yields Renko brick records
     *
     * @throws StorageException If the file cannot be read or trader extension is unavailable
     */
    private function convertOhlcvToAtrRenko(
        string $sourcePath,
        int $atrPeriod,
        int $totalRecords,
        ?callable $progressCallback = null
    ): Generator {
        if (! function_exists('trader_atr')) {
            throw new StorageException('The PHP Trader extension is required for ATR-based Renko conversion. Install ext-trader (PECL).');
        }

        // Pass 1: Load all OHLC records and compute ATR
        // Re-index to guarantee sequential 0-based keys regardless of generator behavior
        $records = array_values(iterator_to_array($this->binaryStorage->readRecordsSequentially($sourcePath)));
        $recordCount = count($records);

        $highs = array_column($records, 'high');
        $lows = array_column($records, 'low');
        $closes = array_column($records, 'close');

        $atrRaw = trader_atr($highs, $lows, $closes, $atrPeriod);

        if ($atrRaw === false) {
            throw new StorageException('trader_atr() failed. Verify that the OHLC data is valid and the ATR period is appropriate.');
        }

        // trader_atr() returns an array whose keys start at (atrPeriod),
        // e.g. for period=14 the keys are 14, 15, 16, ... with no entries for 0..13.
        // Re-index to 0-based, then pad the warmup gap at the front.
        $atrValues = array_values($atrRaw);
        $atrCount = count($atrValues);

        // The re-indexed ATR array has (recordCount - atrPeriod) entries (TA-Lib convention
        // for Wilders-smoothed indicators: output = input - period).
        // Prepend the first valid ATR for the warmup records (indices 0 through atrPeriod - 1).
        $firstValidAtr = $atrValues[0] ?? null;

        if ($firstValidAtr === null || $firstValidAtr <= 0 || is_nan($firstValidAtr)) {
            throw new StorageException('Could not compute a valid ATR value from the OHLC data.');
        }

        // Build a full-length array: warmup records get the first valid ATR,
        // then the computed values follow.
        $warmupCount = $recordCount - $atrCount;
        $fullAtr = array_fill(0, $warmupCount, $firstValidAtr);
        $fullAtr = array_merge($fullAtr, $atrValues);

        // Replace any NaN or non-positive values in the computed portion
        for ($i = $warmupCount; $i < count($fullAtr); $i++) {
            if (is_nan($fullAtr[$i]) || $fullAtr[$i] <= 0) {
                $fullAtr[$i] = $firstValidAtr;
            }
        }

        // Pass 2: Convert with dynamic brick size
        $currentPrice = null;
        $currentDirection = 0;
        $processedCount = 0;

        foreach ($records as $idx => $record) {
            $processedCount++;

            if ($progressCallback !== null && $processedCount % 100 === 0) {
                $progressCallback($processedCount, $totalRecords);
            }

            if ($currentPrice === null) {
                $currentPrice = $record['close'];

                continue;
            }

            $brickSize = $fullAtr[$idx];

            // Guard against zero/negative brick size (shouldn't happen after fill, but be safe)
            if ($brickSize <= 0) {
                $brickSize = $firstValidAtr;
            }

            $high = $record['high'];
            $low = $record['low'];
            $timestamp = $record['timestamp'];

            if ($currentDirection >= 0) {
                $upBricks = (int) floor(($high - $currentPrice) / $brickSize);
                $downBricks = (int) floor(($currentPrice - $low) / $brickSize);

                if ($upBricks >= 1) {
                    for ($i = 0; $i < $upBricks; $i++) {
                        $openPrice = $currentPrice;
                        $closePrice = $currentPrice + $brickSize;

                        yield [
                            'timestamp' => $timestamp,
                            'open' => $openPrice,
                            'high' => $closePrice,
                            'low' => $openPrice,
                            'close' => $closePrice,
                            'volume' => $record['volume'] / max(1, $upBricks),
                        ];

                        $currentPrice = $closePrice;
                    }
                    $currentDirection = 1;
                } elseif ($downBricks >= 2) {
                    for ($i = 0; $i < $downBricks; $i++) {
                        $openPrice = $currentPrice;
                        $closePrice = $currentPrice - $brickSize;

                        yield [
                            'timestamp' => $timestamp,
                            'open' => $openPrice,
                            'high' => $openPrice,
                            'low' => $closePrice,
                            'close' => $closePrice,
                            'volume' => $record['volume'] / max(1, $downBricks),
                        ];

                        $currentPrice = $closePrice;
                    }
                    $currentDirection = -1;
                }
            } else {
                $downBricks = (int) floor(($currentPrice - $low) / $brickSize);
                $upBricks = (int) floor(($high - $currentPrice) / $brickSize);

                if ($downBricks >= 1) {
                    for ($i = 0; $i < $downBricks; $i++) {
                        $openPrice = $currentPrice;
                        $closePrice = $currentPrice - $brickSize;

                        yield [
                            'timestamp' => $timestamp,
                            'open' => $openPrice,
                            'high' => $openPrice,
                            'low' => $closePrice,
                            'close' => $closePrice,
                            'volume' => $record['volume'] / max(1, $downBricks),
                        ];

                        $currentPrice = $closePrice;
                    }
                    $currentDirection = -1;
                } elseif ($upBricks >= 2) {
                    for ($i = 0; $i < $upBricks; $i++) {
                        $openPrice = $currentPrice;
                        $closePrice = $currentPrice + $brickSize;

                        yield [
                            'timestamp' => $timestamp,
                            'open' => $openPrice,
                            'high' => $closePrice,
                            'low' => $openPrice,
                            'close' => $closePrice,
                            'volume' => $record['volume'] / max(1, $upBricks),
                        ];

                        $currentPrice = $closePrice;
                    }
                    $currentDirection = 1;
                }
            }
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
     * Check if an ATR-Renko file already exists.
     *
     * @param  string  $exchange  The exchange identifier
     * @param  string  $market  The market symbol
     * @param  string  $timeframe  The timeframe
     * @param  int  $atrPeriod  The ATR period
     * @return bool True if the file exists
     */
    public function atrRenkoFileExists(string $exchange, string $market, string $timeframe, int $atrPeriod): bool
    {
        $path = $this->generateAtrRenkoFilePath($exchange, $market, $timeframe, $atrPeriod);

        return file_exists($path);
    }

    /**
     * Read the header from an ATR-Renko file.
     *
     * Delegates to the unified BinaryStorage::readHeader() which returns
     * all header fields including dataType and brickSize (which stores the ATR period).
     *
     * @param  string  $filePath  The path to the ATR-Renko file
     * @return array The header information
     *
     * @throws StorageException If the file cannot be read
     */
    public function readAtrRenkoHeader(string $filePath): array
    {
        return $this->binaryStorage->readHeader($filePath);
    }
}
