<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Model\WalkForwardRun;
use Illuminate\Console\Command;

class ListWalkForwardRunsCommand extends Command
{
    protected $signature = 'alphaforge:walk-forward:list
        {--strategy= : Filter by strategy alias}
        {--status= : Filter by status (pending, optimizing, forward_testing, completed, failed)}
        {--limit=20 : Number of results to show}';

    protected $description = 'List past walk-forward runs';

    public function handle(): int
    {
        $query = WalkForwardRun::query()->orderBy('created_at', 'desc');

        if ($strategy = $this->option('strategy')) {
            $query->where('strategy_alias', $strategy);
        }

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        $runs = $query->limit((int) $this->option('limit'))->get();

        if ($runs->isEmpty()) {
            $this->info('No walk-forward runs found.');

            return 0;
        }

        $this->table(
            ['ID', 'Strategy', 'Symbol', 'Method', 'Split', 'Status', 'OOS/IS Ratio', 'Best OOS', 'Created'],
            $runs->map(fn (WalkForwardRun $run) => [
                substr($run->id, 0, 8),
                $run->strategy_alias,
                $run->symbols[0] ?? '-',
                $run->optimization_method,
                number_format($run->split_ratio * 100, 0).'%',
                $run->status,
                $this->formatWfe($run),
                $this->formatBestOos($run),
                $run->created_at->format('Y-m-d H:i'),
            ])
        );

        return 0;
    }

    private function formatWfe(WalkForwardRun $run): string
    {
        $isStats = $run->best_is_statistics;
        $oosStats = $run->best_oos_statistics;

        if (! $isStats || ! $oosStats) {
            return '-';
        }

        $metric = $run->optimization_objective ?? 'sharpe_ratio';
        $isVal = (float) ($isStats[$metric] ?? 0);
        $oosVal = (float) ($oosStats[$metric] ?? 0);

        if ($isVal == 0.0) {
            return '-';
        }

        return number_format(($oosVal / $isVal) * 100, 1).'%';
    }

    private function formatBestOos(WalkForwardRun $run): string
    {
        $oosStats = $run->best_oos_statistics;

        if (! $oosStats) {
            return '-';
        }

        $return = $oosStats['total_return_percent'] ?? null;
        if ($return !== null) {
            return number_format((float) $return, 2).'%';
        }

        $metric = $run->optimization_objective ?? 'sharpe_ratio';
        if (isset($oosStats[$metric])) {
            return number_format((float) $oosStats[$metric], 3);
        }

        return '-';
    }
}
