<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Model\OptimizationRun;
use App\AlphaForge\Backtesting\Service\BacktestResultFormatter;
use App\AlphaForge\Backtesting\Service\ParameterOptimizerService;
use App\AlphaForge\Console\Concerns\HasJsonOutput;
use Illuminate\Console\Command;

class ShowOptimizationCommand extends Command
{
    use HasJsonOutput;

    protected $signature = 'alphaforge:optimizations:show
        {optimization_id : The optimization run ID}
        {--top=10 : Number of top results to display}
        {--json : Output results as JSON}
        {--schema : Display command parameter schema as JSON}
        {--debug : Show peak memory usage on exit}';

    protected $description = 'Show detailed optimization results';

    public function handle(ParameterOptimizerService $optimizer, BacktestResultFormatter $formatter): int
    {
        if (($code = $this->handleSchemaFlag()) !== null) {
            return $code;
        }

        $optimizationId = $this->argument('optimization_id');
        $topCount = (int) $this->option('top');

        $optimizationRun = OptimizationRun::find($optimizationId);

        if (! $optimizationRun) {
            return $this->outputJsonError("Optimization run not found: $optimizationId");
        }

        $results = $optimizer->getRankedResults($optimizationRun)->take($topCount);

        if ($this->jsonEnabled()) {
            return $this->outputJson(true, [
                'id' => $optimizationRun->id,
                'strategy' => $optimizationRun->strategy_alias,
                'symbol' => $optimizationRun->symbols[0] ?? null,
                'timeframe' => $optimizationRun->timeframe,
                'status' => $optimizationRun->status,
                'metric' => $optimizationRun->optimization_metric,
                'progress' => "{$optimizationRun->completed_combinations}/{$optimizationRun->total_combinations}",
                'parameterRanges' => $optimizationRun->parameter_ranges,
                'bestParameters' => $optimizationRun->best_parameters,
                'bestStatistics' => $optimizationRun->best_statistics,
                'topResults' => $results->map(function ($result) {
                    return [
                        'rank' => $result->rank,
                        'parameters' => $result->parameters,
                        'statistics' => $result->statistics,
                    ];
                })->values()->toArray(),
            ]);
        }

        $this->line('<fg=yellow>Optimization Details</>');
        $this->line("  ID: {$optimizationRun->id}");
        $this->line("  Strategy: {$optimizationRun->strategy_alias}");
        $this->line('  Symbol: '.($optimizationRun->symbols[0] ?? '-'));
        $this->line("  Timeframe: {$optimizationRun->timeframe}");
        $this->line("  Status: {$optimizationRun->status}");
        $this->line("  Optimization Metric: {$optimizationRun->optimization_metric}");
        $this->line("  Progress: {$optimizationRun->completed_combinations}/{$optimizationRun->total_combinations}");
        $this->newLine();

        $this->line('<fg=yellow>Parameter Ranges:</>');
        foreach ($optimizationRun->parameter_ranges as $param => $range) {
            $this->line("  - $param: {$range['min']} to {$range['max']} (step: {$range['step']})");
        }
        $this->newLine();

        if ($optimizationRun->isCompleted()) {
            $this->line('<fg=yellow>Best Parameters:</>');
            foreach ($optimizationRun->best_parameters as $param => $value) {
                $this->line("  - $param: $value");
            }
            $this->newLine();

            $this->line('<fg=yellow>Best Statistics:</>');
            $formattedStats = $formatter->formatStatistics($optimizationRun->best_statistics);
            foreach ($formattedStats as $label => $value) {
                $this->line("  - {$label}: {$value}");
            }
            $this->newLine();
        }

        // Display top results table
        $this->line("<fg=yellow>Top {$topCount} Results:</>");

        if ($results->isEmpty()) {
            $this->line('  No completed results yet.');

            $this->debugMemory();

            return 0;
        }

        $metric = $optimizationRun->optimization_metric;
        $tableData = $results->map(function ($result) use ($metric) {
            $params = $result->parameters;
            $stats = $result->statistics;

            return [
                '#'.$result->rank,
                implode(', ', array_map(fn ($k, $v) => "$k=$v", array_keys($params), $params)),
                number_format((float) ($stats['total_return_percent'] ?? 0), 2).'%',
                number_format((float) ($stats['win_rate'] ?? 0) * 100, 1).'%',
                number_format((float) ($stats[$metric] ?? 0), 4),
                number_format((float) ($stats['max_drawdown_percent'] ?? 0) * 100, 2).'%',
            ];
        })->toArray();

        $this->table(
            ['Rank', 'Parameters', 'Return', 'Win Rate', ucfirst(str_replace('_', ' ', $metric)), 'Max DD'],
            $tableData
        );

        $this->debugMemory();

        return 0;
    }
}
