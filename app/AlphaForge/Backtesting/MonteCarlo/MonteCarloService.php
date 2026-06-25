<?php

namespace App\AlphaForge\Backtesting\MonteCarlo;

use RobertGDev\AlphaforgeStatistics\MonteCarlo\MonteCarloService as PackageMonteCarloService;
use App\AlphaForge\Backtesting\Model\BacktestRun;

class MonteCarloService
{
    /**
     * @param  array<int, string>  $tradePnlValues  Per-trade realized P&L strings
     * @param  string  $initialCapital  Initial capital for return calculations
     */
    public function __construct(
        private readonly array $tradePnlValues,
        private readonly string $initialCapital,
    ) {}

    public static function fromBacktestRunId(string $backtestId): self
    {
        $run = BacktestRun::find($backtestId);

        if (! $run) {
            throw new \InvalidArgumentException("Backtest run not found: {$backtestId}");
        }

        if (! $run->isCompleted()) {
            throw new \RuntimeException("Backtest run '{$backtestId}' is not completed.");
        }

        $stats = $run->statistics ?? [];
        $pnlValues = $stats['position_pnl_values'] ?? [];

        return new self($pnlValues, (string) $run->initial_capital);
    }

    public static function fromOptimizationTopN(string $optimizationId, int $rank = 1): self
    {
        $run = BacktestRun::where('optimization_id', $optimizationId)
            ->where('status', 'completed')
            ->orderBy('id', 'asc')
            ->skip($rank - 1)
            ->first();

        if (! $run) {
            throw new \InvalidArgumentException(
                "No backtest run found for optimization '{$optimizationId}' at rank {$rank}."
            );
        }

        return self::fromBacktestRunId($run->id);
    }

    public function analyze(int $iterations = 1000, ?int $seed = null): MonteCarloReport
    {
        $packageService = new PackageMonteCarloService($this->tradePnlValues, $this->initialCapital);
        $packageReport = $packageService->analyze($iterations, $seed);

        $metrics = [];
        foreach ($packageReport->metrics as $key => $metric) {
            $metrics[$key] = new MonteCarloMetric(
                label: $metric->label,
                p5: $metric->p5,
                p25: $metric->p25,
                median: $metric->median,
                p75: $metric->p75,
                p95: $metric->p95,
                probNegative: $metric->probNegative,
            );
        }

        return new MonteCarloReport(
            totalTrades: $packageReport->totalTrades,
            iterations: $packageReport->iterations,
            metrics: $metrics,
        );
    }
}