<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Common\Service\DateParsingService;
use App\AlphaForge\Common\Service\FormattingService;
use App\AlphaForge\Console\Concerns\HasProgressBar;
use App\AlphaForge\Data\Exception\DataFileNotFoundException;
use App\AlphaForge\Data\Exception\DownloaderException;
use App\AlphaForge\Data\Service\BinaryStorage;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Data\Service\DataAvailabilityService;
use App\AlphaForge\Data\Service\DataInspectionService;
use App\AlphaForge\Data\Service\OhlcvDownloader;
use App\AlphaForge\Events\DownloadProgress;
use App\AlphaForge\Services\MarketDataFileService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Events\Dispatcher;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class DataCommand extends Command
{
    use HasProgressBar;

    protected $signature = 'alphaforge:data
        {action : The action to perform (import, export, delete, info, list, update)}
        {exchange? : The exchange identifier (e.g., binance, kraken). Required for import, delete, info, update.}
        {market? : The trading pair symbol (e.g., BTC/USDT). Required for delete, info, update.}
        {timeframe? : The timeframe (e.g., 1m, 5m, 1h, 1d). Required for delete, info, update.}
        {startdate? : The start date for data import (Y-m-d or Y-m-d H:i:s). Not required for update.}
        {enddate? : The end date for data import/update (Y-m-d or Y-m-d H:i:s, defaults to now)}
        {--force : Force overwrite existing data (for import) or skip confirmation (for delete)}
        {--exchange-filter= : Filter by exchange (for list action)}
        {--symbol-filter= : Filter by symbol (for list action)}';

    protected $description = 'Import, export, delete, update, or get info about market data from exchanges';

    protected int $totalDuration = 0;

    public function handle(
        OhlcvDownloader $downloader,
        MarketDataFileService $fileService,
        DataInspectionService $inspectionService,
        DataAvailabilityService $availabilityService,
        DateParsingService $dateParsingService,
        FormattingService $formattingService,
        BinaryStorageInterface $binaryStorage,
        Dispatcher $eventDispatcher
    ): int {
        $action = strtolower($this->argument('action'));
        $exchange = $this->argument('exchange');
        $market = $this->argument('market');
        $timeframe = $this->argument('timeframe');
        $force = $this->option('force');

        if (! in_array($action, ['import', 'export', 'delete', 'info', 'list', 'update'], true)) {
            error("Invalid action '{$action}'. Supported actions: import, export, delete, info, list, update");

            return self::FAILURE;
        }

        if (! in_array($action, ['list'], true) && ($exchange === null || $market === null || $timeframe === null)) {
            error('The exchange, market, and timeframe arguments are required for this action.');
            $this->line('Usage: php artisan alphaforge:data <action> <exchange> <market> <timeframe>');
            $this->line('Example: php artisan alphaforge:data import binance BTC/USDT 1h 2024-01-01');

            return self::FAILURE;
        }

        return match ($action) {
            'import' => $this->handleImport($downloader, $eventDispatcher, $dateParsingService, strtolower($exchange), strtoupper($market), $timeframe, $force),
            'delete' => $this->handleDelete($fileService, $formattingService, strtolower($exchange), strtoupper($market), $timeframe, $force),
            'info' => $this->handleInfo($inspectionService, $formattingService, strtolower($exchange), strtoupper($market), $timeframe),
            'export' => $this->handleExport(),
            'list' => $this->handleList($availabilityService, $formattingService),
            'update' => $this->handleUpdate($downloader, $fileService, $binaryStorage, $dateParsingService, $eventDispatcher, strtolower($exchange), strtoupper($market), $timeframe),
            default => self::FAILURE,
        };
    }

    private function handleImport(
        OhlcvDownloader $downloader,
        Dispatcher $eventDispatcher,
        DateParsingService $dateParsingService,
        string $exchange,
        string $market,
        string $timeframe,
        bool $force
    ): int {
        $startdate = $this->argument('startdate');
        $enddate = $this->argument('enddate');

        if ($startdate === null) {
            error('The startdate argument is required for import action.');

            return self::FAILURE;
        }

        try {
            $startCarbon = $dateParsingService->parseDate($startdate);
        } catch (\InvalidArgumentException $e) {
            error("Invalid start date format: {$startdate}. Use Y-m-d or Y-m-d H:i:s format.");

            return self::FAILURE;
        }

        try {
            $endCarbon = $enddate ? $dateParsingService->parseDate($enddate) : Carbon::now();
        } catch (\InvalidArgumentException $e) {
            error("Invalid end date format: {$enddate}. Use Y-m-d or Y-m-d H:i:s format.");

            return self::FAILURE;
        }

        if ($startCarbon->greaterThanOrEqualTo($endCarbon)) {
            error('Start date must be before end date.');

            return self::FAILURE;
        }

        $this->totalDuration = (int) $endCarbon->timestamp - (int) $startCarbon->timestamp;

        info('Starting market data import...');
        $this->newLine();
        $this->components->twoColumnDetail('Exchange', $exchange);
        $this->components->twoColumnDetail('Market', $market);
        $this->components->twoColumnDetail('Timeframe', $timeframe);
        $this->components->twoColumnDetail('Start Date', $startCarbon->format('Y-m-d H:i:s'));
        $this->components->twoColumnDetail('End Date', $endCarbon->format('Y-m-d H:i:s'));
        $this->components->twoColumnDetail('Force Overwrite', $force ? 'Yes' : 'No');
        $this->newLine();

        $eventDispatcher->listen(DownloadProgress::class, function (DownloadProgress $event) {
            $this->handleProgressEvent($event);
        });

        try {
            $this->startProgressBar('Downloading...');

            $filePath = $downloader->download(
                $exchange,
                $market,
                $timeframe,
                $startCarbon,
                $endCarbon,
                $force
            );

            $this->finishProgressBar();

            info('Market data imported successfully!');
            $this->components->twoColumnDetail('File Path', $filePath);

            return self::SUCCESS;
        } catch (DownloaderException $e) {
            $this->finishProgressBarOnError();
            error("Download failed: {$e->getMessage()}");

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->finishProgressBarOnError();
            error("Unexpected error: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            $eventDispatcher->forget(DownloadProgress::class);
        }
    }

    private function handleUpdate(
        OhlcvDownloader $downloader,
        MarketDataFileService $fileService,
        BinaryStorageInterface $binaryStorage,
        DateParsingService $dateParsingService,
        Dispatcher $eventDispatcher,
        string $exchange,
        string $market,
        string $timeframe
    ): int {
        $filePath = $fileService->generateFilePath($exchange, $market, $timeframe);

        if (! file_exists($filePath)) {
            error("No market data file found for {$exchange}/{$market}/{$timeframe}.");
            $this->line('Use the import action first:');
            $this->line("  php artisan alphaforge:data import {$exchange} {$market} {$timeframe} <startdate>");

            return self::FAILURE;
        }

        try {
            $header = $binaryStorage->readHeader($filePath);
        } catch (\Throwable $e) {
            error("Failed to read data file: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($header['numRecords'] === 0) {
            error('Data file exists but contains no records. Use the import action instead.');

            return self::FAILURE;
        }

        $lastRecord = $binaryStorage->readRecordByIndex($filePath, $header['numRecords'] - 1);

        if ($lastRecord === null) {
            error('Could not read the last record from the data file.');

            return self::FAILURE;
        }

        $startCarbon = Carbon::createFromTimestamp($lastRecord['timestamp']);

        $enddate = $this->argument('enddate');

        try {
            $endCarbon = $enddate ? $dateParsingService->parseDate($enddate) : Carbon::now();
        } catch (\InvalidArgumentException $e) {
            error("Invalid end date format: {$enddate}. Use Y-m-d or Y-m-d H:i:s format.");

            return self::FAILURE;
        }

        if ($startCarbon->greaterThanOrEqualTo($endCarbon)) {
            warning('Local data is already up to date. No update needed.');
            $this->components->twoColumnDetail('Last Record', $startCarbon->format('Y-m-d H:i:s'));

            return self::SUCCESS;
        }

        $this->totalDuration = (int) $endCarbon->timestamp - (int) $startCarbon->timestamp;

        info('Updating market data...');
        $this->newLine();
        $this->components->twoColumnDetail('Exchange', $exchange);
        $this->components->twoColumnDetail('Market', $market);
        $this->components->twoColumnDetail('Timeframe', $timeframe);
        $this->components->twoColumnDetail('Existing Data Up To', $startCarbon->format('Y-m-d H:i:s'));
        $this->components->twoColumnDetail('Updating To', $endCarbon->format('Y-m-d H:i:s'));
        $this->newLine();

        $eventDispatcher->listen(DownloadProgress::class, function (DownloadProgress $event) {
            $this->handleProgressEvent($event);
        });

        try {
            $this->startProgressBar('Downloading...');

            $resultPath = $downloader->download(
                $exchange,
                $market,
                $timeframe,
                $startCarbon,
                $endCarbon,
                false
            );

            $this->finishProgressBar();

            info('Market data updated successfully!');
            $this->components->twoColumnDetail('File Path', $resultPath);

            return self::SUCCESS;
        } catch (DownloaderException $e) {
            $this->finishProgressBarOnError();
            error("Update failed: {$e->getMessage()}");

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->finishProgressBarOnError();
            error("Unexpected error: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            $eventDispatcher->forget(DownloadProgress::class);
        }
    }

    private function handleDelete(
        MarketDataFileService $fileService,
        FormattingService $formattingService,
        string $exchange,
        string $market,
        string $timeframe,
        bool $force
    ): int {
        $filePath = $fileService->generateFilePath($exchange, $market, $timeframe);

        if (! file_exists($filePath)) {
            warning("No market data file found for {$exchange}/{$market}/{$timeframe}");
            $this->components->twoColumnDetail('Expected Path', $filePath);

            return self::FAILURE;
        }

        $fileSize = filesize($filePath);
        $fileSizeFormatted = $formattingService->formatFileSize($fileSize);
        $fileModified = date('Y-m-d H:i:s', filemtime($filePath));

        info('Market data file found:');
        $this->newLine();
        $this->components->twoColumnDetail('Exchange', $exchange);
        $this->components->twoColumnDetail('Market', $market);
        $this->components->twoColumnDetail('Timeframe', $timeframe);
        $this->components->twoColumnDetail('File Path', $filePath);
        $this->components->twoColumnDetail('File Size', $fileSizeFormatted);
        $this->components->twoColumnDetail('Last Modified', $fileModified);
        $this->newLine();

        if (! $force) {
            $confirmed = confirm(
                'Are you sure you want to delete this market data file?',
                false
            );

            if (! $confirmed) {
                warning('Delete operation cancelled.');

                return self::SUCCESS;
            }
        }

        try {
            $result = $fileService->deleteFile($filePath);

            if (! $result['deleted']) {
                error("Failed to delete file: {$filePath}");

                return self::FAILURE;
            }

            info('Market data file deleted successfully!');
            $this->components->twoColumnDetail('Deleted', $filePath);

            foreach ($result['removed_dirs'] as $removedDir) {
                $this->line("  Removed empty directory: {$removedDir}", 'comment');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            error("Error deleting file: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    private function handleExport(): int
    {
        warning("Action 'export' is not yet implemented.");

        return self::FAILURE;
    }

    private function handleList(DataAvailabilityService $availabilityService, FormattingService $formattingService): int
    {
        $exchangeFilter = $this->option('exchange-filter');
        $symbolFilter = $this->option('symbol-filter');

        $manifest = $availabilityService->getManifest();

        if (empty($manifest)) {
            info('No market data files found.');
            $this->line('Use the import action to download market data:');
            $this->line('  php artisan alphaforge:data import <exchange> <market> <timeframe> <startdate> [enddate]');

            return self::SUCCESS;
        }

        if ($exchangeFilter) {
            $manifest = array_filter($manifest, fn ($item) => stripos($item['exchange'], $exchangeFilter) !== false);
        }

        if ($symbolFilter) {
            $manifest = array_filter($manifest, fn ($item) => stripos($item['symbol'], $symbolFilter) !== false);
        }

        if (empty($manifest)) {
            warning('No market data files match the specified filters.');

            return self::SUCCESS;
        }

        $manifest = array_values($manifest);

        info('Available Market Data Files');
        $this->newLine();

        if ($exchangeFilter || $symbolFilter) {
            $filters = [];
            if ($exchangeFilter) {
                $filters[] = "exchange: {$exchangeFilter}";
            }
            if ($symbolFilter) {
                $filters[] = "symbol: {$symbolFilter}";
            }
            $this->components->twoColumnDetail('Filters', implode(', ', $filters));
            $this->newLine();
        }

        $totalFiles = 0;
        $rows = [];

        foreach ($manifest as $item) {
            $symbol = $item['symbol'];
            $exchange = $item['exchange'];
            $timeframes = $item['timeframes'];

            foreach ($timeframes as $tf) {
                $totalFiles++;
                $typeFormatted = $formattingService->formatDataTypeLabel(
                    $tf['dataType'] ?? BinaryStorage::DATA_TYPE_OHLCV,
                    $tf['brickSize'] ?? 0.0
                );
                $rows[] = [
                    $exchange,
                    $symbol,
                    $tf['timeframe'],
                    $typeFormatted,
                    $tf['recordCount'],
                    $tf['startDate'],
                    $tf['endDate'],
                ];
            }
        }

        table(
            ['Exchange', 'Symbol', 'Timeframe', 'Type', 'Records', 'Start Date', 'End Date'],
            $rows
        );

        $this->newLine();
        $this->components->twoColumnDetail('Total Files', (string) $totalFiles);
        $this->components->twoColumnDetail('Total Markets', (string) count($manifest));

        return self::SUCCESS;
    }

    private function handleInfo(
        DataInspectionService $inspectionService,
        FormattingService $formattingService,
        string $exchange,
        string $market,
        string $timeframe
    ): int {
        try {
            $data = $inspectionService->inspect($exchange, $market, $timeframe);
        } catch (DataFileNotFoundException $e) {
            warning("No market data found for {$exchange}/{$market}/{$timeframe}");
            $this->newLine();
            $this->components->twoColumnDetail('Exchange', $exchange);
            $this->components->twoColumnDetail('Market', $market);
            $this->components->twoColumnDetail('Timeframe', $timeframe);
            $this->newLine();
            info('Use the import action to download market data:');
            $this->line("  php artisan alphaforge:data import {$exchange} {$market} {$timeframe} <startdate> [enddate]");

            return self::FAILURE;
        } catch (\Throwable $e) {
            error("Failed to inspect market data: {$e->getMessage()}");

            return self::FAILURE;
        }

        $header = $data['header'];
        $recordCount = $header['numRecords'];
        $fileSize = $data['fileSize'];
        $validation = $data['validation'];

        $firstRecord = $data['sample']['head'][0] ?? null;
        $lastRecord = end($data['sample']['tail']) ?: ($data['sample']['head'][$recordCount - 1] ?? null);

        info('Market Data Information');
        $this->newLine();

        $this->components->twoColumnDetail('Exchange', $exchange);
        $this->components->twoColumnDetail('Market', $market);
        $this->components->twoColumnDetail('Timeframe', $timeframe);
        $this->components->twoColumnDetail('File Path', $data['filePath']);
        $this->newLine();

        $this->components->twoColumnDetail('<fg=yellow>File Statistics</>', '');
        $this->components->twoColumnDetail('  File Size', $formattingService->formatFileSize($fileSize));
        $this->components->twoColumnDetail('  Record Count', number_format($recordCount));
        $this->components->twoColumnDetail('  File Format Version', (string) $header['version']);
        $this->components->twoColumnDetail('  Header Size', $header['headerLength'].' bytes');
        $this->components->twoColumnDetail('  Record Size', $header['recordLength'].' bytes');
        $this->components->twoColumnDetail('  Data Type', $formattingService->formatDataTypeLabel($header['dataType']));
        if ($header['dataType'] === BinaryStorage::DATA_TYPE_RENKO) {
            $this->components->twoColumnDetail('  Brick Size', (string) $header['brickSize']);
        } elseif ($header['dataType'] === BinaryStorage::DATA_TYPE_ATR_RENKO) {
            $this->components->twoColumnDetail('  ATR Period', (string) (int) $header['brickSize']);
        }
        $this->newLine();

        if ($firstRecord && $lastRecord) {
            $this->components->twoColumnDetail('<fg=yellow>Date Range</>', '');
            $this->components->twoColumnDetail('  First Record', $firstRecord['utc']);
            $this->components->twoColumnDetail('  Last Record', $lastRecord['utc']);

            $firstTimestamp = $firstRecord['timestamp'];
            $lastTimestamp = $lastRecord['timestamp'];
            $timeSpanSeconds = $lastTimestamp - $firstTimestamp;
            $this->components->twoColumnDetail('  Time Span', $formattingService->formatTimeSpan($timeSpanSeconds));
            $this->newLine();
        }

        if ($recordCount > 0 && ($firstRecord || $lastRecord)) {
            $this->components->twoColumnDetail('<fg=yellow>Sample Data</>', '');

            if (! empty($data['sample']['head'])) {
                $this->line('  <fg=cyan>First Records:</>');
                foreach ($data['sample']['head'] as $i => $record) {
                    $this->line(sprintf(
                        '    [%d] %s | O: %s H: %s L: %s C: %s V: %s',
                        $i,
                        $record['utc'],
                        $formattingService->formatNumber($record['open']),
                        $formattingService->formatNumber($record['high']),
                        $formattingService->formatNumber($record['low']),
                        $formattingService->formatNumber($record['close']),
                        $formattingService->formatNumber($record['volume'])
                    ));
                }
            }

            if (! empty($data['sample']['tail'])) {
                $this->line('  <fg=cyan>Last Records:</>');
                $tailStartIndex = $recordCount - count($data['sample']['tail']);
                foreach ($data['sample']['tail'] as $i => $record) {
                    $this->line(sprintf(
                        '    [%d] %s | O: %s H: %s L: %s C: %s V: %s',
                        $tailStartIndex + $i,
                        $record['utc'],
                        $formattingService->formatNumber($record['open']),
                        $formattingService->formatNumber($record['high']),
                        $formattingService->formatNumber($record['low']),
                        $formattingService->formatNumber($record['close']),
                        $formattingService->formatNumber($record['volume'])
                    ));
                }
            }
            $this->newLine();
        }

        $this->components->twoColumnDetail('<fg=yellow>Data Validation</>', '');
        $validationStatus = match ($validation['status']) {
            'passed' => '<fg=green>✓ Passed</>',
            'failed' => '<fg=red>✗ Failed</>',
            default => '<fg=yellow>⊘ Skipped</>',
        };
        $this->components->twoColumnDetail('  Status', $validationStatus);
        $this->components->twoColumnDetail('  Message', $validation['message']);

        if ($validation['status'] === 'failed') {
            $gapCount = count($validation['gaps'] ?? []);
            $duplicateCount = count($validation['duplicates'] ?? []);
            $outOfOrderCount = count($validation['outOfOrder'] ?? []);

            if ($gapCount > 0) {
                $this->components->twoColumnDetail('  Gaps Found', (string) $gapCount);
            }
            if ($duplicateCount > 0) {
                $this->components->twoColumnDetail('  Duplicates Found', (string) $duplicateCount);
            }
            if ($outOfOrderCount > 0) {
                $this->components->twoColumnDetail('  Out of Order', (string) $outOfOrderCount);
            }
        }

        return self::SUCCESS;
    }

    private function handleProgressEvent(DownloadProgress $event): void
    {
        if ($this->progressBar === null) {
            return;
        }

        $percentComplete = $this->totalDuration > 0
            ? (int) round(($event->currentProgress / ($this->totalDuration * 1000)) * 100)
            : 0;

        $percentComplete = max(0, min(100, $percentComplete));

        $this->progressBar->setProgress($percentComplete);

        $dateStr = gmdate('Y-m-d H:i:s', $event->lastTimestamp);
        $this->progressBar->setMessage("Fetching: {$dateStr} ({$event->recordsFetchedInBatch} records)");
    }
}
