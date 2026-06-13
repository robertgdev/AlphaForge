<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Data\Service\DataAvailabilityService;
use App\AlphaForge\Data\Service\DataRepairService;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use App\AlphaForge\Console\Commands\Concerns\DebugMemory;
use function Laravel\Prompts\warning;

class RepairDataCommand extends Command
{
    use DebugMemory;

    protected $signature = 'alphaforge:data:repair
        {--dry-run : Show what would be fixed without making changes}
        {--exchange-filter= : Filter by exchange (e.g., binance)}
        {--symbol-filter= : Filter by symbol (e.g., BTCUSDT)}
        {--debug : Show peak memory usage on exit}';

    protected $description = 'Repair corrupted market data files by fixing header record counts';

    public function handle(
        DataRepairService $repairService,
        DataAvailabilityService $availabilityService
    ): int {
        $dryRun = $this->option('dry-run');
        $exchangeFilter = $this->option('exchange-filter');
        $symbolFilter = $this->option('symbol-filter');

        if ($dryRun) {
            info('Running in DRY-RUN mode - no changes will be made');
            $this->newLine();
        }

        $manifest = $availabilityService->getManifest();

        if (empty($manifest)) {
            info('No market data files found to repair.');

            $this->debugMemory();

            return self::SUCCESS;
        }

        $totalFiles = 0;
        $corruptedFiles = 0;
        $fixedFiles = 0;

        foreach ($manifest as $item) {
            $exchange = $item['exchange'];
            $symbol = $item['symbol'];

            if ($exchangeFilter && stripos($exchange, $exchangeFilter) === false) {
                continue;
            }

            if ($symbolFilter && stripos($symbol, $symbolFilter) === false) {
                continue;
            }

            foreach ($item['timeframes'] as $tf) {
                $totalFiles++;
                $timeframe = $tf['timeframe'];

                $result = $repairService->checkAndRepairFile($exchange, $symbol, $timeframe, $dryRun);

                if ($result['status'] === 'ok') {
                    $this->line("  <fg=green>✓</> {$result['message']}");
                } elseif ($result['status'] === 'corrupted') {
                    $this->line("  <fg=red>✗</> {$result['message']}");
                    $this->line("      <fg=yellow>Would fix by updating header to: {$result['actual_count']}</>");
                    $corruptedFiles++;
                } elseif ($result['status'] === 'fixed') {
                    $this->line("  <fg=red>✗</> {$result['message']}");
                    $this->line('      <fg=green>Fixed!</>');
                    $fixedFiles++;
                } elseif ($result['status'] === 'error') {
                    warning($result['message']);
                }
            }
        }

        $this->newLine();

        if ($dryRun) {
            info('Dry-run completed.');
        } else {
            info('Repair completed.');
        }

        $this->components->twoColumnDetail('Total Files Scanned', (string) $totalFiles);
        $this->components->twoColumnDetail('Corrupted Files Found', (string) $corruptedFiles);
        $this->components->twoColumnDetail('Files Fixed', (string) $fixedFiles);

        if ($corruptedFiles > 0 && ! $dryRun) {
            $this->newLine();
            warning('Consider re-running with --dry-run first to preview changes.');
        }

        $this->debugMemory();

        return self::SUCCESS;
    }
}
