<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Model\BacktestRun;
use Illuminate\Console\Command;

use function Safe\file_put_contents;
use function Safe\json_encode;

class ExportTradesCommand extends Command
{
    protected $signature = 'alphaforge:backtest:trades
        {backtest_id : The backtest run ID}
        {--format=csv : Output format (csv, json)}
        {--output= : Output file path (stdout if omitted)}';

    protected $description = 'Export per-trade details from a completed backtest';

    public function handle(): int
    {
        $backtestId = $this->argument('backtest_id');
        $run = BacktestRun::find($backtestId);

        if (! $run) {
            $this->error("Backtest run not found: {$backtestId}");

            return 1;
        }

        if (! $run->isCompleted()) {
            $this->error("Backtest run is not completed. Status: {$run->status}");

            return 1;
        }

        $trades = $run->statistics['position_trades'] ?? [];

        if (empty($trades)) {
            $this->warn('No trade data found for this backtest run.');
            $this->line('  Trade data is captured for backtests run after the position_trades feature was added.');

            return 1;
        }

        $format = $this->option('format');
        $outputPath = $this->option('output');

        $output = $format === 'json'
            ? $this->renderJson($run, $trades)
            : $this->renderCsv($run, $trades);

        if ($outputPath) {
            file_put_contents($outputPath, $output);
            $this->line('<fg=green>Exported '.count($trades)." trades to {$outputPath}</>");
        } else {
            $this->line($output);
        }

        return 0;
    }

    private function renderCsv(BacktestRun $run, array $trades): string
    {
        $header = [
            'entry_time', 'exit_time', 'direction', 'entry_price', 'exit_price',
            'pnl', 'mae', 'mfe', 'bars_held', 'exit_reason', 'quantity',
        ];

        $lines = [implode(',', $header)];

        foreach ($trades as $t) {
            $row = [];
            foreach ($header as $col) {
                $val = $t[$col] ?? '';
                if (is_float($val)) {
                    $val = number_format($val, 6, '.', '');
                }
                if (is_string($val) && (str_contains($val, ',') || str_contains($val, '"'))) {
                    $val = '"'.str_replace('"', '""', $val).'"';
                }
                $row[] = $val;
            }
            $lines[] = implode(',', $row);
        }

        return implode("\n", $lines);
    }

    private function renderJson(BacktestRun $run, array $trades): string
    {
        return json_encode([
            'backtest_id' => $run->id,
            'strategy_alias' => $run->strategy_alias,
            'symbols' => $run->symbols,
            'total_trades' => count($trades),
            'trades' => $trades,
        ], JSON_PRETTY_PRINT);
    }
}
