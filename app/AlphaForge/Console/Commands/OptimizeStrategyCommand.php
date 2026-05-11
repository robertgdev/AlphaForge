<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Optimization\OptimizationConfig;
use App\AlphaForge\Backtesting\Optimization\OptimizationMethod;
use App\AlphaForge\Backtesting\Optimization\Optimizer;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Strategy\Service\StrategyInputParser;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Safe\DateTimeImmutable;

class OptimizeStrategyCommand extends Command
{
    protected $signature = 'alphaforge:optimize
        {strategy : The strategy alias}
        {symbol : Trading symbol}
        {--exchange=binance : Exchange identifier}
        {--timeframe=1h : Timeframe}
        {--capital=10000 : Initial capital}
        {--stake-currency=USDT : Stake currency}
        {--start-date= : Start date (Y-m-d)}
        {--end-date= : End date (Y-m-d)}
        {--params= : Parameter ranges as JSON}
        {--use-strategy-ranges : Use strategy\'s defined min/max ranges}
        {--method=random : Optimization method (grid, random, genetic)}
        {--iterations=500 : Number of iterations for random search}
        {--population=50 : Population size for genetic algorithm}
        {--generations=20 : Number of generations for genetic algorithm}
        {--objective=sharpe_ratio : Objective (sharpe_ratio, balanced, conservative, sharpe_focused, aggressive, or any metric name)}
        {--top-n=50 : Number of top results to persist}';

    protected $description = 'Run strategy parameter optimization';

    public function handle(Optimizer $optimizer, StrategyInputParser $inputParser): int
    {
        $strategyAlias = $this->argument('strategy');
        $symbol = $this->argument('symbol');
        $exchange = $this->option('exchange');
        $timeframeValue = $this->option('timeframe');
        $capital = $this->option('capital');
        $stakeCurrency = $this->option('stake-currency');
        $startDateOption = $this->option('start-date');
        $endDateOption = $this->option('end-date');
        $paramsJson = $this->option('params');
        $useStrategyRanges = $this->option('use-strategy-ranges');
        $methodValue = $this->option('method');
        $iterations = (int) $this->option('iterations');
        $population = (int) $this->option('population');
        $generations = (int) $this->option('generations');
        $objective = $this->option('objective');
        $topN = (int) $this->option('top-n');

        $timeframe = TimeframeEnum::tryFrom($timeframeValue);
        if (! $timeframe) {
            $this->error("Invalid timeframe: $timeframeValue");

            return 1;
        }

        $method = OptimizationMethod::tryFrom($methodValue);
        if (! $method) {
            $this->error("Invalid method: $methodValue. Use: grid, random, genetic");

            return 1;
        }

        $startDate = $startDateOption ? Carbon::parse($startDateOption) : null;
        $endDate = $endDateOption ? Carbon::parse($endDateOption) : null;

        $parameterOverrides = null;

        if ($useStrategyRanges) {
            $parameterRanges = $optimizer->getParameterRangesFromStrategy($strategyAlias);
            if (empty($parameterRanges)) {
                $this->error("No parameter ranges found for strategy: $strategyAlias");

                return 1;
            }
            $this->info('Using strategy-defined parameter ranges:');
            foreach ($parameterRanges as $param => $range) {
                $this->line("  - $param: {$range['min']} to {$range['max']} (step: {$range['step']})");
            }
        } elseif ($paramsJson) {
            $parsed = $inputParser->parseInputs($paramsJson);
            if ($parsed === false) {
                $this->error('Invalid JSON for --params: '.json_last_error_msg());

                return 1;
            }
            $parameterOverrides = $parsed;
        } else {
            $this->error('Either --params or --use-strategy-ranges must be specified');
            $this->line("  --params='{\"fastPeriod\":{\"min\":5,\"max\":20,\"step\":5}}'");
            $this->line('  --use-strategy-ranges');

            return 1;
        }

        $this->info('Starting optimization...');
        $this->line("  Strategy: $strategyAlias");
        $this->line("  Symbol: $symbol");
        $this->line("  Timeframe: {$timeframe->value}");
        $this->line("  Method: {$method->value}");
        $this->line("  Objective: $objective");

        if ($method === OptimizationMethod::RANDOM) {
            $this->line("  Iterations: $iterations");
        } elseif ($method === OptimizationMethod::GENETIC) {
            $this->line("  Population: $population");
            $this->line("  Generations: $generations");
        }

        $this->newLine();

        $config = new OptimizationConfig;
        $config->strategyAlias = $strategyAlias;
        $config->symbols = [$symbol];
        $config->timeframe = $timeframe;
        $config->exchange = $exchange;
        $config->initialCapital = (string) $capital;
        $config->stakeCurrency = $stakeCurrency;
        $config->method = $method;
        $config->iterations = $iterations;
        $config->populationSize = $population;
        $config->generations = $generations;
        $config->objective = $objective;
        $config->topN = $topN;
        $config->parameterOverrides = $parameterOverrides;
        $config->startDate = $startDate ? new DateTimeImmutable($startDate->toIso8601String()) : null;
        $config->endDate = $endDate ? new DateTimeImmutable($endDate->toIso8601String()) : null;

        $optimizationRun = $optimizer->optimize($config);

        $this->newLine();
        $this->info('Optimization completed!');
        $this->line("  Optimization ID: {$optimizationRun->id}");
        $this->line("  Status: {$optimizationRun->status}");

        if ($optimizationRun->isCompleted()) {
            $this->newLine();
            $this->info('Best parameters:');
            foreach ($optimizationRun->best_parameters as $param => $value) {
                $this->line("  - $param: $value");
            }
            $this->newLine();
            $this->info('Best statistics:');
            $stats = $optimizationRun->best_statistics;
            $this->line('  - Net Profit: '.number_format((float) ($stats['total_return_percent'] ?? 0), 2).'%');
            $this->line('  - Win Rate: '.number_format((float) ($stats['win_rate'] ?? 0) * 100, 2).'%');
            $this->line('  - Sharpe Ratio: '.number_format((float) ($stats['sharpe_ratio'] ?? 0), 2));
            $this->line('  - Max Drawdown: '.number_format((float) ($stats['max_drawdown_percent'] ?? 0) * 100, 2).'%');
        }

        return 0;
    }
}
