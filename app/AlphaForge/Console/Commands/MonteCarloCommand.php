<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\MonteCarlo\MonteCarloReport;
use App\AlphaForge\Backtesting\MonteCarlo\MonteCarloService;
use App\AlphaForge\Console\Concerns\HasJsonOutput;
use Illuminate\Console\Command;

class MonteCarloCommand extends Command
{
    use HasJsonOutput;

    protected $signature = 'alphaforge:monte-carlo
        {backtest_id : The backtest run ID to analyze}
        {--iterations=1000 : Number of bootstrap iterations}
        {--seed= : Random seed for reproducible results}
        {--json : Output results as JSON}
        {--debug : Show peak memory usage on exit}';

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
            return $this->outputJsonError($e->getMessage());
        }

        $report = $service->analyze($iterations, $seed !== null ? (int) $seed : null);

        if ($asJson) {
            $this->outputJson(true, $this->buildMonteCarloJson($report, $backtestId));
            $this->debugMemory();

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

            $this->debugMemory();

            return 0;
        }

        $this->renderMonteCarloTable($report);

        $this->renderRiskAssessment($report);

        $this->renderInterpretation($report);

        $this->debugMemory();

        return 0;
    }

    private function renderMonteCarloTable(MonteCarloReport $report): void
    {
        $this->line('<fg=yellow>Monte Carlo Summary ('.number_format($report->iterations).' bootstrap simulations)</>');
        $this->line(str_repeat('─', 60));
        $this->newLine();

        $this->line('  '.str_pad('Metric', 18).str_pad('5%', 12).str_pad('Median', 12).str_pad('95%', 12));
        $this->line('  '.str_repeat('─', 54));

        $desiredMetrics = ['total_return_pct', 'profit_factor', 'win_rate', 'avg_trade_pnl', 'max_drawdown_pct'];
        foreach ($desiredMetrics as $key) {
            $metric = $report->metrics[$key] ?? null;
            if ($metric === null) {
                continue;
            }

            $format = $this->formatFn($key);
            $label = match ($key) {
                'total_return_pct' => 'Total Return',
                'profit_factor' => 'Profit Factor',
                'win_rate' => 'Win Rate',
                'avg_trade_pnl' => 'Avg Trade PnL',
                'max_drawdown_pct' => 'Max Drawdown',
                default => $metric->label,
            };

            $this->line('  '.str_pad($label, 18).str_pad($format($metric->p5), 12).str_pad($format($metric->median), 12).str_pad($format($metric->p95), 12));
        }

        $this->newLine();
    }

    private function renderRiskAssessment(MonteCarloReport $report): void
    {
        $returnMetric = $report->metrics['total_return_pct'] ?? null;

        if ($returnMetric === null) {
            return;
        }

        $this->line('<fg=yellow>Bootstrap Loss Probability</>');
        $this->line(str_repeat('─', 60));
        $this->newLine();

        $probLoss = $returnMetric->probNegative;
        $probProfit = 100.0 - $probLoss;

        $this->line('  Historical trade distribution:');
        $this->line('    Losing simulations:      '.number_format($probLoss, 1).'%');
        $this->line('    Profitable simulations:  '.number_format($probProfit, 1).'%');

        $this->newLine();

        $this->line('  Interpretation:');
        $this->line('    The observed trade distribution appears internally consistent.');
        $this->line('    This is NOT a forecast of future profitability.');

        $this->newLine();
    }

    private function renderInterpretation(MonteCarloReport $report): void
    {
        $returnMetric = $report->metrics['total_return_pct'] ?? null;

        if ($returnMetric === null) {
            return;
        }

        $this->line('<fg=yellow>Interpretation</>');
        $this->line(str_repeat('─', 60));
        $this->newLine();

        $spread = $returnMetric->p95 - $returnMetric->p5;
        $probLoss = $returnMetric->probNegative;
        $median = $returnMetric->median;

        if ($probLoss === 0.0) {
            $this->line('  <fg=green>✓</> No simulations produced an overall loss.');
        }

        if ($probLoss === 0.0 && $spread < 2.0) {
            $this->line('  <fg=green>✓</> Returns appear statistically stable.');
            $this->line('  <fg=green>✓</> Bootstrap distribution is tightly clustered.');
        } elseif ($spread < 2.0) {
            $this->line('  <fg=green>✓</> Bootstrap distribution is tightly clustered.');
        }

        if ($probLoss > 20.0) {
            $this->line('  <fg=yellow>⚠</> Significant chance of negative outcomes — edge may not be stable.');
            $this->line('  Trade ordering matters significantly. The strategy\'s outcome is');
            $this->line('  sensitive to when specific trades occur.');
        }

        if ($spread > 5.0) {
            $this->line('  <fg=yellow>⚠</> Wide outcome range indicates trade outcome variance.');
            $this->line('  A single outlier trade can materially change the bottom line.');
        }

        if ($probLoss > 5.0 && $probLoss <= 20.0) {
            $this->line('  <fg=yellow>⚠</> Marginal confidence in profitability.');
            $this->line('  While the median outcome is positive, there is a meaningful');
            $this->line('  probability of negative returns depending on trade order.');
        }

        if ($median < 1.0) {
            $this->line('  <fg=yellow>⚠</> Absolute returns remain economically small.');
        }

        if ($median >= 5.0) {
            $this->line('  <fg=green>✓</> Returns are economically meaningful.');
        }

        $this->newLine();

        $this->line('  <fg=gray>⚠ Monte Carlo assumes future trades resemble historical trades.</>');
        $this->line('  <fg=gray>  It does not account for changing market dynamics or regime shifts.</>');
        $this->line('  <fg=gray>  Percentiles reflect reordering of observed trade outcomes, not unseen scenarios.</>');

        $this->newLine();
    }

    private function buildMonteCarloJson(MonteCarloReport $report, string $backtestId): array
    {
        $data = [
            'backtest_id' => $backtestId,
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

        return $data;
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
