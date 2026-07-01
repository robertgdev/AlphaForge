<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Model\BacktestRun;
use App\AlphaForge\Backtesting\Model\OptimizationRun;
use App\AlphaForge\Console\Concerns\HasJsonOutput;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

use function Safe\file_put_contents;
use function Safe\json_encode;

class ExportOptimizeCommand extends Command
{
    use HasJsonOutput;

    protected $signature = 'alphaforge:export:optimize
        {optimization_id : The optimization run ID}
        {--format=csv : Output format (csv, json)}
        {--output= : Output file path (stdout if omitted)}
        {--top=10 : Number of top results to export}
        {--json : Output results as JSON}
        {--debug : Show peak memory usage on exit}';

    protected $description = 'Export optimization results (top-N parameters and statistics)';

    public function handle(): int
    {
        $optimizationId = $this->argument('optimization_id');

        if ($this->jsonEnabled() && $this->input->hasParameterOption('--format')) {
            return $this->outputJsonError('Cannot use --json and --format together. Use one or the other.');
        }

        $run = OptimizationRun::find($optimizationId);

        if (! $run) {
            return $this->outputJsonError("Optimization run not found: {$optimizationId}");
        }

        if (! $run->isCompleted()) {
            return $this->outputJsonError("Optimization run is not completed. Status: {$run->status}");
        }

        $topN = (int) $this->option('top');
        $outputPath = $this->option('output');

        $results = BacktestRun::where('optimization_id', $optimizationId)
            ->where('status', 'completed')
            ->orderBy('id', 'asc')
            ->limit($topN)
            ->get();

        if ($results->isEmpty()) {
            return $this->outputJsonError('No completed backtest results found for this optimization.');
        }

        if ($this->jsonEnabled()) {
            return $this->outputJson(true, $this->buildOptimizeJson($run, $results), outputPath: $outputPath);
        }

        $output = $format === 'json'
            ? $this->renderJson($run, $results)
            : $this->renderCsv($run, $results);

        if ($outputPath) {
            $dir = dirname($outputPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($outputPath, $output);
            $this->line('<fg=green>Exported '.$results->count()." results to {$outputPath}</>");
        } else {
            $this->line($output);
        }

        $this->debugMemory();

        return 0;
    }

    /**
     * @param  Collection<int, BacktestRun>  $results
     */
    private function buildOptimizeJson(OptimizationRun $run, $results): array
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

        return $data;
    }

    /**
     * @param  Collection<int, BacktestRun>  $results
     */
    private function renderCsv(OptimizationRun $run, $results): string
    {
        $header = ['rank', 'score', 'params', 'return_pct', 'sharpe', 'sortino', 'max_dd_pct', 'trades', 'win_rate', 'profit_factor'];
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
                $stats['sortino_ratio'] ?? '0',
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
     * @param  Collection<int, BacktestRun>  $results
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
