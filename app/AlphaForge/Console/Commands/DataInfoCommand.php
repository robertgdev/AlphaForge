<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Common\Service\FormattingService;
use App\AlphaForge\Console\Concerns\ParsesMarketDataArgs;
use App\AlphaForge\Data\Exception\DataFileNotFoundException;
use App\AlphaForge\Data\Service\BinaryStorage;
use App\AlphaForge\Data\Service\DataInspectionService;
use Illuminate\Console\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class DataInfoCommand extends Command
{
    use ParsesMarketDataArgs;

    protected $signature = 'alphaforge:data:info
        {exchange : The exchange identifier (e.g., binance, kraken)}
        {market : The trading pair symbol (e.g., BTC/USDT)}
        {timeframe : The timeframe (e.g., 1m, 5m, 1h, 1d)}';

    protected $description = 'Display information about a market data file';

    public function handle(
        DataInspectionService $inspectionService,
        FormattingService $formattingService
    ): int {
        $exchange = $this->parseExchange();
        $market = $this->parseMarket();
        $timeframe = $this->parseTimeframe();

        try {
            $data = $inspectionService->inspect($exchange, $market, $timeframe);
        } catch (DataFileNotFoundException $e) {
            warning("No market data found for {$exchange}/{$market}/{$timeframe}");
            $this->newLine();
            $this->displayMarketDataHeader($exchange, $market, $timeframe);
            info('Use the import command to download market data:');
            $this->line("  php artisan alphaforge:data:import {$exchange} {$market} {$timeframe} <startdate> [enddate]");

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
        $this->displayMarketDataHeader($exchange, $market, $timeframe, [
            'File Path' => $data['filePath'],
        ]);

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
}
