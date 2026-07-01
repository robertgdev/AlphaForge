<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Model\BacktestRun;
use App\AlphaForge\Backtesting\Service\BacktestResultFormatter;
use App\AlphaForge\Console\Concerns\HasJsonOutput;
use Illuminate\Console\Command;

class OptimizationResultCommand extends Command
{
    use HasJsonOutput;

    protected $signature = 'alphaforge:optimizations:result
        {backtest_id : The backtest run ID within an optimization}
        {--show-positions : Include positions in output}
        {--json : Output results as JSON}
        {--schema : Display command parameter schema as JSON}
        {--debug : Show peak memory usage on exit}';

    protected $description = 'Show a specific backtest result from an optimization';

    public function handle(BacktestResultFormatter $formatter): int
    {
        if (($code = $this->handleSchemaFlag()) !== null) {
            return $code;
        }

        $backtestId = $this->argument('backtest_id');

        $backtestRun = BacktestRun::with('optimization')->find($backtestId);

        if (! $backtestRun) {
            return $this->outputJsonError("Backtest run not found: $backtestId");
        }

        if ($this->jsonEnabled()) {
            return $this->outputJson(true, [
                'backtestId' => $backtestRun->id,
                'strategy' => $backtestRun->strategy_alias,
                'symbol' => $backtestRun->symbols[0] ?? null,
                'timeframe' => $backtestRun->timeframe,
                'status' => $backtestRun->status,
                'parameters' => $backtestRun->strategy_inputs,
                'initialCapital' => (float) $backtestRun->initial_capital,
                'finalCapital' => (float) $backtestRun->final_capital,
                'statistics' => $backtestRun->statistics,
            ]);
        }

        $this->line('<fg=yellow>Backtest Result Details</>');
        $this->line("  ID: {$backtestRun->id}");

        if ($backtestRun->optimization) {
            $this->line('  Optimization ID: '.substr($backtestRun->optimization->id, 0, 8));
        }

        $this->line("  Strategy: {$backtestRun->strategy_alias}");
        $this->line('  Symbol: '.($backtestRun->symbols[0] ?? '-'));
        $this->line("  Timeframe: {$backtestRun->timeframe}");
        $this->line("  Status: {$backtestRun->status}");
        $this->newLine();

        $this->line('<fg=yellow>Parameters:</>');
        foreach ($backtestRun->strategy_inputs as $param => $value) {
            $this->line("  - $param: $value");
        }
        $this->newLine();

        if ($backtestRun->isCompleted()) {
            $stats = $backtestRun->statistics ?? [];

            $this->line('<fg=yellow>Statistics:</>');
            $this->line('  Initial Capital: '.number_format((float) $backtestRun->initial_capital, 2));
            $this->line('  Final Capital: '.number_format((float) $backtestRun->final_capital, 2));
            $this->line('  Net Profit: '.number_format((float) ($stats['total_return_percent'] ?? 0), 2).'%');

            $formattedStats = $formatter->formatStatistics($stats);
            foreach ($formattedStats as $label => $value) {
                $this->line("  {$label}: {$value}");
            }
            $this->newLine();

            if ($this->option('show-positions') && ! empty($stats['positions'])) {
                $this->line('<fg=yellow>Positions:</>');
                $positionData = $formatter->formatPositions($stats['positions']);

                $this->table(
                    ['Symbol', 'Direction', 'Entry', 'Exit', 'PnL'],
                    $positionData
                );
            }
        } elseif ($backtestRun->hasFailed()) {
            $this->line("<fg=red>Error:</> {$backtestRun->error_message}");
        }

        $this->debugMemory();

        return 0;
    }
}
