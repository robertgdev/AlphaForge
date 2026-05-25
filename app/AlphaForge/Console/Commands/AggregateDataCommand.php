<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Services\AggregateDataService;
use App\AlphaForge\Services\MarketDataFileService;
use Illuminate\Console\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class AggregateDataCommand extends Command
{
    protected $signature = 'alphaforge:data:aggregate
        {exchange : The exchange identifier (e.g., binance, kraken)}
        {symbol : The trading pair symbol (e.g., BTC/USDT)}
        {source_timeframe : The source timeframe to aggregate from (e.g., 1m, 5m, 15m)}
        {target_timeframe : The target timeframe to aggregate to (e.g., 1h, 4h, 1d)}
        {--force : Force overwrite if target file already exists}
        {--update : Incrementally update the target file by appending new aggregated data}';

    protected $description = 'Aggregate OHLCV data from a lower timeframe to a higher timeframe';

    public function handle(
        AggregateDataService $aggregateDataService,
        MarketDataFileService $fileService
    ): int {
        $exchange = strtolower($this->argument('exchange'));
        $symbol = strtoupper($this->argument('symbol'));
        $sourceTimeframeValue = $this->argument('source_timeframe');
        $targetTimeframeValue = $this->argument('target_timeframe');
        $force = $this->option('force');
        $update = $this->option('update');

        $sourceTimeframe = TimeframeEnum::tryFrom($sourceTimeframeValue);
        if ($sourceTimeframe === null) {
            error("Invalid source timeframe '{$sourceTimeframeValue}'. Valid values: 1m, 5m, 15m, 30m, 1h, 4h, 1d");

            return self::FAILURE;
        }

        $targetTimeframe = TimeframeEnum::tryFrom($targetTimeframeValue);
        if ($targetTimeframe === null) {
            error("Invalid target timeframe '{$targetTimeframeValue}'. Valid values: 1m, 5m, 15m, 30m, 1h, 4h, 1d");

            return self::FAILURE;
        }

        if ($sourceTimeframe->toSeconds() >= $targetTimeframe->toSeconds()) {
            error('Target timeframe must be higher (larger) than source timeframe.');

            return self::FAILURE;
        }

        $ratio = $targetTimeframe->toSeconds() / $sourceTimeframe->toSeconds();
        if ($ratio != (int) $ratio) {
            error("Cannot aggregate from {$sourceTimeframeValue} to {$targetTimeframeValue}. Ratio must be a whole number.");

            return self::FAILURE;
        }

        $sourcePath = $fileService->generateFilePath($exchange, $symbol, $sourceTimeframeValue, 'ohlcv');
        $targetPath = $fileService->generateFilePath($exchange, $symbol, $targetTimeframeValue, 'ohlcv');

        if (! file_exists($sourcePath)) {
            warning("Source file not found: {$sourcePath}");
            $this->line('Use the import action to download market data:');
            $this->line("  php artisan alphaforge:data:import {$exchange} {$symbol} {$sourceTimeframeValue} <startdate>");

            return self::FAILURE;
        }

        if ($update && $force) {
            error('Cannot use --update and --force together. --update appends to existing data; --force overwrites it.');

            return self::FAILURE;
        }

        if (file_exists($targetPath) && ! $force && ! $update) {
            warning("Target file already exists: {$targetPath}");
            $this->line('Use --force to overwrite, or --update to append new data.');

            return self::FAILURE;
        }

        if ($update && ! file_exists($targetPath)) {
            info('Target file does not exist. Performing full aggregation instead.');
            $update = false;
        }

        $this->displayConfiguration($exchange, $symbol, $sourceTimeframeValue, $targetTimeframeValue, $sourcePath, $targetPath, $update);

        try {
            if ($update) {
                $this->info('Starting incremental aggregation...');
                $this->newLine();

                $aggregatedCount = $aggregateDataService->aggregateIncremental(
                    $sourcePath,
                    $targetPath,
                    $symbol,
                    $sourceTimeframe,
                    $targetTimeframe
                );

                if ($aggregatedCount === 0) {
                    $this->newLine();
                    warning('No new data to aggregate. Target file is already up to date.');

                    return self::SUCCESS;
                }
            } else {
                $this->info('Starting aggregation...');
                $this->newLine();

                $aggregatedCount = $aggregateDataService->aggregateData(
                    $sourcePath,
                    $targetPath,
                    $symbol,
                    $sourceTimeframe,
                    $targetTimeframe
                );
            }

            $this->newLine();
            info('Aggregation completed successfully!');
            $this->components->twoColumnDetail('Records Aggregated', number_format($aggregatedCount));
            $this->components->twoColumnDetail('Output File', $targetPath);

            return self::SUCCESS;

        } catch (\Throwable $e) {
            error('Aggregation failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function displayConfiguration(
        string $exchange,
        string $symbol,
        string $sourceTimeframe,
        string $targetTimeframe,
        string $sourcePath,
        string $targetPath,
        bool $update = false
    ): void {
        info($update ? 'Timeframe Aggregation (Incremental Update)' : 'Timeframe Aggregation');
        $this->newLine();

        $this->components->twoColumnDetail('Exchange', $exchange);
        $this->components->twoColumnDetail('Symbol', $symbol);
        $this->components->twoColumnDetail('Source Timeframe', $sourceTimeframe);
        $this->components->twoColumnDetail('Target Timeframe', $targetTimeframe);
        $this->newLine();

        $sourceSeconds = TimeframeEnum::from($sourceTimeframe)->toSeconds();
        $targetSeconds = TimeframeEnum::from($targetTimeframe)->toSeconds();
        $ratio = $targetSeconds / $sourceSeconds;
        $this->components->twoColumnDetail('Aggregation Ratio', "{$ratio}x ({$sourceTimeframe} → {$targetTimeframe})");
        $this->newLine();

        $this->components->twoColumnDetail('Source File', $sourcePath);
        $this->components->twoColumnDetail('Target File', $targetPath);
        $this->newLine();
    }
}
