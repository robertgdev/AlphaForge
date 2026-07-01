<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Common\Service\FormattingService;
use App\AlphaForge\Console\Concerns\HasJsonOutput;
use App\AlphaForge\Data\Service\BinaryStorage;
use App\AlphaForge\Data\Service\DataAvailabilityService;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class DataListCommand extends Command
{
    use HasJsonOutput;

    protected $signature = 'alphaforge:data:list
        {--exchange-filter= : Filter by exchange}
        {--symbol-filter= : Filter by symbol}
        {--json : Output results as JSON}
        {--schema : Display command parameter schema as JSON}
        {--debug : Show peak memory usage on exit}';

    protected $description = 'List all available market data files';

    public function handle(
        DataAvailabilityService $availabilityService,
        FormattingService $formattingService
    ): int {
        if (($code = $this->handleSchemaFlag()) !== null) {
            return $code;
        }

        $exchangeFilter = $this->option('exchange-filter');
        $symbolFilter = $this->option('symbol-filter');

        $manifest = $availabilityService->getManifest();

        if (empty($manifest)) {
            if ($this->jsonEnabled()) {
                return $this->outputJson(true, ['files' => [], 'totals' => ['files' => 0, 'markets' => 0]]);
            }

            info('No market data files found.');
            $this->line('Use the import command to download market data:');
            $this->line('  php artisan alphaforge:data:import <exchange> <market> <timeframe> <startdate> [enddate]');

            return self::SUCCESS;
        }

        if ($exchangeFilter) {
            $manifest = array_filter($manifest, fn ($item) => stripos($item['exchange'], $exchangeFilter) !== false);
        }

        if ($symbolFilter) {
            $manifest = array_filter($manifest, fn ($item) => stripos($item['symbol'], $symbolFilter) !== false);
        }

        if (empty($manifest)) {
            if ($this->jsonEnabled()) {
                return $this->outputJson(true, ['files' => [], 'totals' => ['files' => 0, 'markets' => 0]]);
            }

            warning('No market data files match the specified filters.');

            return self::SUCCESS;
        }

        $manifest = array_values($manifest);

        $totalFiles = 0;
        $rows = [];
        $files = [];

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
                $files[] = [
                    'exchange' => $exchange,
                    'symbol' => $symbol,
                    'timeframe' => $tf['timeframe'],
                    'type' => $typeFormatted,
                    'records' => $tf['recordCount'],
                    'startDate' => $tf['startDate'],
                    'endDate' => $tf['endDate'],
                ];
            }
        }

        if ($this->jsonEnabled()) {
            return $this->outputJson(true, [
                'files' => $files,
                'totals' => ['files' => $totalFiles, 'markets' => count($manifest)],
            ]);
        }

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

        table(
            ['Exchange', 'Symbol', 'Timeframe', 'Type', 'Records', 'Start Date', 'End Date'],
            $rows
        );

        $this->newLine();
        $this->components->twoColumnDetail('Total Files', (string) $totalFiles);
        $this->components->twoColumnDetail('Total Markets', (string) count($manifest));

        return self::SUCCESS;
    }
}
