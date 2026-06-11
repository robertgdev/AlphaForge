<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\MonteCarlo\MonteCarloReport;
use App\AlphaForge\Backtesting\MonteCarlo\MonteCarloService;
use Illuminate\Console\Command;

use function Safe\json_encode;

class MonteCarloCommand extends Command
{
    protected $signature = 'alphaforge:monte-carlo
        {backtest_id : The backtest run ID to analyze}
        {--iterations=1000 : Number of bootstrap iterations}
        {--seed= : Random seed for reproducible results}
        {--json : Output results as JSON}';

    protected $description = 'Run Monte Carlo bootstrap analysis on backtest trade outcomes';

    public function handle(): int
    {
        $backtestId = $this->argument('backtest_id');
        $iterations = (int) $this->option('iterations');
        $seed = $this->option('seed');
        $asJson = $this->option('json');

        try {
            $service = MonteCarloService::fromBacktestRunId($backtestId);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $report = $service->analyze($iterations, $seed !== null ? (int) $seed : null);

        if ($asJson) {
            $this->renderJson($report);

            return 0;
        }

        $this->line('<fg=yellow>=== Monte Carlo Bootstrap Analysis ===</>');
        $this->line("  Backtest ID: {$backtestId}");
        $this->line("  Trade count: {$report->totalTrades}");
        $this->line("  Bootstrap iterations: {$report->iterations}");
        if ($seed !== null) {
            $this->line("  Seed: {$seed}");
        }
        $this->newLine();

        if ($report->totalTrades > 0 && $report->totalTrades < 30) {
            $this->line("  <fg=yellow>⚠ Only {$report->totalTrades} trades observed — Monte Carlo confidence is limited.</>");
            $this->line('  <fg=gray>  Resampling few observations cannot manufacture statistical certainty.</>');
            $this->newLine();
        }

        if (! $report->hasTrades()) {
            $this->line('  <fg=yellow>No trades available for analysis.</>');
            $this->line('  Monte Carlo requires at least one closed trade. Run a backtest with trades first.');
            $this->newLine();

            return 0;
        }

        $this->line('<fg=yellow>Metric Distribution (5% / 25% / Median / 75% / 95% percentiles)</>');
        $this->newLine();

        $tableData = [];
        foreach ($report->metrics as $key => $metric) {
            $sig = $metric->isSignificant() ? '<fg=green>✓</>' : ($metric->probNegative > 20 ? '<fg=red>✗</>' : '<fg=yellow>~</>');

            $format = $this->formatFn($key);
            $tableData[] = [
                $metric->label,
                $format($metric->p5),
                $format($metric->p25),
                $format($metric->median),
                $format($metric->p75),
                $format($metric->p95),
                number_format($metric->probNegative, 1).'%',
                $sig,
            ];
        }

        $this->table(
            ['Metric', 'P5 (worst)', 'P25', 'Median', 'P75', 'P95 (best)', 'P(< 0)', 'Sig.'],
            $tableData
        );

        $this->newLine();
        $this->line('  <fg=green>✓</> Significant (P(negative) < 5% AND P5 > 0 — the metric is reliably positive)');
        $this->line('  <fg=yellow>~</> Marginal (P(negative) 5–20%)');
        $this->line('  <fg=red>✗</> Unreliable (P(negative) > 20% — high probability of negative outcomes)');
        $this->newLine();
        $this->line('  <fg=gray>P5 through P95 are percentile confidence bands. The median is the most likely outcome.</>');
        $this->line('  <fg=gray>P(< 0) = probability that the resampled metric is below zero (loss/harm).</>');
        $this->newLine();
        $this->line('  <fg=gray>⚠ Monte Carlo assumes future trades resemble historical trades.</>');
        $this->line('  <fg=gray>  It does not account for changing market dynamics or regime shifts.</>');
        $this->line('  <fg=gray>  Percentiles reflect reordering of observed trade outcomes, not unseen scenarios.</>');

        $this->renderInterpretation($report);

        return 0;
    }

    private function renderInterpretation(MonteCarloReport $report): void
    {
        $returnMetric = $report->metrics['total_return_pct'] ?? null;

        if ($returnMetric === null) {
            return;
        }

        $this->newLine();
        $this->line('<fg=yellow>Monte Carlo Interpretation</>');
        $this->line(str_repeat('─', 40));

        $spread = $returnMetric->p95 - $returnMetric->p5;
        $probLoss = $returnMetric->probNegative;
        $median = $returnMetric->median;

        $this->line('  '.number_format($returnMetric->p95 - $returnMetric->p5, 2).'% spread between worst 5% and best 5% of outcomes.');
        $this->line('  Probability of losing money: '.number_format($probLoss, 1).'%.');
        $this->line('  Expected median return: '.($median >= 0 ? '+' : '').number_format($median, 2).'%.');

        $this->newLine();

        if ($probLoss === 0.0 && $spread < 2.0) {
            $this->line('  <fg=green>Conclusion:</> Trade ordering has little effect on outcomes.');
            if ($median < 1.0) {
                $this->line('  The strategy is consistently low-return rather than high-risk.');
            } else {
                $this->line('  The strategy produces consistent positive returns regardless of');
                $this->line('  trade sequence, suggesting robust edge rather than path dependency.');
            }
        } elseif ($probLoss > 20.0) {
            $this->line('  <fg=yellow>Conclusion:</> Trade ordering matters significantly.');
            $this->line('  The strategy\'s outcome is sensitive to when specific trades occur,');
            $this->line('  suggesting fragile edge or unfavorable win/loss distribution.');
        } elseif ($spread > 5.0) {
            $this->line('  <fg=yellow>Conclusion:</> Wide outcome range indicates trade outcome variance.');
            $this->line('  Results are sensitive to trade sequence. A single outlier trade');
            $this->line('  can materially change the bottom line.');
        } elseif ($probLoss > 5.0) {
            $this->line('  <fg=yellow>Conclusion:</> Marginal confidence in profitability.');
            $this->line('  While the median outcome is positive, there is a meaningful');
            $this->line('  probability of negative returns depending on trade order.');
        } else {
            $this->line('  <fg=green>Conclusion:</> The strategy shows reliable positive returns');
            $this->line('  across most trade sequences with moderate outcome variance.');
        }

        $this->newLine();
    }

    private function renderJson(MonteCarloReport $report): void
    {
        $data = [
            'total_trades' => $report->totalTrades,
            'iterations' => $report->iterations,
            'metrics' => [],
        ];

        foreach ($report->metrics as $key => $metric) {
            $data['metrics'][$key] = [
                'label' => $metric->label,
                'p5' => $metric->p5,
                'p25' => $metric->p25,
                'median' => $metric->median,
                'p75' => $metric->p75,
                'p95' => $metric->p95,
                'prob_negative_pct' => $metric->probNegative,
                'significant' => $metric->isSignificant(),
            ];
        }

        $this->line(json_encode($data, JSON_PRETTY_PRINT));
    }

    private function formatFn(string $metric): \Closure
    {
        return match ($metric) {
            'total_return_pct', 'max_drawdown_pct', 'win_rate', 'positive_trades' => fn ($v) => number_format($v, 2).'%',
            'profit_factor' => fn ($v) => is_infinite((float) $v) ? '∞' : number_format((float) $v, 4),
            'avg_trade_pnl' => fn ($v) => number_format($v, 2),
            default => fn ($v) => (string) $v,
        };
    }
}
