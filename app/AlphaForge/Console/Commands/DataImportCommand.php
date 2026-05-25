<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Common\Service\DateParsingService;
use App\AlphaForge\Console\Concerns\HasProgressBar;
use App\AlphaForge\Data\Exception\DownloaderException;
use App\AlphaForge\Data\Service\OhlcvDownloader;
use App\AlphaForge\Events\DownloadProgress;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Events\Dispatcher;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class DataImportCommand extends Command
{
    use HasProgressBar;

    protected $signature = 'alphaforge:data:import
        {exchange : The exchange identifier (e.g., binance, kraken)}
        {market : The trading pair symbol (e.g., BTC/USDT)}
        {timeframe : The timeframe (e.g., 1m, 5m, 1h, 1d)}
        {startdate : The start date for data import (Y-m-d or Y-m-d H:i:s)}
        {enddate? : The end date for data import (Y-m-d or Y-m-d H:i:s, defaults to now)}
        {--force : Force overwrite existing data}';

    protected $description = 'Import market data from an exchange';

    protected int $totalDuration = 0;

    public function handle(
        OhlcvDownloader $downloader,
        DateParsingService $dateParsingService,
        Dispatcher $eventDispatcher
    ): int {
        $exchange = strtolower($this->argument('exchange'));
        $market = strtoupper($this->argument('market'));
        $timeframe = $this->argument('timeframe');
        $force = $this->option('force');
        $startdate = $this->argument('startdate');
        $enddate = $this->argument('enddate');

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
