<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Dto\DataTypeConfig;
use App\AlphaForge\Backtesting\Optimization\OptimizationConfig;
use App\AlphaForge\Backtesting\Optimization\OptimizationMethod;
use App\AlphaForge\Backtesting\Optimization\OptimizationProgress;
use App\AlphaForge\Backtesting\Optimization\Optimizer;
use App\AlphaForge\Backtesting\Optimization\ParallelRunnerMode;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Console\Commands\Concerns\ResolvesParallelRunner;
use App\AlphaForge\Strategy\Service\StrategyInputParser;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Safe\DateTimeImmutable;

class OptimizeStrategyCommand extends Command
{
    use ResolvesParallelRunner;
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
        {--top-n=50 : Number of top results to persist}
        {--data-type=ohlcv : Market data type to backtest against (ohlcv, heikenashi, renko, atr_renko)}
        {--brick-size= : Brick size for renko data-type (e.g., 0.001, 10, 100)}
        {--atr-period= : ATR period for atr_renko data-type (e.g., 14)}
        {--progress=1 : Progress verbosity (0=none, 1=bar, 2=dots, 3=detailed)}
        {--runner=fork : Parallel runner mode (sync, fork, queue)}
        {--workers=auto : Number of parallel workers (auto = CPU core count)}';

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

        try {
            $dataTypeConfig = DataTypeConfig::fromOptions(
                $this->option('data-type'),
                $this->option('brick-size'),
                $this->option('atr-period'),
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        foreach ($dataTypeConfig->warnings as $warning) {
            $this->warn($warning);
        }

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

        $runnerValue = $this->option('runner');
        $runnerMode = $this->resolveRunnerMode($runnerValue);
        $workerCount = $this->resolveWorkerCount($this->option('workers'));

        $this->line("  Runner: {$runnerMode->value}".($runnerMode === ParallelRunnerMode::FORK ? " ({$workerCount} workers)" : ''));

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
        $config->dataType = $dataTypeConfig->dataType;
        $config->brickSize = $dataTypeConfig->brickSize;
        $config->atrPeriod = $dataTypeConfig->atrPeriod;
        $config->runnerMode = $runnerMode;
        $config->workerCount = $workerCount;

        $progressLevel = (int) $this->option('progress');
        $progressBar = null;
        $dotCount = 0;

        $progressCallback = match ($progressLevel) {
            0 => null,
            1 => function (OptimizationProgress $p) use (&$progressBar) {
                if ($progressBar === null && $p->total > 0) {
                    $progressBar = $this->output->createProgressBar($p->total);
                    $progressBar->setFormat('  %current%/%max% [%bar%] %percent:3s%%');
                    $progressBar->start();
                }
                $progressBar?->setProgress($p->completed);
            },
            2 => function (OptimizationProgress $p) use (&$dotCount) {
                $this->output->write('.');
                $dotCount++;
                if ($dotCount % 80 === 0) {
                    $this->output->write(sprintf(" %d/%d\n", $p->completed, $p->total));
                }
            },
            3 => function (OptimizationProgress $p) {
                $iterWidth = max(2, strlen((string) $p->total));
                $paramsStr = implode(', ', array_map(
                    fn ($k, $v) => $k.'='.(is_float($v) ? number_format($v, 4) : (is_int($v) ? (string) $v : $v)),
                    array_keys($p->parameters),
                    $p->parameters,
                ));

                if ($p->error !== null) {
                    $this->line(sprintf(
                        '  [%'.$iterWidth.'d/%'.$iterWidth.'d] %-35s │ ERROR │ %s',
                        $p->completed,
                        $p->total,
                        mb_strimwidth($paramsStr, 0, 35, '…'),
                        mb_strimwidth($p->error, 0, 90, '…'),
                    ));

                    return;
                }

                $sharpe = number_format((float) ($p->statistics['sharpe_ratio'] ?? 0), 2);
                $balance = number_format((float) ($p->statistics['final_capital'] ?? 0), 2);
                $trades = (int) ($p->statistics['total_trades'] ?? 0);
                $ddPct = (float) ($p->statistics['max_drawdown_percent'] ?? 0) * 100;
                $retPct = (float) ($p->statistics['total_return_percent'] ?? 0) * 100;
                $volPct = (float) ($p->statistics['volatility'] ?? 0) * 100;

                $this->line(sprintf(
                    '  [%'.$iterWidth.'d/%'.$iterWidth.'d] %-35s │ #trd= %5d │ vol= %6.2f%% │ dd= %5.2f%% │ bal= %12s │ ret= %6.1f%% │ score= %8.4f │ sharpe= %6s',
                    $p->completed,
                    $p->total,
                    mb_strimwidth($paramsStr, 0, 35, '…'),
                    $trades,
                    $volPct,
                    $ddPct,
                    $balance,
                    $retPct,
                    $p->score,
                    $sharpe,
                ));
            },
            default => null,
        };

        $this->line('  Loading market data...');

        $optimizationRun = $optimizer->optimize($config, $progressCallback);

        $progressBar?->finish();
        if ($progressLevel === 2 && $dotCount > 0 && $dotCount % 80 !== 0) {
            $this->newLine();
        }

        $this->newLine();

        if ($runnerMode === ParallelRunnerMode::QUEUE) {
            $this->info('Optimization dispatched to queue!');
            $this->line("  Optimization ID: {$optimizationRun->id}");
            $this->line("  Status: {$optimizationRun->status} (queued)");
            $this->line('  Monitor progress with:');
            $this->line("    php artisan alphaforge:optimizations:show {$optimizationRun->id}");
            $this->line('  Or check the queue worker logs.');
            $this->line("  Results will be persisted to the database upon completion.");
        } else {
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
        }

        return 0;
    }
}
