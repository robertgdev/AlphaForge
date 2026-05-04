<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Model\BacktestRun;
use App\AlphaForge\Backtesting\Service\BacktestResultFormatter;
use Illuminate\Console\Command;

class OptimizationResultCommand extends Command
{
    protected $signature = 'alphaforge:optimizations:result
        {backtest_id : The backtest run ID within an optimization}
        {--show-positions : Include positions in output}';

    protected $description = 'Show a specific backtest result from an optimization';

    public function handle(BacktestResultFormatter $formatter): int
    {
        $backtestId = $this->argument('backtest_id');

        $backtestRun = BacktestRun::with('optimization')->find($backtestId);

        if (! $backtestRun) {
            $this->error("Backtest run not found: $backtestId");
            return 1;
        }

        $this->line("<fg=yellow>Backtest Result Details</>");
        $this->line("  ID: {$backtestRun->id}");

        if ($backtestRun->optimization) {
            $this->line("  Optimization ID: " . substr($backtestRun->optimization->id, 0, 8));
        }

        $this->line("  Strategy: {$backtestRun->strategy_alias}");
        $this->line("  Symbol: " . ($backtestRun->symbols[0] ?? '-'));
        $this->line("  Timeframe: {$backtestRun->timeframe}");
        $this->line("  Status: {$backtestRun->status}");
        $this->newLine();

        $this->line("<fg=yellow>Parameters:</>");
        foreach ($backtestRun->strategy_inputs as $param => $value) {
            $this->line("  - $param: $value");
        }
        $this->newLine();

        if ($backtestRun->isCompleted()) {
            $stats = $backtestRun->statistics ?? [];

            $this->line("<fg=yellow>Statistics:</>");
            $this->line("  Initial Capital: " . number_format((float) $backtestRun->initial_capital, 2));
            $this->line("  Final Capital: " . number_format((float) $backtestRun->final_capital, 2));
            $this->line("  Net Profit: " . number_format((float) ($stats['total_return_percent'] ?? 0), 2) . "%");

            $formattedStats = $formatter->formatStatistics($stats);
            foreach ($formattedStats as $label => $value) {
                $this->line("  {$label}: {$value}");
            }
            $this->newLine();

            if ($this->option('show-positions') && ! empty($stats['positions'])) {
                $this->line("<fg=yellow>Positions:</>");
                $positionData = $formatter->formatPositions($stats['positions']);

                $this->table(
                    ['Symbol', 'Direction', 'Entry', 'Exit', 'PnL'],
                    $positionData
                );
            }
        } elseif ($backtestRun->hasFailed()) {
            $this->line("<fg=red>Error:</> {$backtestRun->error_message}");
        }

        return 0;
    }
}
