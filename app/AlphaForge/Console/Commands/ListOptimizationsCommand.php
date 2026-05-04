<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Model\OptimizationRun;
use Illuminate\Console\Command;

class ListOptimizationsCommand extends Command
{
    protected $signature = 'alphaforge:optimizations:list
        {--strategy= : Filter by strategy alias}
        {--status= : Filter by status (pending, running, completed, failed)}
        {--limit=20 : Number of results to show}';

    protected $description = 'List past optimization runs';

    public function handle(): int
    {
        $query = OptimizationRun::query()->orderBy('created_at', 'desc');

        if ($strategy = $this->option('strategy')) {
            $query->where('strategy_alias', $strategy);
        }

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        $optimizations = $query->limit((int) $this->option('limit'))->get();

        if ($optimizations->isEmpty()) {
            $this->info("No optimization runs found.");
            return 0;
        }

        $this->table(
            ['ID', 'Strategy', 'Symbol', 'Status', 'Combinations', 'Best Metric', 'Created'],
            $optimizations->map(fn ($opt) => [
                substr($opt->id, 0, 8),
                $opt->strategy_alias,
                $opt->symbols[0] ?? '-',
                $opt->status,
                "{$opt->completed_combinations}/{$opt->total_combinations}",
                $this->formatMetric($opt),
                $opt->created_at->format('Y-m-d H:i'),
            ])
        );

        return 0;
    }

    private function formatMetric(OptimizationRun $optimizationRun): string
    {
        $metric = $optimizationRun->optimization_metric;
        $stats = $optimizationRun->best_statistics;

        if (! $stats || ! isset($stats[$metric])) {
            return '-';
        }

        $value = $stats[$metric];

        // Handle percentage metrics
        if (str_contains($metric, 'percent') || str_contains($metric, 'drawdown')) {
            return number_format((float) $value * 100, 2) . '%';
        }

        return number_format((float) $value, 4);
    }
}
