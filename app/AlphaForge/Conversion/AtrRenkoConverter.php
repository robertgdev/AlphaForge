<?php

namespace App\AlphaForge\Conversion;

use App\AlphaForge\Data\Exception\StorageException;
use App\AlphaForge\Data\Service\BinaryStorage;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Services\MarketDataFileService;
use Generator;
use TaLibHybrid\TaLibHybrid;

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
      *   2. Compute ATR series using TaLibHybrid::atr() (Wilders smoothing)
      *   3. Replace NULL warmup entries in TaLibHybrid::atr() output with the first valid ATR value
     *   4. Iterate records with corresponding ATR-derived brick size,
     *      applying the same high-low Renko logic as the fixed-brick converter
     *
     * @param  string  $sourcePath  The path to the OHLC file
     * @param  int  $atrPeriod  The ATR period
     * @param  int  $totalRecords  Total number of OHLC records
     * @param  callable|null  $progressCallback  Optional progress callback
     * @return Generator Yields Renko brick records
     *
     * @throws StorageException If the file cannot be read or TaLibHybrid is unavailable
     */
    private function convertOhlcvToAtrRenko(
        string $sourcePath,
        int $atrPeriod,
        int $totalRecords,
        ?callable $progressCallback = null
    ): Generator {
        $records = array_values(iterator_to_array($this->binaryStorage->readRecordsSequentially($sourcePath)));
        $recordCount = count($records);

$highs = array_column($records, 'high');
         $lows = array_column($records, 'low');
         $closes = array_column($records, 'close');

         try {
             $atrRaw = TaLibHybrid::atr($highs, $lows, $closes, $atrPeriod);
         } catch (\Throwable $e) {
             throw new StorageException('TaLibHybrid::atr() failed: ' . $e->getMessage());
         }

         if (empty($atrRaw)) {
             throw new StorageException('TaLibHybrid::atr() returned no results. Verify that the OHLC data is valid and the ATR period is appropriate.');
         }

         // TaLibHybrid::atr() returns a full-size array (same count as input) with NULL for warmup entries.
        // Find the first valid (non-NULL) ATR value, then fill NULLs with it.
        $fullAtr = $atrRaw;
        $firstValidAtr = null;

        foreach ($fullAtr as $val) {
            if ($val !== null && $val > 0 && ! is_nan($val)) {
                $firstValidAtr = $val;
                break;
            }
        }

        if ($firstValidAtr === null) {
            throw new StorageException('Could not compute a valid ATR value from the OHLC data.');
        }

        foreach ($fullAtr as $i => $val) {
            if ($val === null || is_nan($val) || $val <= 0) {
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
     * Incrementally update an existing ATR-Renko file with new source data.
     *
     * Reads the last ATR-Renko brick to derive currentPrice/currentDirection state,
     * recomputes the full ATR series from source (needed for correct dynamic brick sizes),
     * reads source records from the last brick timestamp onward, and reconverts
     * the boundary bar (overwriting the last brick) before appending new bricks.
     *
     * @param  string  $exchange  The exchange identifier
     * @param  string  $market  The market symbol
     * @param  string  $timeframe  The timeframe
     * @param  int  $atrPeriod  The ATR period for dynamic brick sizing
     * @param  callable|null  $progressCallback  Optional callback for progress updates
     * @return int Number of new bricks appended (0 = up to date, -1 = fell back to full conversion)
     */
    public function convertIncremental(
        string $exchange,
        string $market,
        string $timeframe,
        int $atrPeriod,
        ?callable $progressCallback = null
    ): int {
        $destPath = $this->generateAtrRenkoFilePath($exchange, $market, $timeframe, $atrPeriod);

        if (! file_exists($destPath)) {
            $this->convert($exchange, $market, $timeframe, $atrPeriod, $progressCallback);

            return -1;
        }

        $destHeader = $this->binaryStorage->readHeader($destPath);

        if ($destHeader['numRecords'] === 0) {
            $this->convert($exchange, $market, $timeframe, $atrPeriod, $progressCallback);

            return -1;
        }

        $lastRecord = $this->binaryStorage->readRecordByIndex($destPath, $destHeader['numRecords'] - 1);

        if ($lastRecord === null) {
            $this->convert($exchange, $market, $timeframe, $atrPeriod, $progressCallback);

            return -1;
        }

        $lastTimestamp = $lastRecord['timestamp'];
        $currentPrice = $lastRecord['close'];
        $currentDirection = ($lastRecord['close'] > $lastRecord['open']) ? 1 : -1;

        $sourcePath = $this->fileService->generateFilePath($exchange, $market, $timeframe, 'ohlcv');

        $allSourceRecords = array_values(iterator_to_array($this->binaryStorage->readRecordsSequentially($sourcePath)));

$highs = array_column($allSourceRecords, 'high');
         $lows = array_column($allSourceRecords, 'low');
         $closes = array_column($allSourceRecords, 'close');

         try {
             $atrRaw = TaLibHybrid::atr($highs, $lows, $closes, $atrPeriod);
         } catch (\Throwable $e) {
             throw new StorageException('TaLibHybrid::atr() failed: ' . $e->getMessage());
         }

         if (empty($atrRaw)) {
             throw new StorageException('TaLibHybrid::atr() returned no results. Verify that the OHLC data is valid and the ATR period is appropriate.');
         }

         $fullAtr = $atrRaw;
        $firstValidAtr = null;

        foreach ($fullAtr as $val) {
            if ($val !== null && $val > 0 && ! is_nan($val)) {
                $firstValidAtr = $val;
                break;
            }
        }

        if ($firstValidAtr === null) {
            throw new StorageException('Could not compute a valid ATR value from the OHLC data.');
        }

        foreach ($fullAtr as $i => $val) {
            if ($val === null || is_nan($val) || $val <= 0) {
                $fullAtr[$i] = $firstValidAtr;
            }
        }

        $incrementalRecords = iterator_to_array(
            $this->binaryStorage->readRecordsByTimestampRange($sourcePath, $lastTimestamp, PHP_INT_MAX)
        );

        if (count($incrementalRecords) <= 1) {
            return 0;
        }

        $timestampIndexMap = [];
        foreach ($allSourceRecords as $idx => $rec) {
            $timestampIndexMap[$rec['timestamp']] = $idx;
        }

        $totalRecords = count($incrementalRecords);
        $processedCount = 0;
        $convertedBricks = [];
        $firstBrickOverwritten = false;

        foreach ($incrementalRecords as $record) {
            $processedCount++;

            if ($progressCallback !== null && $processedCount % 100 === 0) {
                $progressCallback($processedCount, $totalRecords);
            }

            if ($currentPrice === null) {
                $currentPrice = $record['close'];

                continue;
            }

            $globalIdx = $timestampIndexMap[$record['timestamp']] ?? null;
            $brickSize = ($globalIdx !== null && isset($fullAtr[$globalIdx])) ? $fullAtr[$globalIdx] : $firstValidAtr;

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

                        $brick = [
                            'timestamp' => $timestamp,
                            'open' => $openPrice,
                            'high' => $closePrice,
                            'low' => $openPrice,
                            'close' => $closePrice,
                            'volume' => $record['volume'] / max(1, $upBricks),
                        ];

                        if (! $firstBrickOverwritten && $timestamp === $lastTimestamp) {
                            $this->binaryStorage->overwriteLastRecord($destPath, $brick);
                            $firstBrickOverwritten = true;
                        } else {
                            $convertedBricks[] = $brick;
                        }

                        $currentPrice = $closePrice;
                    }
                    $currentDirection = 1;
                } elseif ($downBricks >= 2) {
                    for ($i = 0; $i < $downBricks; $i++) {
                        $openPrice = $currentPrice;
                        $closePrice = $currentPrice - $brickSize;

                        $brick = [
                            'timestamp' => $timestamp,
                            'open' => $openPrice,
                            'high' => $openPrice,
                            'low' => $closePrice,
                            'close' => $closePrice,
                            'volume' => $record['volume'] / max(1, $downBricks),
                        ];

                        if (! $firstBrickOverwritten && $timestamp === $lastTimestamp) {
                            $this->binaryStorage->overwriteLastRecord($destPath, $brick);
                            $firstBrickOverwritten = true;
                        } else {
                            $convertedBricks[] = $brick;
                        }

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

                        $brick = [
                            'timestamp' => $timestamp,
                            'open' => $openPrice,
                            'high' => $openPrice,
                            'low' => $closePrice,
                            'close' => $closePrice,
                            'volume' => $record['volume'] / max(1, $downBricks),
                        ];

                        if (! $firstBrickOverwritten && $timestamp === $lastTimestamp) {
                            $this->binaryStorage->overwriteLastRecord($destPath, $brick);
                            $firstBrickOverwritten = true;
                        } else {
                            $convertedBricks[] = $brick;
                        }

                        $currentPrice = $closePrice;
                    }
                    $currentDirection = -1;
                } elseif ($upBricks >= 2) {
                    for ($i = 0; $i < $upBricks; $i++) {
                        $openPrice = $currentPrice;
                        $closePrice = $currentPrice + $brickSize;

                        $brick = [
                            'timestamp' => $timestamp,
                            'open' => $openPrice,
                            'high' => $closePrice,
                            'low' => $openPrice,
                            'close' => $closePrice,
                            'volume' => $record['volume'] / max(1, $upBricks),
                        ];

                        if (! $firstBrickOverwritten && $timestamp === $lastTimestamp) {
                            $this->binaryStorage->overwriteLastRecord($destPath, $brick);
                            $firstBrickOverwritten = true;
                        } else {
                            $convertedBricks[] = $brick;
                        }

                        $currentPrice = $closePrice;
                    }
                    $currentDirection = 1;
                }
            }
        }

        if ($progressCallback !== null) {
            $progressCallback($totalRecords, $totalRecords);
        }

        $newRecordsCount = count($convertedBricks);

        if ($newRecordsCount > 0) {
            $this->binaryStorage->appendRecords($destPath, $convertedBricks);
            $this->binaryStorage->updateRecordCount($destPath, $destHeader['numRecords'] + $newRecordsCount);
        }

        return $newRecordsCount;
    }

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
