<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Model\BacktestRun;
use App\AlphaForge\Backtesting\Model\OptimizationRun;
use Illuminate\Console\Command;

use function Safe\file_put_contents;
use function Safe\json_encode;

class ExportOptimizeCommand extends Command
{
    protected $signature = 'alphaforge:export:optimize
        {optimization_id : The optimization run ID}
        {--format=csv : Output format (csv, json)}
        {--output= : Output file path (stdout if omitted)}
        {--top=10 : Number of top results to export}';

    protected $description = 'Export optimization results (top-N parameters and statistics)';

    public function handle(): int
    {
        $optimizationId = $this->argument('optimization_id');
        $run = OptimizationRun::find($optimizationId);

        if (! $run) {
            $this->error("Optimization run not found: {$optimizationId}");

            return 1;
        }

        if (! $run->isCompleted()) {
            $this->error("Optimization run is not completed. Status: {$run->status}");

            return 1;
        }

        $topN = (int) $this->option('top');
        $format = $this->option('format');
        $outputPath = $this->option('output');

        $results = BacktestRun::where('optimization_id', $optimizationId)
            ->where('status', 'completed')
            ->orderBy('id', 'asc')
            ->limit($topN)
            ->get();

        if ($results->isEmpty()) {
            $this->warn('No completed backtest results found for this optimization.');
            $this->line('  The optimization may still be running, or results were not persisted.');

            return 1;
        }

        $output = $format === 'json'
            ? $this->renderJson($run, $results)
            : $this->renderCsv($run, $results);

        if ($outputPath) {
            file_put_contents($outputPath, $output);
            $this->line('<fg=green>Exported '.$results->count()." results to {$outputPath}</>");
        } else {
            $this->line($output);
        }

        return 0;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BacktestRun>  $results
     */
    private function renderCsv(OptimizationRun $run, $results): string
    {
        $header = ['rank', 'score', 'params', 'return_pct', 'sharpe', 'max_dd_pct', 'trades', 'win_rate', 'profit_factor'];
        $lines = [implode(',', $header)];

        foreach ($results as $index => $r) {
            $stats = $r->statistics ?? [];
            $paramsStr = json_encode($r->strategy_inputs ?? []);
            if (str_contains($paramsStr, ',')) {
                $paramsStr = '"'.str_replace('"', '""', $paramsStr).'"';
            }

            $row = [
                $index + 1,
                $stats['optimization_score'] ?? '0',
                $paramsStr,
                $stats['total_return_percent'] ?? '0',
                $stats['sharpe_ratio'] ?? '0',
                $stats['max_drawdown_percent'] ?? '0',
                $stats['total_trades'] ?? '0',
                $stats['win_rate'] ?? '0',
                $stats['profit_factor'] ?? '0',
            ];

            $lines[] = implode(',', $row);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BacktestRun>  $results
     */
    private function renderJson(OptimizationRun $run, $results): string
    {
        $data = [
            'optimization_id' => $run->id,
            'strategy_alias' => $run->strategy_alias,
            'symbols' => $run->symbols,
            'method' => $run->optimization_method,
            'objective' => $run->optimization_objective,
            'total_combinations' => $run->total_combinations,
            'completed_combinations' => $run->completed_combinations,
            'best_parameters' => $run->best_parameters,
            'best_statistics' => $run->best_statistics,
            'results' => [],
        ];

        foreach ($results as $index => $r) {
            $stats = $r->statistics ?? [];
            $result = [
                'rank' => $index + 1,
                'score' => (float) ($stats['optimization_score'] ?? 0),
                'parameters' => $r->strategy_inputs,
                'statistics' => [
                    'total_return_percent' => (float) ($stats['total_return_percent'] ?? 0),
                    'sharpe_ratio' => (float) ($stats['sharpe_ratio'] ?? 0),
                    'max_drawdown_percent' => (float) ($stats['max_drawdown_percent'] ?? 0),
                    'total_trades' => (int) ($stats['total_trades'] ?? 0),
                    'win_rate' => (float) ($stats['win_rate'] ?? 0),
                    'profit_factor' => (float) ($stats['profit_factor'] ?? 0),
                    'sortino_ratio' => (float) ($stats['sortino_ratio'] ?? 0),
                    'calmar_ratio' => (float) ($stats['calmar_ratio'] ?? 0),
                ],
            ];

            if (! empty($stats['per_symbol'])) {
                $result['per_symbol'] = $stats['per_symbol'];
            }

            $data['results'][] = $result;
        }

        return json_encode($data, JSON_PRETTY_PRINT);
    }
}
