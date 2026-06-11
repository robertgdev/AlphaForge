<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Common\Service\DateParsingService;
use App\AlphaForge\Console\Concerns\HandlesDownloadProgress;
use App\AlphaForge\Console\Concerns\ParsesMarketDataArgs;
use App\AlphaForge\Data\Exception\DownloaderException;
use App\AlphaForge\Data\Service\OhlcvDownloader;
use App\AlphaForge\Events\DownloadProgress;
use Illuminate\Console\Command;
use Illuminate\Events\Dispatcher;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class DataImportCommand extends Command
{
    use HandlesDownloadProgress;
    use ParsesMarketDataArgs;

    protected $signature = 'alphaforge:data:import
        {exchange : The exchange identifier (e.g., binance, kraken)}
        {market : The trading pair symbol (e.g., BTC/USDT)}
        {timeframe : The timeframe (e.g., 1m, 5m, 1h, 1d)}
        {startdate : The start date for data import (Y-m-d or Y-m-d H:i:s)}
        {enddate? : The end date for data import (Y-m-d or Y-m-d H:i:s, defaults to now)}
        {--force : Force overwrite existing data}';

    protected $description = 'Import market data from an exchange';

    public function handle(
        OhlcvDownloader $downloader,
        DateParsingService $dateParsingService,
        Dispatcher $eventDispatcher
    ): int {
        $exchange = $this->parseExchange();
        $market = $this->parseMarket();
        $timeframe = $this->parseTimeframe();
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
            $endCarbon = $this->parseEndDate($enddate, $dateParsingService);
        } catch (\InvalidArgumentException $e) {
            error("Invalid end date format: {$enddate}. Use Y-m-d or Y-m-d H:i:s format.");

            return self::FAILURE;
        }

        if (! $this->validateDateRange($startCarbon, $endCarbon)) {
            return self::FAILURE;
        }

        $this->totalDuration = (int) $endCarbon->timestamp - (int) $startCarbon->timestamp;

        info('Starting market data import...');
        $this->newLine();
        $this->displayMarketDataHeader($exchange, $market, $timeframe, [
            'Start Date' => $startCarbon->format('Y-m-d H:i:s'),
            'End Date' => $endCarbon->format('Y-m-d H:i:s'),
            'Force Overwrite' => $force ? 'Yes' : 'No',
        ]);

        $downloading = false;

        $eventDispatcher->listen(DownloadProgress::class, function (DownloadProgress $event) use (&$downloading) {
            if (! $downloading) {
                $this->startProgressBar('Downloading...');
                $downloading = true;
            }
            $this->handleProgressEvent($event);
        });

        try {
            $filePath = $downloader->download(
                $exchange,
                $market,
                $timeframe,
                $startCarbon,
                $endCarbon,
                $force
            );

            if ($downloading) {
                $this->finishProgressBar();
            }

            info('Market data imported successfully!');
            $this->components->twoColumnDetail('File Path', $filePath);

            return self::SUCCESS;
        } catch (DownloaderException $e) {
            if ($downloading) {
                $this->finishProgressBarOnError();
            }
            error("Download failed: {$e->getMessage()}");

            return self::FAILURE;
        } catch (\Throwable $e) {
            if ($downloading) {
                $this->finishProgressBarOnError();
            }
            error("Unexpected error: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            $eventDispatcher->forget(DownloadProgress::class);
        }
    }
}
