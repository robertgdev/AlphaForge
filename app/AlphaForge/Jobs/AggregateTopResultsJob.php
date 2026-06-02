<?php

namespace App\AlphaForge\Jobs;

use App\AlphaForge\Backtesting\Model\BacktestRun;
use App\AlphaForge\Backtesting\Model\OptimizationRun;
use App\AlphaForge\Backtesting\Optimization\Objective\ObjectiveFactory;
use App\AlphaForge\Backtesting\Optimization\Objective\ObjectiveFunctionInterface;
use App\AlphaForge\Backtesting\Optimization\ScoredResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

use function Safe\json_encode;

class AggregateTopResultsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public string $optimizationId,
        public string $objective,
        public int $topN,
        public string $snapshotPath,
    ) {
        $this->onQueue(config('alphaforge.queues.backtests', 'backtests'));
    }

    public function handle(): void
    {
        $optimizationRun = OptimizationRun::findOrFail($this->optimizationId);

        $objective = $this->resolveObjective();

        $backtestRuns = BacktestRun::where('optimization_id', $this->optimizationId)
            ->where('status', 'completed')
            ->get();

        if ($backtestRuns->isEmpty()) {
            $optimizationRun->markAsFailed('No backtests completed successfully');

            $this->cleanup();

            return;
        }

        $scored = [];

        foreach ($backtestRuns as $run) {
            $statistics = $run->statistics ?? [];
            if (empty($statistics)) {
                continue;
            }

            $score = $objective->score($statistics);
            $scored[] = new ScoredResult($run->strategy_inputs, $statistics, $score);
        }

        if (empty($scored)) {
            $optimizationRun->markAsFailed('No valid statistics found in completed backtests');

            $this->cleanup();

            return;
        }

        usort($scored, fn (ScoredResult $a, ScoredResult $b) => $b->score <=> $a->score);

        $ranked = array_slice($scored, 0, $this->topN);

        foreach ($ranked as $rank => $r) {
            $existing = BacktestRun::where('optimization_id', $this->optimizationId)
                ->where('strategy_inputs', json_encode($r->parameters))
                ->first();

            if ($existing) {
                $existing->update([
                    'statistics' => array_merge($r->statistics, ['optimization_score' => (string) $r->score]),
                ]);
            }
        }

        $best = $ranked[0];
        $optimizationRun->markAsCompleted($best->parameters, $best->statistics);

        Log::info('Queue optimization completed', [
            'optimization_id' => $this->optimizationId,
            'best_score' => $best->score,
            'total_backtests' => $backtestRuns->count(),
            'top_n' => count($ranked),
        ]);

        $this->cleanup();
    }

    private function resolveObjective(): ObjectiveFunctionInterface
    {
        return ObjectiveFactory::create($this->objective);
    }

    private function cleanup(): void
    {
        if (file_exists($this->snapshotPath)) {
            @unlink($this->snapshotPath);
        }
    }
}
