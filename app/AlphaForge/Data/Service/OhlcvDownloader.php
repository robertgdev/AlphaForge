<?php

namespace App\AlphaForge\Data\Service;

use App\AlphaForge\Data\Exception\DownloadCancelledException;
use App\AlphaForge\Data\Exception\DownloaderException;
use App\AlphaForge\Data\Exception\EmptyHistoryException;
use App\AlphaForge\Data\Service\Exchange\ExchangeAdapterInterface;
use App\AlphaForge\Services\MarketDataFileService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

use function Safe\filesize;

readonly class OhlcvDownloader
{
    public function __construct(
        private ExchangeAdapterInterface $exchangeAdapter,
        private BinaryStorageInterface $binaryStorage,
        private MarketDataFileService $marketDataFileService,
    ) {}

    /**
     * Downloads OHLCV market data from an exchange and stores it in binary format.
     *
     * @param  string  $exchangeId  The exchange identifier (e.g., 'binance', 'kraken')
     * @param  string  $symbol  The trading pair symbol (e.g., 'BTC/USDT')
     * @param  string  $timeframe  The timeframe (e.g., '1m', '1h', '1d')
     * @param  Carbon  $startTime  The start date/time for the data
     * @param  Carbon  $endTime  The end date/time for the data
     * @param  bool  $forceOverwrite  Whether to overwrite existing data
     * @param  string|null  $jobId  Optional job ID for cancellation support
     * @return string The path to the generated data file
     *
     * @throws DownloaderException
     * @throws DownloadCancelledException
     */
    public function download(
        string $exchangeId,
        string $symbol,
        string $timeframe,
        Carbon $startTime,
        Carbon $endTime,
        bool $forceOverwrite = false,
        ?string $jobId = null,
    ): string {
        $finalPath = $this->marketDataFileService->generateFilePath($exchangeId, $symbol, $timeframe);
        $attemptedTempFiles = [];
        $exception = null;

        try {
            $rangesToDownload = $this->calculateMissingRanges($finalPath, $startTime, $endTime, $timeframe, $forceOverwrite);

            if (empty($rangesToDownload)) {
                Log::info('Local data is already complete for the requested range. No download needed.', ['path' => $finalPath]);

                return $finalPath;
            }

            Log::info('Found {count} missing data range(s) to download.', ['count' => count($rangesToDownload)]);

            foreach ($rangesToDownload as $i => $range) {
                $chunkStartTime = $range[0];
                $chunkEndTime = $range[1];
                $tempPath = $this->binaryStorage->getTempFilePath($finalPath).".chunk.{$i}";
                $attemptedTempFiles[] = $tempPath;
                $this->marketDataFileService->cleanupFile($tempPath);

                try {
                    Log::info('Downloading chunk #{num}: {start} to {end}', [
                        'num' => $i + 1,
                        'start' => $chunkStartTime->format('Y-m-d H:i:s'),
                        'end' => $chunkEndTime->format('Y-m-d H:i:s'),
                    ]);
                    $this->downloadToTemp($exchangeId, $symbol, $timeframe, $chunkStartTime, $chunkEndTime, $tempPath, $jobId);

                } catch (EmptyHistoryException $e) {
                    Log::warning('Initial date {start_date} is too early. Attempting to find the earliest available data from the exchange.', [
                        'start_date' => $chunkStartTime->format('Y-m-d H:i:s'),
                    ]);
                    $firstAvailableDate = $this->exchangeAdapter->fetchFirstAvailableTimestamp($exchangeId, $symbol, $timeframe);

                    if ($firstAvailableDate !== null && $firstAvailableDate <= $chunkEndTime) {
                        Log::info('Found earliest data at {real_start}. Resuming download for the adjusted range.', [
                            'real_start' => $firstAvailableDate->format('Y-m-d H:i:s'),
                        ]);
                        $this->downloadToTemp($exchangeId, $symbol, $timeframe, $firstAvailableDate, $chunkEndTime, $tempPath, $jobId);
                    } else {
                        Log::warning('Could not determine a valid start date or the earliest data is outside the requested range for {symbol}. Skipping this chunk.', [
                            'symbol' => $symbol,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            $exception = $e;
        } finally {
            // Discover any valid temp files that were created, even if the process was interrupted.
            $validTempFiles = [];
            foreach ($attemptedTempFiles as $path) {
                if (file_exists($path) && filesize($path) > 64) {
                    $validTempFiles[] = $path;
                }
            }

            if (! empty($validTempFiles)) {
                Log::info('Merging {count} successfully downloaded chunk(s).', ['count' => count($validTempFiles)]);
                $this->mergeFiles(file_exists($finalPath) ? $finalPath : null, $validTempFiles, $finalPath);
            } elseif ($exception === null && ! file_exists($finalPath)) {
                // Only create an empty file if nothing was downloaded AND no error occurred.
                $this->binaryStorage->createFile($finalPath, $symbol, $timeframe);
            }

            // If an exception was caught, re-throw it now after cleanup/merging is done.
            if ($exception instanceof DownloadCancelledException) {
                Log::info('Download was cancelled by user. Progress has been saved.');
                throw $exception;
            } elseif ($exception !== null) {
                Log::error('Download failed: {message}.', ['message' => $exception->getMessage(), 'exception' => $exception]);
                throw new DownloaderException("Download failed for {$exchangeId}/{$symbol}: {$exception->getMessage()}", $exception->getCode(), $exception);
            }
        }

        return $finalPath;
    }

    /**
     * Calculate the missing data ranges that need to be downloaded.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    private function calculateMissingRanges(string $filePath, Carbon $requestStart, Carbon $requestEnd, string $timeframe, bool $forceOverwrite): array
    {
        $fileExists = file_exists($filePath) && filesize($filePath) > 64;

        if ($forceOverwrite || ! $fileExists) {
            return [[$requestStart, $requestEnd]];
        }

        $header = $this->binaryStorage->readHeader($filePath);

        if ($header['numRecords'] < 1) {
            return [[$requestStart, $requestEnd]];
        }

        $firstRecord = $this->binaryStorage->readRecordByIndex($filePath, 0);
        $lastRecord = $this->binaryStorage->readRecordByIndex($filePath, $header['numRecords'] - 1);

        $localStart = Carbon::createFromTimestamp($firstRecord['timestamp']);
        $localEnd = Carbon::createFromTimestamp($lastRecord['timestamp']);

        $rangesToDownload = [];

        // 1. Calculate the "before" chunk, correctly clipped by the request's end date.
        $beforeChunkStart = $requestStart;
        $beforeChunkEnd = $localStart->copy()->subSecond();
        if ($beforeChunkStart < $beforeChunkEnd) {
            $actualEnd = $requestEnd->min($beforeChunkEnd);
            if ($beforeChunkStart <= $actualEnd) {
                $rangesToDownload[] = [$beforeChunkStart, $actualEnd];
            }
        }

        // 2. Calculate internal gaps, correctly clipped by the request's date range.
        $internalGaps = $this->findGapsInFile($filePath, $timeframe);
        foreach ($internalGaps as $gap) {
            $downloadStart = $requestStart->max($gap[0]);
            $downloadEnd = $requestEnd->min($gap[1]);
            if ($downloadStart <= $downloadEnd) {
                $rangesToDownload[] = [$downloadStart, $downloadEnd];
            }
        }

        // 3. Calculate the "after" chunk, correctly clipped by the request's start date.
        $afterChunkStart = $localEnd->copy()->addSecond();
        $afterChunkEnd = $requestEnd;
        if ($afterChunkStart < $afterChunkEnd) {
            $actualStart = $requestStart->max($afterChunkStart);
            if ($actualStart <= $afterChunkEnd) {
                $rangesToDownload[] = [$actualStart, $afterChunkEnd];
            }
        }

        return $this->sortAndMergeRanges($rangesToDownload);
    }

    /**
     * Find gaps in the existing data file.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    private function findGapsInFile(string $filePath, string $timeframe): array
    {
        $expectedInterval = $this->timeframeToSeconds($timeframe);
        if ($expectedInterval === null) {
            return [];
        }

        $gaps = [];
        $records = $this->binaryStorage->readRecordsSequentially($filePath);
        $previousTimestamp = null;

        foreach ($records as $record) {
            $currentTimestamp = $record['timestamp'];
            if ($previousTimestamp !== null) {
                $diff = $currentTimestamp - $previousTimestamp;
                if ($diff > $expectedInterval) {
                    $gapStart = Carbon::createFromTimestamp($previousTimestamp + $expectedInterval);
                    $gapEnd = Carbon::createFromTimestamp($currentTimestamp - 1);
                    if ($gapStart <= $gapEnd) {
                        $gaps[] = [$gapStart, $gapEnd];
                    }
                }
            }
            $previousTimestamp = $currentTimestamp;
        }

        return $gaps;
    }

    /**
     * Merge multiple data files into a single final file.
     */
    private function mergeFiles(?string $originalPath, array $tempFiles, string $finalPath): void
    {
        $currentFileToMerge = $originalPath;

        foreach ($tempFiles as $i => $tempFile) {
            $mergedPath = $this->binaryStorage->getMergedTempFilePath($finalPath).".{$i}";
            if ($currentFileToMerge === null) {
                $this->binaryStorage->atomicRename($tempFile, $mergedPath);
            } else {
                $this->binaryStorage->mergeAndWrite($currentFileToMerge, $tempFile, $mergedPath);
            }

            if ($currentFileToMerge !== null && $currentFileToMerge !== $finalPath) {
                $this->marketDataFileService->cleanupFile($currentFileToMerge);
            }
            $this->marketDataFileService->cleanupFile($tempFile);
            $currentFileToMerge = $mergedPath;
        }

        if ($currentFileToMerge !== null) {
            $this->binaryStorage->atomicRename($currentFileToMerge, $finalPath);
        }
    }

    /**
     * Download data to a temporary file.
     */
    private function downloadToTemp(
        string $exchangeId,
        string $symbol,
        string $timeframe,
        Carbon $startTime,
        Carbon $endTime,
        string $tempPath,
        ?string $jobId = null,
    ): void {
        if (! $this->exchangeAdapter->supportsExchange($exchangeId)) {
            throw new DownloaderException("Exchange '{$exchangeId}' is not supported.");
        }

        $this->binaryStorage->createFile($tempPath, $symbol, $timeframe);
        $recordsGenerator = $this->exchangeAdapter->fetchOhlcv($exchangeId, $symbol, $timeframe, $startTime, $endTime, $jobId);

        $recordCount = $this->binaryStorage->streamAndCommitRecords($tempPath, $recordsGenerator);

        if ($recordCount > 0) {
            Log::info('Streamed and committed {count} records to temp file.', ['count' => $recordCount]);
        }
    }

    /**
     * Convert a timeframe string to seconds.
     */
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
     * Sort and merge overlapping date ranges.
     *
     * @param  array<int, array{0: Carbon, 1: Carbon}>  $ranges
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    private function sortAndMergeRanges(array $ranges): array
    {
        if (count($ranges) <= 1) {
            return $ranges;
        }

        usort($ranges, static fn ($a, $b) => $a[0]->timestamp <=> $b[0]->timestamp);
        $merged = [];
        $currentRange = array_shift($ranges);

        foreach ($ranges as $range) {
            if ($range[0]->timestamp <= $currentRange[1]->copy()->addSecond()->timestamp) {
                $currentRange[1] = $currentRange[1]->max($range[1]);
            } else {
                $merged[] = $currentRange;
                $currentRange = $range;
            }
        }

        $merged[] = $currentRange;

        return $merged;
    }
}
