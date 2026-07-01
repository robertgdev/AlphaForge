<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Model\WalkForwardRun;
use App\AlphaForge\Console\Concerns\HasJsonOutput;
use Illuminate\Console\Command;

class ListWalkForwardRunsCommand extends Command
{
    use HasJsonOutput;

    protected $signature = 'alphaforge:walk-forward:list
        {--strategy= : Filter by strategy alias}
        {--status= : Filter by status (pending, optimizing, forward_testing, completed, failed)}
        {--limit=20 : Number of results to show}
        {--json : Output results as JSON}
        {--schema : Display command parameter schema as JSON}
        {--debug : Show peak memory usage on exit}';

    protected $description = 'List past walk-forward runs';

    public function handle(): int
    {
        if (($code = $this->handleSchemaFlag()) !== null) {
            return $code;
        }

        $query = WalkForwardRun::query()->orderBy('created_at', 'desc');

        if ($strategy = $this->option('strategy')) {
            $query->where('strategy_alias', $strategy);
        }

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        $runs = $query->limit((int) $this->option('limit'))->get();

        if ($runs->isEmpty()) {
            if ($this->jsonEnabled()) {
                return $this->outputJson(true, ['runs' => []]);
            }

            $this->info('No walk-forward runs found.');

            $this->debugMemory();

            return 0;
        }

        if ($this->jsonEnabled()) {
            return $this->outputJson(true, [
                'runs' => $runs->map(fn (WalkForwardRun $run) => [
                    'id' => $run->id,
                    'strategy' => $run->strategy_alias,
                    'symbol' => $run->symbols[0] ?? null,
                    'method' => $run->optimization_method,
                    'split' => $run->split_ratio,
                    'status' => $run->status,
                    'oosScore' => $this->extractOosScore($run),
                    'bestOos' => $this->extractBestOos($run),
                    'created' => $run->created_at->toIso8601String(),
                ])->values()->toArray(),
            ]);
        }

        $this->table(
            ['ID', 'Strategy', 'Symbol', 'Method', 'Split', 'Status', 'OOS Score', 'Best OOS', 'Created'],
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

        $this->debugMemory();

        return 0;
    }

    private function extractOosScore(WalkForwardRun $run): ?float
    {
        $oosStats = $run->best_oos_statistics;
        if (! $oosStats) {
            return null;
        }
        $metric = $run->optimization_objective ?? 'sharpe_ratio';

        return (float) ($oosStats[$metric] ?? 0);
    }

    private function extractBestOos(WalkForwardRun $run): ?float
    {
        $oosStats = $run->best_oos_statistics;
        if (! $oosStats) {
            return null;
        }
        $return = $oosStats['total_return_percent'] ?? null;
        if ($return !== null) {
            return (float) $return;
        }
        $metric = $run->optimization_objective ?? 'sharpe_ratio';
        if (isset($oosStats[$metric])) {
            return (float) $oosStats[$metric];
        }

        return null;
    }

    private function formatWfe(WalkForwardRun $run): string
    {
        $oosStats = $run->best_oos_statistics;

        if (! $oosStats) {
            return '-';
        }

        $metric = $run->optimization_objective ?? 'sharpe_ratio';
        $oosVal = (float) ($oosStats[$metric] ?? 0);

        return number_format($oosVal, 2);
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
