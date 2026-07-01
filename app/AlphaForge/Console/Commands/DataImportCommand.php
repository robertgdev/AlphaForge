<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Common\Service\DateParsingService;
use App\AlphaForge\Console\Concerns\HandlesDownloadProgress;
use App\AlphaForge\Console\Concerns\HasJsonOutput;
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
    use HasJsonOutput;
    use ParsesMarketDataArgs;

    protected $signature = 'alphaforge:data:import
        {exchange : The exchange identifier (e.g., binance, kraken)}
        {market : The trading pair symbol (e.g., BTC/USDT)}
        {timeframe : The timeframe (e.g., 1m, 5m, 1h, 1d)}
        {startdate : The start date for data import (Y-m-d or Y-m-d H:i:s)}
        {enddate? : The end date for data import (Y-m-d or Y-m-d H:i:s, defaults to now)}
        {--force : Force overwrite existing data}
        {--json : Output results as JSON}
        {--schema : Display command parameter schema as JSON}
        {--debug : Show peak memory usage on exit}';

    protected $description = 'Import market data from an exchange';

    public function handle(
        OhlcvDownloader $downloader,
        DateParsingService $dateParsingService,
        Dispatcher $eventDispatcher
    ): int {
        if (($code = $this->handleSchemaFlag()) !== null) {
            return $code;
        }

        $exchange = $this->parseExchange();
        $market = $this->parseMarket();
        $timeframe = $this->parseTimeframe();
        $force = $this->option('force');
        $startdate = $this->argument('startdate');
        $enddate = $this->argument('enddate');

        try {
            $startCarbon = $dateParsingService->parseDate($startdate);
        } catch (\InvalidArgumentException $e) {
            if ($this->jsonEnabled()) {
                return $this->outputJsonError("Invalid start date format: {$startdate}. Use Y-m-d or Y-m-d H:i:s format.");
            }

            error("Invalid start date format: {$startdate}. Use Y-m-d or Y-m-d H:i:s format.");

            return self::FAILURE;
        }

        try {
            $endCarbon = $this->parseEndDate($enddate, $dateParsingService);
        } catch (\InvalidArgumentException $e) {
            if ($this->jsonEnabled()) {
                return $this->outputJsonError("Invalid end date format: {$enddate}. Use Y-m-d or Y-m-d H:i:s format.");
            }

            error("Invalid end date format: {$enddate}. Use Y-m-d or Y-m-d H:i:s format.");

            return self::FAILURE;
        }

        if (! $this->validateDateRange($startCarbon, $endCarbon)) {
            return self::FAILURE;
        }

        $this->totalDuration = (int) $endCarbon->timestamp - (int) $startCarbon->timestamp;

        if (! $this->jsonEnabled()) {
            info('Starting market data import...');
            $this->newLine();
            $this->displayMarketDataHeader($exchange, $market, $timeframe, [
                'Start Date' => $startCarbon->format('Y-m-d H:i:s'),
                'End Date' => $endCarbon->format('Y-m-d H:i:s'),
                'Force Overwrite' => $force ? 'Yes' : 'No',
            ]);
        }

        $downloading = false;

        $eventDispatcher->listen(DownloadProgress::class, function (DownloadProgress $event) use (&$downloading) {
            if ($this->jsonEnabled()) {
                return;
            }
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

            if ($this->jsonEnabled()) {
                return $this->outputJson(true, [
                    'exchange' => $exchange,
                    'market' => $market,
                    'timeframe' => $timeframe,
                    'startDate' => $startCarbon->format('Y-m-d H:i:s'),
                    'endDate' => $endCarbon->format('Y-m-d H:i:s'),
                    'filePath' => $filePath,
                ]);
            }

            info('Market data imported successfully!');
            $this->components->twoColumnDetail('File Path', $filePath);

            return self::SUCCESS;
        } catch (DownloaderException $e) {
            if ($downloading) {
                $this->finishProgressBarOnError();
            }

            if ($this->jsonEnabled()) {
                return $this->outputJsonError("Download failed: {$e->getMessage()}");
            }

            error("Download failed: {$e->getMessage()}");

            return self::FAILURE;
        } catch (\Throwable $e) {
            if ($downloading) {
                $this->finishProgressBarOnError();
            }

            if ($this->jsonEnabled()) {
                return $this->outputJsonError("Unexpected error: {$e->getMessage()}");
            }

            error("Unexpected error: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            $eventDispatcher->forget(DownloadProgress::class);
        }
    }
}
