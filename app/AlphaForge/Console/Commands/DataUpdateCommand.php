<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Common\Service\DateParsingService;
use App\AlphaForge\Console\Concerns\HandlesDownloadProgress;
use App\AlphaForge\Console\Concerns\ParsesMarketDataArgs;
use App\AlphaForge\Conversion\AtrRenkoConverter;
use App\AlphaForge\Conversion\HeikenAshiConverter;
use App\AlphaForge\Conversion\RenkoConverter;
use App\AlphaForge\Data\Exception\DownloaderException;
use App\AlphaForge\Data\Service\BinaryStorage;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Data\Service\DataAvailabilityService;
use App\AlphaForge\Services\DataAutoGenerator;
use App\AlphaForge\Data\Service\OhlcvDownloader;
use App\AlphaForge\Events\DownloadProgress;
use App\AlphaForge\Services\MarketDataFileService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Events\Dispatcher;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class DataUpdateCommand extends Command
{
    use HandlesDownloadProgress;
    use ParsesMarketDataArgs;

    protected $signature = 'alphaforge:data:update
        {exchange : The exchange identifier (e.g., binance, kraken)}
        {market : The trading pair symbol (e.g., BTC/USDT)}
        {timeframe : The timeframe (e.g., 1m, 5m, 1h, 1d)}
        {enddate? : The end date for update (Y-m-d or Y-m-d H:i:s, defaults to now)}
        {--with-dependencies : Also update all derived data files (Renko, Heiken-Ashi, etc.)}
        {--auto-generate : Auto-generate derived data files that do not exist (Renko, Heiken-Ashi, ATR-Renko, aggregated OHLCV)}';

    protected $description = 'Update market data to the latest available';

    public function handle(
        OhlcvDownloader $downloader,
        MarketDataFileService $fileService,
        BinaryStorageInterface $binaryStorage,
        DataAvailabilityService $availabilityService,
        DateParsingService $dateParsingService,
        Dispatcher $eventDispatcher,
        DataAutoGenerator $dataAutoGenerator
    ): int {
        $exchange = $this->parseExchange();
        $market = $this->parseMarket();
        $timeframe = $this->parseTimeframe();
        $withDependencies = $this->option('with-dependencies');
        $enddate = $this->argument('enddate');

        $filePath = $fileService->generateFilePath($exchange, $market, $timeframe);

        if (! file_exists($filePath)) {
            if ($this->option('auto-generate')) {
                $this->line('Auto-generate enabled — trying to derive OHLCV data...');
                $genResult = $dataAutoGenerator->autoGenerate(
                    \App\AlphaForge\Backtesting\Dto\DataTypeConfig::fromOptions('ohlcv', null, null),
                    $exchange,
                    $market,
                    $timeframe,
                    additionalTimeframes: [],
                    output: fn (string $msg) => $this->line("  {$msg}"),
                );

                foreach ($genResult['generated'] as $path) {
                    $this->line("  Generated: {$path}");
                }

                foreach ($genResult['errors'] as $err) {
                    error($err);
                }

                if (! empty($genResult['errors'])) {
                    $this->line('Download source data first:');
                    $this->line("  php artisan alphaforge:data:import {$exchange} {$market} <lower_timeframe> <startdate>");

                    return self::FAILURE;
                }

                $this->newLine();
            } else {
                error("No market data file found for {$exchange}/{$market}/{$timeframe}.");
                $this->line('Use the import command first:');
                $this->line("  php artisan alphaforge:data:import {$exchange} {$market} {$timeframe} <startdate>");
                $this->line('Or use --auto-generate to derive from a lower timeframe if available.');

                return self::FAILURE;
            }
        }

        try {
            $header = $binaryStorage->readHeader($filePath);
        } catch (\Throwable $e) {
            error("Failed to read data file: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($header['numRecords'] === 0) {
            error('Data file exists but contains no records. Use the import command instead.');

            return self::FAILURE;
        }

        $lastRecord = $binaryStorage->readRecordByIndex($filePath, $header['numRecords'] - 1);

        if ($lastRecord === null) {
            error('Could not read the last record from the data file.');

            return self::FAILURE;
        }

        $startCarbon = Carbon::createFromTimestamp($lastRecord['timestamp']);

        try {
            $endCarbon = $this->parseEndDate($enddate, $dateParsingService);
        } catch (\InvalidArgumentException $e) {
            error("Invalid end date format: {$enddate}. Use Y-m-d or Y-m-d H:i:s format.");

            return self::FAILURE;
        }

        if ($startCarbon->greaterThanOrEqualTo($endCarbon)) {
            warning('Local data is already up to date. No update needed.');
            $this->components->twoColumnDetail('Last Record', $startCarbon->format('Y-m-d H:i:s'));

            if ($withDependencies) {
                return $this->updateDependencies($availabilityService, $exchange, $market, $timeframe);
            }

            return self::SUCCESS;
        }

        $this->totalDuration = (int) $endCarbon->timestamp - (int) $startCarbon->timestamp;

        info('Updating market data...');
        $this->newLine();
        $this->displayMarketDataHeader($exchange, $market, $timeframe, [
            'Existing Data Up To' => $startCarbon->format('Y-m-d H:i:s'),
            'Updating To' => $endCarbon->format('Y-m-d H:i:s'),
        ]);

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

            if ($withDependencies) {
                return $this->updateDependencies($availabilityService, $exchange, $market, $timeframe);
            }

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

    private function updateDependencies(
        DataAvailabilityService $availabilityService,
        string $exchange,
        string $market,
        string $timeframe
    ): int {
        $dependencies = $availabilityService->findDependencies($exchange, $market, $timeframe);

        if (empty($dependencies)) {
            $this->newLine();
            info('No dependent data files found. Nothing to update.');

            return self::SUCCESS;
        }

        $this->newLine();
        info('Updating dependent data files...');
        $this->newLine();

        $results = [];
        $hasFailure = false;

        foreach ($dependencies as $dependency) {
            $dataType = $dependency['dataType'];
            $brickSize = $dependency['brickSize'];
            $typeLabel = $this->formatDependencyLabel($dataType, $brickSize);

            $this->line("  <fg=cyan>Updating: {$typeLabel}</>");

            try {
                $converter = $this->resolveConverter($dataType);
                $newRecordsCount = $this->performIncrementalUpdate(
                    $converter,
                    $dataType,
                    $exchange,
                    $market,
                    $timeframe,
                    $brickSize,
                    $typeLabel
                );

                if ($newRecordsCount === -1) {
                    $results[] = ['label' => $typeLabel, 'status' => 'full', 'count' => 0];
                    $this->line('    <fg=green>Completed (full conversion performed)</>');
                } elseif ($newRecordsCount === 0) {
                    $results[] = ['label' => $typeLabel, 'status' => 'uptodate', 'count' => 0];
                    $this->line('    <fg=yellow>Already up to date</>');
                } else {
                    $results[] = ['label' => $typeLabel, 'status' => 'updated', 'count' => $newRecordsCount];
                    $this->line('    <fg=green>+ '.number_format($newRecordsCount).' new records</>');
                }
            } catch (\Throwable $e) {
                $results[] = ['label' => $typeLabel, 'status' => 'failed', 'count' => 0];
                $this->line("    <fg=red>Failed: {$e->getMessage()}</>");
                $hasFailure = true;
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>Dependency Update Summary</>', '');

        $updated = 0;
        $upToDate = 0;
        $fullConversions = 0;
        $failed = 0;

        foreach ($results as $result) {
            $statusLabel = match ($result['status']) {
                'updated' => '<fg=green>Updated</>',
                'full' => '<fg=cyan>Full Conversion</>',
                'uptodate' => '<fg=yellow>Up to Date</>',
                'failed' => '<fg=red>Failed</>',
            };

            match ($result['status']) {
                'updated' => $updated++,
                'full' => $fullConversions++,
                'uptodate' => $upToDate++,
                'failed' => $failed++,
            };

            $this->components->twoColumnDetail("  {$result['label']}", $statusLabel);
        }

        $this->newLine();
        $totalCount = count($results);
        $this->components->twoColumnDetail('Total Dependencies', (string) $totalCount);
        $this->components->twoColumnDetail('Updated', (string) $updated);
        $this->components->twoColumnDetail('Up to Date', (string) $upToDate);
        $this->components->twoColumnDetail('Full Conversions', (string) $fullConversions);

        if ($failed > 0) {
            $this->components->twoColumnDetail('Failed', (string) $failed);
        }

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }

    private function resolveConverter(int $dataType): RenkoConverter|AtrRenkoConverter|HeikenAshiConverter
    {
        return match ($dataType) {
            BinaryStorage::DATA_TYPE_RENKO => $this->laravel->make(RenkoConverter::class),
            BinaryStorage::DATA_TYPE_ATR_RENKO => $this->laravel->make(AtrRenkoConverter::class),
            BinaryStorage::DATA_TYPE_HEIKEN_ASHI => $this->laravel->make(HeikenAshiConverter::class),
            default => throw new \InvalidArgumentException("Unsupported data type: {$dataType}"),
        };
    }

    private function performIncrementalUpdate(
        RenkoConverter|AtrRenkoConverter|HeikenAshiConverter $converter,
        int $dataType,
        string $exchange,
        string $market,
        string $timeframe,
        float $brickSize,
        string $typeLabel
    ): int {
        $progressCallback = function (int $current, int $total) {
            $this->updateProgress($current, $total);
        };

        $this->startProgressBar("Converting {$typeLabel}...");

        try {
            $result = match ($dataType) {
                BinaryStorage::DATA_TYPE_RENKO => $converter->convertIncremental(
                    $exchange,
                    $market,
                    $timeframe,
                    $brickSize,
                    $progressCallback
                ),
                BinaryStorage::DATA_TYPE_ATR_RENKO => $converter->convertIncremental(
                    $exchange,
                    $market,
                    $timeframe,
                    (int) $brickSize,
                    $progressCallback
                ),
                BinaryStorage::DATA_TYPE_HEIKEN_ASHI => $converter->convertIncremental(
                    $exchange,
                    $market,
                    $timeframe,
                    $progressCallback
                ),
                default => throw new \InvalidArgumentException("Unsupported data type: {$dataType}"),
            };

            $this->finishProgressBar();

            return $result;
        } catch (\Throwable $e) {
            $this->finishProgressBarOnError();

            throw $e;
        }
    }

    private function formatDependencyLabel(int $dataType, float $brickSize): string
    {
        return match ($dataType) {
            BinaryStorage::DATA_TYPE_RENKO => "Renko (brick: {$brickSize})",
            BinaryStorage::DATA_TYPE_ATR_RENKO => 'ATR-Renko (period: '.(int) $brickSize.')',
            BinaryStorage::DATA_TYPE_HEIKEN_ASHI => 'Heiken-Ashi',
            default => "Unknown (type: {$dataType})",
        };
    }
}
