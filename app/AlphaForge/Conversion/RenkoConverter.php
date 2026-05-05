<?php

namespace App\AlphaForge\Conversion;

use App\AlphaForge\Data\Exception\StorageException;
use App\AlphaForge\Data\Service\BinaryStorage;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Services\MarketDataFileService;
use Generator;

/**
 * Service for converting OHLC data to Renko bricks.
 */
final class RenkoConverter
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
     * Convert OHLC data to Renko bricks.
     *
     * @param  string  $exchange  The exchange identifier
     * @param  string  $market  The market symbol
     * @param  string  $timeframe  The timeframe
     * @param  float  $brickSize  The brick size for Renko conversion
     * @param  callable|null  $progressCallback  Optional callback for progress updates (receives current, total)
     * @return string The path to the generated Renko file
     *
     * @throws StorageException If the OHLC file cannot be read or the Renko file cannot be written
     */
    public function convert(
        string $exchange,
        string $market,
        string $timeframe,
        float $brickSize,
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

        $destPath = $this->generateRenkoFilePath($exchange, $market, $timeframe, $brickSize);

        $this->binaryStorage->createFile($destPath, $header['symbol'], '', BinaryStorage::DATA_TYPE_RENKO, $brickSize);

        $renkoBricks = $this->convertOhlcvToRenko(
            $sourcePath,
            $brickSize,
            $totalRecords,
            $progressCallback
        );

        $this->binaryStorage->appendRecords($destPath, $renkoBricks);

        return $destPath;
    }

    /**
     * Generate the file path for a Renko data file.
     *
     * @param  string  $exchange  The exchange identifier
     * @param  string  $market  The market symbol
     * @param  string  $timeframe  The timeframe
     * @param  float  $brickSize  The brick size
     * @return string The full path to the Renko file
     */
    public function generateRenkoFilePath(
        string $exchange,
        string $market,
        string $timeframe,
        float $brickSize
    ): string {
        $sanitizedSymbol = str_replace('/', '_', strtoupper($market));
        $brickSizeStr = $this->formatBrickSize($brickSize);

        return sprintf(
            '%s/%s/%s/%s/renko_%s.stchx',
            rtrim($this->marketDataPath, '/'),
            strtolower($exchange),
            $sanitizedSymbol,
            $timeframe,
            $brickSizeStr
        );
    }

    /**
     * Format brick size for filename (avoiding special characters).
     *
     * @param  float  $brickSize  The brick size
     * @return string The formatted brick size string
     */
    private function formatBrickSize(float $brickSize): string
    {
        $str = (string) $brickSize;

        if (floor($brickSize) === $brickSize) {
            return (string) (int) $brickSize;
        }

        return str_replace('.', '_', $str);
    }

    /**
     * Convert OHLC data to Renko bricks using the high-low method.
     *
     * This method uses the high and low prices to determine brick movements,
     * which is more accurate for Renko chart construction.
     *
     * @param  string  $sourcePath  The path to the OHLC file
     * @param  float  $brickSize  The brick size
     * @param  int  $totalRecords  Total number of OHLC records
     * @param  callable|null  $progressCallback  Optional progress callback
     * @return Generator Yields Renko brick records
     *
     * @throws StorageException If the file cannot be read
     */
    private function convertOhlcvToRenko(
        string $sourcePath,
        float $brickSize,
        int $totalRecords,
        ?callable $progressCallback = null
    ): Generator {
        $records = $this->binaryStorage->readRecordsSequentially($sourcePath);

        $currentPrice = null;
        $currentDirection = 0;
        $processedCount = 0;
        $lastTimestamp = 0;

        foreach ($records as $record) {
            $processedCount++;

            if ($progressCallback !== null && $processedCount % 100 === 0) {
                $progressCallback($processedCount, $totalRecords);
            }

            if ($currentPrice === null) {
                $currentPrice = $record['close'];
                $lastTimestamp = $record['timestamp'];

                continue;
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

            $lastTimestamp = $timestamp;
        }

        if ($progressCallback !== null) {
            $progressCallback($totalRecords, $totalRecords);
        }
    }

    /**
     * Incrementally update an existing Renko file with new source data.
     *
     * Reads the last Renko brick to derive currentPrice/currentDirection state,
     * reads source records from that timestamp onward, reconverts the boundary
     * bar (overwriting the last brick), and appends any new bricks.
     *
     * @param  string  $exchange  The exchange identifier
     * @param  string  $market  The market symbol
     * @param  string  $timeframe  The timeframe
     * @param  float  $brickSize  The brick size for Renko conversion
     * @param  callable|null  $progressCallback  Optional callback for progress updates
     * @return int Number of new bricks appended (0 = up to date, -1 = fell back to full conversion)
     */
    public function convertIncremental(
        string $exchange,
        string $market,
        string $timeframe,
        float $brickSize,
        ?callable $progressCallback = null
    ): int {
        $destPath = $this->generateRenkoFilePath($exchange, $market, $timeframe, $brickSize);

        if (! file_exists($destPath)) {
            $this->convert($exchange, $market, $timeframe, $brickSize, $progressCallback);

            return -1;
        }

        $destHeader = $this->binaryStorage->readHeader($destPath);

        if ($destHeader['numRecords'] === 0) {
            $this->convert($exchange, $market, $timeframe, $brickSize, $progressCallback);

            return -1;
        }

        $lastRecord = $this->binaryStorage->readRecordByIndex($destPath, $destHeader['numRecords'] - 1);

        if ($lastRecord === null) {
            $this->convert($exchange, $market, $timeframe, $brickSize, $progressCallback);

            return -1;
        }

        $lastTimestamp = $lastRecord['timestamp'];
        $currentPrice = $lastRecord['close'];
        $currentDirection = ($lastRecord['close'] > $lastRecord['open']) ? 1 : -1;

        $sourcePath = $this->fileService->generateFilePath($exchange, $market, $timeframe, 'ohlcv');

        $sourceRecords = iterator_to_array(
            $this->binaryStorage->readRecordsByTimestampRange($sourcePath, $lastTimestamp, PHP_INT_MAX)
        );

        if (count($sourceRecords) <= 1) {
            return 0;
        }

        $totalRecords = count($sourceRecords);
        $processedCount = 0;
        $convertedBricks = [];
        $firstBrickOverwritten = false;

        foreach ($sourceRecords as $record) {
            $processedCount++;

            if ($progressCallback !== null && $processedCount % 100 === 0) {
                $progressCallback($processedCount, $totalRecords);
            }

            if ($currentPrice === null) {
                $currentPrice = $record['close'];

                continue;
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
     * Check if a Renko file already exists.
     *
     * @param  string  $exchange  The exchange identifier
     * @param  string  $market  The market symbol
     * @param  string  $timeframe  The timeframe
     * @param  float  $brickSize  The brick size
     * @return bool True if the file exists
     */
    public function renkoFileExists(string $exchange, string $market, string $timeframe, float $brickSize): bool
    {
        $path = $this->generateRenkoFilePath($exchange, $market, $timeframe, $brickSize);

        return file_exists($path);
    }

    /**
     * Read the header from a Renko file.
     *
     * Delegates to the unified BinaryStorage::readHeader() which returns
     * all header fields including dataType and brickSize.
     *
     * @param  string  $filePath  The path to the Renko file
     * @return array The header information
     *
     * @throws StorageException If the file cannot be read
     */
    public function readRenkoHeader(string $filePath): array
    {
        return $this->binaryStorage->readHeader($filePath);
    }
}
