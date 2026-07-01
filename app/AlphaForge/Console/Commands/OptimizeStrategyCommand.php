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
use App\AlphaForge\Console\Concerns\HasJsonOutput;
use App\AlphaForge\Services\DataAutoGenerator;
use App\AlphaForge\Strategy\Service\StrategyInputParser;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Safe\DateTimeImmutable;

class OptimizeStrategyCommand extends Command
{
    use HasJsonOutput;
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
        {--execution-timeframe= : Lower timeframe for order/position execution (e.g., 1m, 5m)}
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
        {--workers=auto : Number of parallel workers (auto = CPU core count)}
        {--resume= : Optimization ID to resume from checkpoint}
        {--checkpoint-interval=0 : Save checkpoint every N iterations (0=disabled)}
        {--sizing-model=percent_of_equity : Position sizing model (percent_of_equity, risk_based, fixed_dollar, kelly, atr_volatility)}
        {--risk-per-trade=1.0 : Percentage of equity risked per trade (for risk_based model)}
        {--max-leverage=1.0 : Maximum notional exposure as multiple of equity}
        {--fixed-stake= : Fixed dollar amount per trade (for fixed_dollar model)}
        {--auto-generate : Auto-generate derived data files (renko, heikenashi, atr_renko, aggregated OHLCV)}
        {--json : Output results as JSON}
        {--schema : Display command parameter schema as JSON}
        {--debug : Show peak memory usage on exit}';

    protected $description = 'Run strategy parameter optimization';

    public function handle(Optimizer $optimizer, StrategyInputParser $inputParser,
        DataAutoGenerator $dataAutoGenerator): int
    {
        if (($code = $this->handleSchemaFlag()) !== null) {
            return $code;
        }

        $strategyAlias = $this->argument('strategy');
        $symbol = $this->argument('symbol');
        $exchange = $this->option('exchange');
        $timeframeValue = $this->option('timeframe');
        $capital = $this->option('capital');
        $stakeCurrency = $this->option('stake-currency');
        $startDateOption = $this->option('start-date');
        $endDateOption = $this->option('end-date');
        $executionTimeframeValue = $this->option('execution-timeframe');
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
            return $this->outputJsonError($e->getMessage());
        }

        // Auto-generate warnings and generation
        if (! $this->jsonEnabled()) {
            foreach ($dataTypeConfig->warnings as $warning) {
                $this->warn($warning);
            }
        }

        // Auto-generate derived data when --auto-generate is set
        if ($this->option('auto-generate') && ! $this->jsonEnabled()) {
            $this->line("Auto-generate enabled — checking derived data for {$symbol} / {$timeframeValue}...");

            $genResult = $dataAutoGenerator->autoGenerate(
                $dataTypeConfig,
                $exchange,
                $symbol,
                $timeframeValue,
                executionTimeframe: $executionTimeframeValue,
                additionalTimeframes: [],
                output: fn (string $msg) => $this->line("  {$msg}"),
            );

            foreach ($genResult['generated'] as $path) {
                $this->line("  Generated: {$path}");
            }

            foreach ($genResult['errors'] as $err) {
                $this->error($err);
            }

            if (! empty($genResult['errors'])) {
                $this->debugMemory();

                return self::FAILURE;
            }

            $this->newLine();
        } elseif ($this->option('auto-generate') && $this->jsonEnabled()) {
            $genResult = $dataAutoGenerator->autoGenerate(
                $dataTypeConfig,
                $exchange,
                $symbol,
                $timeframeValue,
                executionTimeframe: $executionTimeframeValue,
                additionalTimeframes: [],
                output: fn (string $msg) => null,
            );

            if (! empty($genResult['errors'])) {
                return $this->outputJsonError(implode('; ', $genResult['errors']));
            }
        }

        $timeframe = TimeframeEnum::tryFrom($timeframeValue);
        if (! $timeframe) {
            return $this->outputJsonError("Invalid timeframe: $timeframeValue");
        }

        $executionTimeframe = null;
        if ($executionTimeframeValue) {
            $executionTimeframe = TimeframeEnum::tryFrom($executionTimeframeValue);
            if (! $executionTimeframe) {
                return $this->outputJsonError("Invalid execution timeframe: $executionTimeframeValue. Valid values: 1m, 5m, 15m, 30m, 1h, 4h, 1d, 1w, 1M");
            }

            if ($executionTimeframe->toSeconds() >= $timeframe->toSeconds()) {
                return $this->outputJsonError("Execution timeframe ({$executionTimeframe->value}) must be lower (finer granularity) than the signal timeframe ({$timeframe->value}).");
            }
        }

        $method = OptimizationMethod::tryFrom($methodValue);
        if (! $method) {
            return $this->outputJsonError("Invalid method: $methodValue. Use: grid, random, genetic");
        }

        $startDate = $startDateOption ? Carbon::parse($startDateOption) : null;
        $endDate = $endDateOption ? Carbon::parse($endDateOption) : null;

        $parameterOverrides = null;

        if ($useStrategyRanges) {
            $parameterRanges = $optimizer->getParameterRangesFromStrategy($strategyAlias);
            if (empty($parameterRanges)) {
                return $this->outputJsonError("No parameter ranges found for strategy: $strategyAlias");
            }
            if (! $this->jsonEnabled()) {
                $this->info('Using strategy-defined parameter ranges:');
                foreach ($parameterRanges as $param => $range) {
                    $this->line("  - $param: {$range['min']} to {$range['max']} (step: {$range['step']})");
                }
            }
        } elseif ($paramsJson) {
            $parsed = $inputParser->parseInputs($paramsJson);
            if ($parsed === false) {
                return $this->outputJsonError('Invalid JSON for --params: '.json_last_error_msg());
            }
            $parameterOverrides = $parsed;
        } else {
            return $this->outputJsonError('Either --params or --use-strategy-ranges must be specified');
        }

        if (! $this->jsonEnabled()) {
            $this->info('Starting optimization...');
            $this->line("  Strategy: $strategyAlias");
            $this->line("  Symbol: $symbol");
            $this->line("  Timeframe: {$timeframe->value}");
            if ($executionTimeframe !== null) {
                $this->line("  Execution Timeframe: {$executionTimeframe->value}");
                $this->line('  Execution Model: Signals on completed '.$timeframe->value.' bars; orders executed using '.$executionTimeframe->value.' market data; SL/TP evaluated on '.$executionTimeframe->value.' candles. No intraminute tick simulation.');
            }
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
        }

        $runnerValue = $this->option('runner');
        $runnerMode = $this->resolveRunnerMode($runnerValue);
        $workerCount = $this->resolveWorkerCount($this->option('workers'));

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
        $config->executionTimeframe = $executionTimeframe;
        $config->runnerMode = $runnerMode;
        $config->workerCount = $workerCount;

        $sizingModel = $this->option('sizing-model');
        $config->sizingModel = $sizingModel;
        $config->sizingConfig = [
            'riskPerTrade' => (float) $this->option('risk-per-trade'),
            'maxLeverage' => (float) $this->option('max-leverage'),
        ];
        if ($this->option('fixed-stake') !== null) {
            $config->sizingConfig['fixedStake'] = $this->option('fixed-stake');
        }

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
                $sortino = number_format((float) ($p->statistics['sortino_ratio'] ?? 0), 2);
                $balance = number_format((float) ($p->statistics['final_capital'] ?? 0), 2);
                $trades = (int) ($p->statistics['total_trades'] ?? 0);
                $ddPct = (float) ($p->statistics['max_drawdown_percent'] ?? 0) * 100;
                $retPct = (float) ($p->statistics['total_return_percent'] ?? 0);
                $volPct = (float) ($p->statistics['volatility'] ?? 0) * 100;

                $this->line(sprintf(
                    '  [%'.$iterWidth.'d/%'.$iterWidth.'d] %-35s │ #trd= %5d │ vol= %6.2f%% │ dd= %5.2f%% │ bal= %12s │ ret= %6.1f%% │ score= %8.4f │ sharpe= %6s │ sortino= %6s',
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
                    $sortino,
                ));
            },
            default => null,
        };

        if (! $this->jsonEnabled()) {
            $this->line('  Loading market data...');
        }

        $checkpointInterval = (int) $this->option('checkpoint-interval');
        $resumeFromId = $this->option('resume');

        try {
            $optimizationRun = $optimizer->optimize($config, $progressCallback, $checkpointInterval, $resumeFromId);
        } catch (\Throwable $e) {
            return $this->outputJsonError('Optimization failed: '.$e->getMessage());
        }

        $progressBar?->finish();
        if ($progressLevel === 2 && $dotCount > 0 && $dotCount % 80 !== 0 && ! $this->jsonEnabled()) {
            $this->newLine();
        }

        if ($this->jsonEnabled()) {
            return $this->outputJson(true, [
                'optimizationId' => $optimizationRun->id,
                'strategy' => $optimizationRun->strategy_alias,
                'symbol' => $optimizationRun->symbols[0] ?? null,
                'method' => $optimizationRun->optimization_method,
                'objective' => $optimizationRun->optimization_metric,
                'iterations' => $iterations,
                'timeframe' => $optimizationRun->timeframe,
                'dataType' => $dataTypeConfig->dataType,
                'bestParameters' => $optimizationRun->best_parameters,
                'bestStatistics' => $optimizationRun->best_statistics,
            ]);
        }

        $this->newLine();

        if ($runnerMode === ParallelRunnerMode::QUEUE) {
            $this->info('Optimization dispatched to queue!');
            $this->line("  Optimization ID: {$optimizationRun->id}");
            $this->line("  Status: {$optimizationRun->status} (queued)");
            $this->line('  Monitor progress with:');
            $this->line("    php artisan alphaforge:optimizations:show {$optimizationRun->id}");
            $this->line('  Or check the queue worker logs.');
            $this->line('  Results will be persisted to the database upon completion.');
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
                $this->line('  - Sortino Ratio: '.number_format((float) ($stats['sortino_ratio'] ?? 0), 2));
                $this->line('  - Max Drawdown: '.number_format((float) ($stats['max_drawdown_percent'] ?? 0) * 100, 2).'%');
            }
        }

        $this->debugMemory();

        return 0;
    }
}
