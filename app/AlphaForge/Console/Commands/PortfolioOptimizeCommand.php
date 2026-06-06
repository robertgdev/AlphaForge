<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Model\OptimizationRun;
use App\AlphaForge\Backtesting\Optimization\MultiSymbolOptimizer;
use App\AlphaForge\Backtesting\Optimization\OptimizationConfig;
use Illuminate\Console\Command;

class PortfolioOptimizeCommand extends Command
{
    protected $signature = 'alphaforge:optimize:portfolio
        {strategy : Strategy alias (e.g. sma_crossover)}
        {symbols* : One or more symbols (e.g. BTCUSDT ETHUSDT SOLUSDT)}
        {--method=random : Optimization method (grid, random, genetic)}
        {--iterations=500 : Total iterations (for random method)}
        {--population=50 : Population size (for genetic method)}
        {--generations=20 : Generations (for genetic method)}
        {--timeframe=1h : Bar timeframe}
        {--exchange=binance : Exchange for market data}
        {--initial-capital=10000 : Initial capital}
        {--start-date= : Start date (YYYY-MM-DD)}
        {--end-date= : End date (YYYY-MM-DD)}
        {--top-n=10 : Number of top results to keep}
        {--use-strategy-ranges : Use strategy-defined parameter ranges}
        {--min-trades=1 : Minimum trades per symbol required}';

    protected $description = 'Run portfolio-level optimization across multiple symbols';

    public function handle(MultiSymbolOptimizer $optimizer): int
    {
        $strategyAlias = $this->argument('strategy');
        $symbols = $this->argument('symbols');

        if (count($symbols) < 2) {
            $this->error('Portfolio optimization requires at least 2 symbols. For single-symbol use: alphaforge:optimize');

            return 1;
        }

        $this->line('<fg=yellow>=== Portfolio Optimization ===</>');
        $this->line("  Strategy: {$strategyAlias}");
        $this->line('  Symbols: '.implode(', ', $symbols));
        $this->line('  Method: '.$this->option('method'));
        $this->line("  Iterations: {$this->option('iterations')}");
        $this->newLine();

        $config = $this->buildConfig($strategyAlias, $symbols);

        $this->line('<fg=green>Running optimization...</>');
        $startTime = microtime(true);

        $run = $optimizer->optimize($config, function ($progress) {
            if ($progress->completed % 10 === 0) {
                $this->output->write("\r  <fg=gray>Progress: {$progress->completed}/{$progress->total} — Score: ".number_format($progress->score, 4).'</>');
            }
        });

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->line("\n  <fg=green>Completed in {$elapsed}s</>");

        if ($run->isCompleted()) {
            $this->displayResults($run);
        } else {
            $this->error('Optimization failed: '.($run->error_message ?? 'unknown error'));
        }

        return 0;
    }

    /**
     * @param  array<string>  $symbols
     */
    private function buildConfig(string $strategyAlias, array $symbols): OptimizationConfig
    {
        $data = [
            'strategy_alias' => $strategyAlias,
            'symbols' => $symbols,
            'timeframe' => $this->option('timeframe'),
            'exchange' => $this->option('exchange'),
            'initial_capital' => $this->option('initial-capital'),
            'method' => $this->option('method'),
            'iterations' => (int) $this->option('iterations'),
            'population_size' => (int) $this->option('population'),
            'generations' => (int) $this->option('generations'),
            'top_n' => (int) $this->option('top-n'),
            'objective' => 'portfolio_score',
        ];

        if ($this->option('start-date')) {
            $data['start_date'] = $this->option('start-date');
        }
        if ($this->option('end-date')) {
            $data['end_date'] = $this->option('end-date');
        }

        return OptimizationConfig::fromArray($data);
    }

    private function displayResults(OptimizationRun $run): void
    {
        $this->newLine();
        $this->line('<fg=yellow>Best Portfolio Parameters:</>');

        foreach ($run->best_parameters as $param => $value) {
            $this->line("  {$param} = {$value}");
        }

        $this->newLine();

        if (! empty($run->best_statistics)) {
            $this->line('<fg=yellow>Combined Portfolio Metrics:</>');
            $stats = $run->best_statistics;

            $tableData = [];
            foreach ([
                'total_return_percent' => 'Avg Return %',
                'sharpe_ratio' => 'Avg Sharpe',
                'max_drawdown_percent' => 'Avg Max DD %',
                'total_trades' => 'Total Trades',
                'symbols_count' => 'Symbols Scored',
            ] as $key => $label) {
                if (isset($stats[$key])) {
                    $val = $stats[$key];
                    $tableData[] = [$label, is_float($val) ? number_format($val, 4) : (string) $val];
                }
            }

            if (! empty($tableData)) {
                $this->table(['Metric', 'Value'], $tableData);
            }

            // Show per-symbol breakdown
            if (! empty($stats['per_symbol'])) {
                $this->newLine();
                $this->line('<fg=yellow>Per-Symbol Breakdown:</>');

                $perSymbolData = [];
                foreach ($stats['per_symbol'] as $symbol => $s) {
                    $perSymbolData[] = [
                        $symbol,
                        number_format((float) ($s['total_return_percent'] ?? 0), 2).'%',
                        number_format((float) ($s['sharpe_ratio'] ?? 0), 3),
                        (int) ($s['total_trades'] ?? 0),
                        number_format((float) ($s['win_rate'] ?? 0) * 100, 1).'%',
                    ];
                }

                $this->table(
                    ['Symbol', 'Return', 'Sharpe', 'Trades', 'Win Rate'],
                    $perSymbolData
                );
            }
        }

        $this->newLine();
        $this->line("<fg=gray>Optimization ID: {$run->id}</>");
        $this->line('<fg=gray>View details: php artisan alphaforge:optimizations:show '.substr($run->id, 0, 8).'</>');
    }
}
