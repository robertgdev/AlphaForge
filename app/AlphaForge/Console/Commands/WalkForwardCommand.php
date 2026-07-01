<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Dto\DataTypeConfig;
use App\AlphaForge\Backtesting\Dto\WalkForwardConfiguration;
use App\AlphaForge\Backtesting\Model\WalkForwardResult;
use App\AlphaForge\Backtesting\Model\WalkForwardRun;
use App\AlphaForge\Backtesting\Optimization\OptimizationMethod;
use App\AlphaForge\Backtesting\Optimization\ParallelRunnerMode;
use App\AlphaForge\Backtesting\WalkForward\StrategyGrader;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardAnalysis;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardAnalyzer;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardExporter;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardService;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Console\Commands\Concerns\ResolvesParallelRunner;
use App\AlphaForge\Console\Concerns\HasJsonOutput;
use App\AlphaForge\Services\DataAutoGenerator;
use App\AlphaForge\Strategy\Service\StrategyInputParser;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Safe\DateTimeImmutable;

use function Safe\file_put_contents;

class WalkForwardCommand extends Command
{
    use HasJsonOutput;
    use ResolvesParallelRunner;

    protected $signature = 'alphaforge:walk-forward
        {strategy : The strategy alias}
        {symbol : Trading symbol}
        {--exchange=binance : Exchange identifier}
        {--timeframe=1h : Timeframe}
        {--execution-timeframe= : Lower timeframe for order/position execution (e.g., 1m, 5m)}
        {--capital=10000 : Initial capital}
        {--stake-currency=USDT : Stake currency}
        {--start-date= : Full data range start date (Y-m-d)}
        {--end-date= : Full data range end date (Y-m-d)}
        {--split=0.75 : In-sample fraction (e.g., 0.75 = 75% backtest, 25% forward)}
        {--oos-start= : Explicit out-of-sample start date (Y-m-d, overrides --split)}
        {--method=random : Optimization method (grid, random, genetic)}
        {--iterations=500 : Number of iterations for random search}
        {--population=50 : Population size for genetic algorithm}
        {--generations=20 : Number of generations for genetic algorithm}
        {--objective=sharpe_ratio : Objective function}
        {--top-n=50 : Number of top results to persist and forward-test}
        {--params= : Parameter ranges as JSON}
        {--use-strategy-ranges : Use strategy\'s defined min/max ranges}
        {--min-trades=0 : Minimum OOS trade count for statistical reliability}
        {--min-oos-days=0 : Warn if OOS period has fewer days than this (recommended: 90)}
        {--data-type=ohlcv : Market data type (ohlcv, heikenashi, renko, atr_renko)}
        {--brick-size= : Brick size for renko data-type (e.g., 0.001, 10, 100)}
        {--atr-period= : ATR period for atr_renko data-type (e.g., 14)}
        {--force : Skip data range warnings}
        {--format=table : Output format (table, csv, json)}
        {--output= : Write output to file instead of stdout}
        {--runner=fork : Parallel runner mode for optimization phase (sync, fork, queue)}
        {--workers=auto : Number of parallel workers (auto = CPU core count)}
        {--sizing-model=percent_of_equity : Position sizing model (percent_of_equity, risk_based, fixed_dollar, kelly, atr_volatility)}
        {--risk-per-trade=1.0 : Percentage of equity risked per trade (for risk_based model)}
        {--max-leverage=1.0 : Maximum notional exposure as multiple of equity}
        {--fixed-stake= : Fixed dollar amount per trade (for fixed_dollar model)}
        {--auto-generate : Auto-generate derived data files (renko, heikenashi, atr_renko, aggregated OHLCV)}
        {--json : Output results as JSON}
        {--schema : Display command parameter schema as JSON}
        {--debug : Show peak memory usage on exit}';

    protected $description = 'Run walk-forward analysis: optimize on in-sample data, validate on out-of-sample data';

    public function handle(WalkForwardService $service, WalkForwardAnalyzer $analyzer, WalkForwardExporter $exporter, StrategyInputParser $inputParser,
        DataAutoGenerator $dataAutoGenerator): int
    {
        if (($code = $this->handleSchemaFlag()) !== null) {
            return $code;
        }

        $strategyAlias = $this->argument('strategy');
        $symbol = $this->argument('symbol');
        $exchange = $this->option('exchange');
        $timeframeValue = $this->option('timeframe');
        $executionTimeframeValue = $this->option('execution-timeframe');
        $capital = $this->option('capital');
        $stakeCurrency = $this->option('stake-currency');
        $startDateOption = $this->option('start-date');
        $endDateOption = $this->option('end-date');
        $splitRatio = (float) $this->option('split');
        $oosStartOption = $this->option('oos-start');
        $methodValue = $this->option('method');
        $iterations = (int) $this->option('iterations');
        $population = (int) $this->option('population');
        $generations = (int) $this->option('generations');
        $objective = $this->option('objective');
        $topN = (int) $this->option('top-n');
        $paramsJson = $this->option('params');
        $useStrategyRanges = $this->option('use-strategy-ranges');
        $minTrades = (int) $this->option('min-trades');
        $minOosDays = (int) $this->option('min-oos-days');
        $dataTypeValue = $this->option('data-type');
        $brickSize = $this->option('brick-size');
        $atrPeriod = $this->option('atr-period');
        $force = $this->option('force');
        $format = $this->option('format');
        $outputPath = $this->option('output');

        $useJson = $this->jsonEnabled();

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

        if ($splitRatio <= 0 || $splitRatio >= 1) {
            return $this->outputJsonError('--split must be between 0 and 1 (exclusive)');
        }

        if (! in_array($format, ['table', 'csv', 'json'])) {
            return $this->outputJsonError("Invalid format: $format. Use: table, csv, json");
        }

        if ($this->jsonEnabled() && $this->input->hasParameterOption('--format')) {
            return $this->outputJsonError('Cannot use --json and --format together. Use one or the other.');
        }

        try {
            $dataTypeConfig = DataTypeConfig::fromOptions($dataTypeValue, $brickSize, $atrPeriod);
        } catch (\InvalidArgumentException $e) {
            return $this->outputJsonError($e->getMessage());
        }

        foreach ($dataTypeConfig->warnings as $warning) {
            $this->warn($warning);

            // Auto-generate derived data when --auto-generate is set
            if ($this->option('auto-generate')) {
                $this->line("Auto-generate enabled — checking derived data for {$symbol} / {$timeframeValue}...");

                $genResult = $dataAutoGenerator->autoGenerate(
                    $dataTypeConfig,
                    $exchange,
                    $symbol,
                    $timeframeValue,
                    $executionTimeframeValue,
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
            }

        }

        $dataTypeValue = $dataTypeConfig->dataType;
        $brickSize = $dataTypeConfig->brickSize;
        $atrPeriod = $dataTypeConfig->atrPeriod;

        $startDate = $startDateOption ? Carbon::parse($startDateOption) : null;
        $endDate = $endDateOption ? Carbon::parse($endDateOption) : null;

        $parameterOverrides = null;

        if ($useStrategyRanges) {
            $this->info('Using strategy-defined parameter ranges.');
        } elseif ($paramsJson) {
            $parsed = $inputParser->parseInputs($paramsJson);
            if ($parsed === false) {
                $this->error('Invalid JSON for --params: '.json_last_error_msg());

                $this->debugMemory();

                return 1;
            }
            $parameterOverrides = $parsed;
        } else {
            return $this->outputJsonError('Either --params or --use-strategy-ranges must be specified');
        }

        $this->info('Starting Walk-Forward Analysis...');
        $this->newLine();

        $runnerMode = $this->resolveRunnerMode($this->option('runner'));
        $workerCount = $this->resolveWorkerCount($this->option('workers'));

        if (! $this->jsonEnabled()) {
            $this->line("  Strategy: $strategyAlias");
            $this->line("  Symbol: $symbol");
            $this->line("  Timeframe: {$timeframe->value}");

            if ($executionTimeframe !== null) {
                $this->line("  Execution Timeframe: {$executionTimeframe->value}");
                $this->line('  Execution Model: Signals on completed '.$timeframe->value.' bars; orders executed using '.$executionTimeframe->value.' market data; SL/TP evaluated on '.$executionTimeframe->value.' candles. No intraminute tick simulation.');
            }

            $this->line("  Method: {$method->value}");
            $this->line("  Objective: $objective");
            $this->line("  Top-N: $topN");
            $this->line("  Data Type: $dataTypeValue");
            if ($brickSize !== null) {
                $this->line("  Brick Size: $brickSize");
            }
            if ($atrPeriod !== null) {
                $this->line("  ATR Period: $atrPeriod");
            }
            $this->line('  Split: '.($splitRatio * 100).'% in-sample / '.((1 - $splitRatio) * 100).'% out-of-sample');

            if ($minTrades > 0) {
                $this->line("  Min Trades: $minTrades");
            }

            if ($method === OptimizationMethod::RANDOM) {
                $this->line("  Iterations: $iterations");
            } elseif ($method === OptimizationMethod::GENETIC) {
                $this->line("  Population: $population");
                $this->line("  Generations: $generations");
            }

            $this->line("  Runner: {$runnerMode->value}".($runnerMode === ParallelRunnerMode::FORK ? " ({$workerCount} workers)" : ''));
            $this->newLine();
        }

        $config = new WalkForwardConfiguration;
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
        $config->splitRatio = $splitRatio;
        $config->oosStartDate = $oosStartOption;
        $config->parameterOverrides = $parameterOverrides;
        $config->startDate = $startDate ? new DateTimeImmutable($startDate->toIso8601String()) : null;
        $config->endDate = $endDate ? new DateTimeImmutable($endDate->toIso8601String()) : null;
        $config->executionTimeframe = $executionTimeframe;
        $config->minTrades = $minTrades > 0 ? $minTrades : null;
        $config->dataType = $dataTypeConfig->dataType;
        $config->brickSize = $dataTypeConfig->brickSize;
        $config->atrPeriod = $dataTypeConfig->atrPeriod;
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

        try {
            [$isStart, $isEnd, $oosStart, $oosEnd] = $service->computeDateSplit($config);
        } catch (\InvalidArgumentException $e) {
            return $this->outputJsonError($e->getMessage());
        }

        if (! $force && $minOosDays > 0) {
            $oosDays = $oosStart->diffInDays($oosEnd);
            if ($oosDays < $minOosDays) {
                $this->warn("OOS period is only {$oosDays} days — results may not be statistically meaningful.");
                $this->line('  Consider using a longer date range or a lower split ratio.');
            }
        }

        if (! $useJson) {
            $this->info('Phase 1: Optimization (In-Sample)');
            $this->line('  Period: '.$isStart->toDateString().' → '.$isEnd->toDateString());
            $this->newLine();
        }

        $progressCallback = null;
        $bar = null;

        if ($format === 'table' && $this->output->isVerbose()) {
            $bar = $this->output->createProgressBar($topN);
            $bar->setFormat('  %message% %current%/%max% [%bar%] %percent:1s%%');
            $bar->setMessage('Forward testing...');
        }

        $progressCallback = function (int $completed, int $total) use ($bar) {
            if ($bar !== null) {
                $bar->setMaxSteps($total);
                $bar->setProgress($completed);
            }
        };

        try {
            $wfRun = $service->run($config, $progressCallback);
        } catch (\Throwable $e) {
            return $this->outputJsonError('Walk-forward analysis failed: '.$e->getMessage());
        } finally {
            $bar?->finish();
            if ($bar !== null) {
                $this->newLine();
            }
        }

        if ($wfRun->hasFailed()) {
            return $this->outputJsonError('Walk-forward analysis failed: '.($wfRun->error_message ?? 'Unknown error'));
        }

        if (! $useJson) {
            $this->newLine();
            $this->info('Phase 2: Forward Validation (Out-of-Sample)');
            $this->line('  Period: '.$oosStart->toDateString().' → '.$oosEnd->toDateString());
            $this->line('  Tested top '.$topN.' parameter sets');
            $this->newLine();
        }

        $analysis = $analyzer->analyze($wfRun, $minTrades);

        if ($useJson) {
            return $this->outputJson(true, $this->buildWalkForwardJson($wfRun, $analysis), outputPath: $outputPath);
        }

        if ($format === 'csv') {
            $csv = $exporter->toCsv($analysis);
            $this->outputResult($csv, $outputPath);

            $this->debugMemory();

            return 0;
        }

        if ($format === 'json') {
            $json = $exporter->toJson($analysis);
            $this->outputResult($json, $outputPath);

            $this->debugMemory();

            return 0;
        }

        if ($useJson) {
            return 0;
        }

        $this->displayResultsTable($analysis);

        $this->displaySummary($analysis);

        $this->displayResearchConclusion($analysis);

        $this->newLine();
        $this->line("  Walk-Forward Run ID: {$wfRun->id}");
        if ($wfRun->optimization_run_id) {
            $this->line("  Optimization Run ID: {$wfRun->optimization_run_id}");
        }

        $this->debugMemory();

        return 0;
    }

    private function buildWalkForwardJson(WalkForwardRun $wfRun, WalkForwardAnalysis $analysis): array
    {
        $grade = StrategyGrader::grade($analysis);

        return [
            'walk_forward_run_id' => $wfRun->id,
            'optimization_run_id' => $wfRun->optimization_run_id,
            'strategy' => $wfRun->strategy_alias,
            'symbols' => $wfRun->symbols,
            'timeframe' => $wfRun->timeframe,
            'execution_timeframe' => $wfRun->execution_timeframe,
            'data_type' => $wfRun->data_type,
            'is_period' => $wfRun->is_start_date?->format('Y-m-d').' to '.$wfRun->is_end_date?->format('Y-m-d'),
            'oos_period' => $wfRun->oos_start_date?->format('Y-m-d').' to '.$wfRun->oos_end_date?->format('Y-m-d'),
            'split_ratio' => $wfRun->split_ratio,
            'optimization_method' => $wfRun->optimization_method,
            'optimization_objective' => $wfRun->optimization_objective,
            'top_n' => $wfRun->top_n,
            'status' => $wfRun->status,
            'stability_classification' => $analysis->stabilityClassification,
            'stability_interpretation' => $analysis->stabilityInterpretation,
            'economic_performance' => $analysis->economicPerformance,
            'economic_interpretation' => $analysis->economicInterpretation,
            'robust_count' => $analysis->robustCount,
            'robust_ratio' => $analysis->robustRatio,
            'beat_buy_hold_count' => $analysis->beatBuyHoldCount,
            'beat_buy_hold_ratio' => $analysis->beatBuyHoldRatio,
            'return_gt_10_count' => $analysis->returnGt10Count,
            'return_gt_10_ratio' => $analysis->returnGt10Ratio,
            'sharpe_beat_benchmark_count' => $analysis->sharpeBeatBenchmarkCount,
            'sharpe_beat_benchmark_ratio' => $analysis->sharpeBeatBenchmarkRatio,
            'median_is_score' => $analysis->medianIsScore,
            'median_oos_score' => $analysis->medianOosScore,
            'median_oos_return' => $analysis->medianOosReturn,
            'median_oos_sharpe' => $analysis->medianOosSharpe,
            'median_oos_max_dd' => $analysis->medianOosMaxDd,
            'avg_degradation' => $analysis->avgDegradation,
            'median_degradation' => $analysis->medianDegradation,
            'rank_correlation' => $analysis->rankCorrelation,
            'rank_stability_label' => $analysis->rankStabilityLabel,
            'reliable_count' => $analysis->reliableCount,
            'reliable_ratio' => $analysis->reliableRatio,
            'min_trades' => $analysis->minTrades,
            'suspicious_sharpe' => $analysis->suspiciousSharpe,
            'low_trade_warning' => $analysis->lowTradeWarning,
            'boundary_warnings' => array_map(fn (array $w) => [
                'param' => $w['param'],
                'direction' => $w['direction'],
                'boundary' => $w['boundary'],
                'pct' => $w['pct'],
            ], $analysis->boundaryWarnings),
            'benchmark' => $analysis->benchmarkHasData ? [
                'return_pct' => $analysis->benchmarkReturn,
                'max_drawdown_pct' => $analysis->benchmarkMaxDrawdown,
                'sharpe' => $analysis->benchmarkSharpe,
            ] : null,
            'time_in_market' => $analysis->timeInMarket,
            'exposure_adjusted_target' => $analysis->exposureAdjustedTarget,
            'capture_ratio' => $analysis->captureRatio,
            'market_capture' => $analysis->marketCapture,
            'capital_efficiency' => $analysis->capitalEfficiency,
            'grade' => [
                'score' => $grade['score'],
                'stars' => $grade['stars'],
                'label' => $grade['label'],
                'breakdown' => $grade['breakdown'],
                'stars_by_category' => $grade['stars_by_category'],
            ],
            'best_parameters' => $wfRun->best_parameters,
            'best_oos_result' => $analysis->bestOosResult ? [
                'rank' => $analysis->bestOosRank,
                'parameters' => $analysis->bestOosResult->parameters,
                'is_score' => $analysis->bestOosResult->is_score,
                'oos_score' => $analysis->bestOosResult->oos_score,
                'score_degradation' => $analysis->bestOosResult->score_degradation,
                'is_statistics' => $analysis->bestOosResult->is_statistics,
                'oos_statistics' => $analysis->bestOosResult->oos_statistics,
            ] : null,
            'results' => array_map(fn (WalkForwardResult $r) => [
                'rank' => $r->rank,
                'parameters' => $r->parameters,
                'is_score' => $r->is_score,
                'oos_score' => $r->oos_score,
                'score_degradation' => $r->score_degradation,
                'is_statistics' => $r->is_statistics,
                'oos_statistics' => $r->oos_statistics,
            ], $analysis->results),
        ];
    }

    private function outputResult(string $content, ?string $outputPath): void
    {
        if ($outputPath) {
            $dir = dirname($outputPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($outputPath, $content);
            $this->info("Output written to {$outputPath}");
        } else {
            $this->line($content);
        }
    }

    private function displayResultsTable(WalkForwardAnalysis $analysis): void
    {
        $this->info('Results Summary');
        $this->line(str_repeat('─', 80));

        $headers = ['Rank', 'Parameters', 'IS Score', 'OOS Score', 'Degradation'];
        $rows = [];

        foreach (array_slice($analysis->results, 0, 20) as $result) {
            $params = collect($result->parameters)
                ->map(fn ($v, $k) => "$k=$v")
                ->implode(', ');

            if (strlen($params) > 30) {
                $params = substr($params, 0, 27).'...';
            }

            $rows[] = [
                $result->rank,
                $params,
                number_format($result->is_score ?? 0, 2),
                number_format($result->oos_score ?? 0, 2),
                $this->formatDegradation($result->score_degradation ?? 0),
            ];
        }

        $this->table($headers, $rows);

        $totalResults = count($analysis->results);
        if ($totalResults > 20) {
            $this->line('  ... and '.($totalResults - 20).' more results (use Walk-Forward Run ID to query all)');
        }
    }

    private function formatDegradation(float $degradation): string
    {
        if ($degradation < 0) {
            return '↑ +'.number_format(abs($degradation), 1).'%';
        }

        return '↓ -'.number_format($degradation, 1).'%';
    }

    private function displaySummary(WalkForwardAnalysis $analysis): void
    {
        $this->newLine();
        $this->info('Walk-Forward Summary');
        $this->line(str_repeat('─', 40));

        $stabilityLabel = strtoupper($analysis->stabilityClassification);
        $this->line("  Parameter Stability: {$stabilityLabel} — {$analysis->stabilityInterpretation}");

        $ecoLabel = strtoupper($analysis->economicPerformance);
        $this->line("  Economic Performance: {$ecoLabel} — {$analysis->economicInterpretation}");

        if ($analysis->economicPerformance === 'poor' && $analysis->stabilityClassification !== 'likely_overfit') {
            $this->newLine();
            $this->line('  <fg=yellow>⚠ Stable optimization does not imply a profitable strategy.</>');
            if ($analysis->benchmarkHasData) {
                $this->line('  <fg=yellow>⚠ Out-of-sample returns materially lag buy-and-hold.</>');
            }
        }

        $this->newLine();
        $this->line('  Robust parameters:');
        if ($analysis->benchmarkHasData) {
            $this->line('    Beat buy-and-hold:         '.$analysis->beatBuyHoldCount.'/'.count($analysis->results).' ('.number_format($analysis->beatBuyHoldRatio * 100, 1).'%)');
        }
        $this->line('    Positive OOS return:       '.$analysis->robustCount.'/'.count($analysis->results).' ('.number_format($analysis->robustRatio * 100, 1).'%)');
        if ($analysis->benchmarkHasData) {
            $this->line('    Sharpe > benchmark:        '.$analysis->sharpeBeatBenchmarkCount.'/'.count($analysis->results).' ('.number_format($analysis->sharpeBeatBenchmarkRatio * 100, 1).'%)');
        }
        $this->line('    Return > 10%:              '.$analysis->returnGt10Count.'/'.count($analysis->results).' ('.number_format($analysis->returnGt10Ratio * 100, 1).'%)');

        if ($analysis->reliableCount > 0 || $analysis->minTrades > 0) {
            $this->line("    Statistically reliable (≥{$analysis->minTrades} trades, profitable OOS): {$analysis->reliableCount}/".count($analysis->results).' ('.number_format($analysis->reliableRatio * 100, 1).'%)');
        }

        $this->line('  Median IS score: '.number_format($analysis->medianIsScore, 2));
        $this->line('  Median OOS score: '.number_format($analysis->medianOosScore, 2));
        $this->line('  Median OOS return: '.($analysis->medianOosReturn >= 0 ? '+' : '').number_format($analysis->medianOosReturn, 2).'%');
        $this->line('  Median OOS Sharpe: '.number_format($analysis->medianOosSharpe, 2));
        $this->line('  Median OOS max DD: '.number_format($analysis->medianOosMaxDd, 2).'%');
        $this->line('  Median score degradation: '.$this->formatDegradation($analysis->medianDegradation));
        $this->line('  Average score degradation: '.$this->formatDegradation($analysis->avgDegradation));

        if ($analysis->rankCorrelation !== null) {
            $this->line('  IS-OOS Rank Correlation (Spearman): '.number_format($analysis->rankCorrelation, 3).' ('.$analysis->rankStabilityLabel.')');
        }

        if ($analysis->lowTradeWarning) {
            $this->newLine();
            $this->line('  <fg=yellow>⚠ Low trade count — interpret statistical metrics with caution.</>');
        }

        if ($analysis->suspiciousSharpe) {
            $sr = $analysis->bestOosResult
                ? number_format((float) ($analysis->bestOosResult->oos_statistics['sharpe_ratio'] ?? 0), 2)
                : 'N/A';
            $rcp = $analysis->bestOosResult
                ? number_format(abs((float) ($analysis->bestOosResult->oos_statistics['total_return_percent'] ?? 0)), 2)
                : 'N/A';
            $this->newLine();
            $this->line("  <fg=yellow>⚠ High Sharpe is driven primarily by extremely low volatility, not by strong absolute returns. Sharpe {$sr} with only {$rcp}% return suggests the strategy is mostly in cash, producing a deceptively attractive risk-adjusted metric.</>");
        }

        if (! empty($analysis->boundaryWarnings)) {
            $this->newLine();
            $this->line('  <fg=yellow>⚠ Parameter boundary warnings:</>');
            foreach ($analysis->boundaryWarnings as $w) {
                $dirLabel = $w['direction'] === 'min' ? 'min' : 'max';
                $this->line("    - {$w['param']}: {$w['pct']}% of top results at {$dirLabel} ({$w['boundary']}); consider expanding the search range.");
            }
        }

        if ($analysis->bestOosResult) {
            $bestParams = collect($analysis->bestOosResult->parameters)
                ->map(fn ($v, $k) => "$k=$v")
                ->implode(', ');

            $this->line('  Best OOS: Rank '.$analysis->bestOosRank.' ('.$bestParams.')');
        }

        if ($analysis->benchmarkHasData) {
            $this->newLine();
            $this->line('  <fg=yellow>Buy & Hold Benchmark (OOS Period)</>');
            $this->line(str_repeat('─', 40));
            $this->line('  '.str_pad('Metric', 20).str_pad('Strategy (OOS)', 18).'Buy & Hold');
            $this->line('  '.str_repeat('─', 60));
            $stReturn = $analysis->bestOosResult
                ? number_format((float) ($analysis->bestOosResult->oos_statistics['total_return_percent'] ?? 0), 2).'%'
                : 'N/A';
            $bhReturn = number_format($analysis->benchmarkReturn, 2).'%';
            $this->line('  '.str_pad('Return', 20).str_pad($stReturn, 18).$bhReturn);

            $stMaxDD = $analysis->bestOosResult
                ? number_format((float) ($analysis->bestOosResult->oos_statistics['max_drawdown_percent'] ?? 0) * 100, 2).'%'
                : 'N/A';
            $bhMaxDD = number_format($analysis->benchmarkMaxDrawdown, 2).'%';
            $this->line('  '.str_pad('Max Drawdown', 20).str_pad($stMaxDD, 18).$bhMaxDD);

            $stSharpe = $analysis->bestOosResult
                ? number_format((float) ($analysis->bestOosResult->oos_statistics['sharpe_ratio'] ?? 0), 2)
                : 'N/A';
            $bhSharpe = number_format($analysis->benchmarkSharpe, 2);
            $this->line('  '.str_pad('Sharpe', 20).str_pad($stSharpe, 18).$bhSharpe);

            if ($analysis->benchmarkReturn != 0) {
                $stRetVal = $analysis->bestOosResult
                    ? (float) ($analysis->bestOosResult->oos_statistics['total_return_percent'] ?? 0)
                    : 0.0;
                $bhRetVal = $analysis->benchmarkReturn;
                $this->line(str_repeat('─', 60));
                $this->line('  <fg=yellow>Market Capture</>');
                $this->line('  '.str_repeat('─', 60));
                $this->line('  '.str_pad('Buy & Hold return', 20).str_pad(($bhRetVal >= 0 ? '+' : '').number_format($bhRetVal, 2).'%', 18).'');
                $this->line('  '.str_pad('Strategy return', 20).str_pad(($stRetVal >= 0 ? '+' : '').number_format($stRetVal, 2).'%', 18).'');
                $this->line('  '.str_pad('Upside captured', 20).str_pad(number_format($analysis->marketCapture, 1).'% of buy-and-hold', 18).'');
                if ($analysis->timeInMarket > 0) {
                    $this->line('  '.str_pad('Time invested', 20).str_pad(number_format($analysis->timeInMarket, 1).'%', 18).'');
                    $effRating = match (true) {
                        $analysis->marketCapture > 75 => 'HIGH',
                        $analysis->marketCapture > 40 => 'MODERATE',
                        $analysis->marketCapture > 15 => 'LOW',
                        default => 'VERY LOW',
                    };
                    $this->line('  '.str_pad('Efficiency rating', 20).str_pad($effRating, 18).'');
                }
            }

            if ($analysis->timeInMarket > 0) {
                $this->line(str_repeat('─', 60));
                $this->line('  '.str_pad('Time in Market', 20).str_pad(number_format($analysis->timeInMarket, 2).'%', 18).'');
                $this->line('  '.str_pad('Exposure-adj Target', 20).str_pad(number_format($analysis->exposureAdjustedTarget, 2).'%', 18).'');
                $this->line('  '.str_pad('Capture Ratio', 20).str_pad(number_format($analysis->captureRatio, 1).'%', 18).'');

                $captureRatio = $analysis->captureRatio;
                $stRet = (float) ($analysis->bestOosResult->oos_statistics['total_return_percent'] ?? 0);
                if ($captureRatio > 50) {
                    $effLabel = 'HIGH';
                    $effDesc = 'Captured '.number_format($captureRatio, 0).'% of buy-and-hold performance using '.number_format($analysis->timeInMarket, 1).'% market exposure.';
                } elseif ($captureRatio > 20) {
                    $effLabel = 'MODERATE';
                    $effDesc = 'Captured '.number_format($captureRatio, 0).'% of buy-and-hold performance using '.number_format($analysis->timeInMarket, 1).'% market exposure.';
                } else {
                    $effLabel = 'LOW';
                    $effDesc = 'Spent '.number_format($analysis->timeInMarket, 1).'% of the test invested while capturing only '.number_format($stRet, 2).'% of buy-and-hold performance ('.number_format($analysis->benchmarkReturn, 2).'%).';
                }
                $this->line('  '.str_pad('Capital Efficiency', 20).str_pad($effLabel, 18).$effDesc);
            }
        }
    }

    private function displayResearchConclusion(WalkForwardAnalysis $analysis): void
    {
        $this->newLine();
        $this->line(str_repeat('=', 60));
        $this->line('<fg=yellow>  RESEARCH CONCLUSION</>');
        $this->line(str_repeat('=', 60));
        $this->newLine();

        $positives = [];
        $warnings = [];

        $stabilityOk = in_array($analysis->stabilityClassification, ['excellent', 'good', 'moderate'], true);
        if ($stabilityOk) {
            $positives[] = 'Parameter stability appears '.$analysis->stabilityClassification
                .' ('.number_format($analysis->robustRatio * 100, 0).'% profitable OOS).';
        } else {
            $warnings[] = 'Parameter stability is '.$analysis->stabilityClassification
                .' — optimization results may not generalize.';
        }

        if ($analysis->rankCorrelation !== null) {
            $label = $analysis->rankStabilityLabel;
            $rho = number_format($analysis->rankCorrelation, 2);
            if ($analysis->rankCorrelation > 0.4) {
                $positives[] = "IS→OOS rank correlation is {$label} (ρ = {$rho}).";
            } else {
                $warnings[] = "IS→OOS rank correlation is {$label} (ρ = {$rho}) — ranks are not predictive.";
            }
        }

        if ($analysis->economicPerformance === 'strong') {
            $positives[] = 'Economic performance is strong.';
        } elseif ($analysis->economicPerformance === 'poor') {
            $warnings[] = 'Despite above robustness, economic performance is weak.';
            if ($analysis->bestOosResult && $analysis->benchmarkHasData) {
                $stRet = (float) ($analysis->bestOosResult->oos_statistics['total_return_percent'] ?? 0);
                $bhRet = $analysis->benchmarkReturn;
                $warnings[] = '    Strategy return: '.($stRet >= 0 ? '+' : '').number_format($stRet, 2).'%';
                $warnings[] = '    Buy & Hold return: '.($bhRet >= 0 ? '+' : '').number_format($bhRet, 2).'%';
            }
        }

        if ($analysis->suspiciousSharpe) {
            $sr = $analysis->bestOosResult
                ? number_format((float) ($analysis->bestOosResult->oos_statistics['sharpe_ratio'] ?? 0), 2)
                : 'N/A';
            $rcp = $analysis->bestOosResult
                ? number_format(abs((float) ($analysis->bestOosResult->oos_statistics['total_return_percent'] ?? 0)), 2)
                : 'N/A';
            $warnings[] = 'The strategy appears underinvested.';
            $warnings[] = "High Sharpe ({$sr}) is driven primarily by extremely low volatility, not by strong absolute returns. With only {$rcp}% return, the strategy is mostly in cash, producing a deceptively attractive risk-adjusted metric.";
        }

        if ($analysis->lowTradeWarning) {
            $warnings[] = 'Low trade count limits statistical confidence.';
        }

        if (! empty($analysis->boundaryWarnings)) {
            $warnings[] = count($analysis->boundaryWarnings).' parameter(s) cluster near search boundaries — consider expanding ranges.';
        }

        if ($analysis->oosIsRatioWarning) {
            $warnings[] = 'OOS/IS ratio is inflated by near-zero scores — not meaningful.';
        }

        foreach ($positives as $p) {
            $this->line("  <fg=green>✓</> {$p}");
        }

        if (! empty($positives) && ! empty($warnings)) {
            $this->newLine();
        }

        foreach ($warnings as $w) {
            $this->line("  <fg=yellow>⚠ {$w}</>");
        }

        $this->newLine();
        $this->line('  <fg=yellow>Recommendation:</>');

        if ($analysis->economicPerformance === 'poor' && $stabilityOk) {
            if ($analysis->suspiciousSharpe) {
                $this->line('  The strategy spends most of the evaluation period out of the market.');
                $this->line('  The primary issue appears to be insufficient participation rather than');
                $this->line('  poor trade quality. Consider lowering the entry threshold or relaxing');
                $this->line('  filters that prevent capital deployment.');
            } elseif ($analysis->benchmarkHasData && $analysis->timeInMarket > 0) {
                $stRet = $analysis->bestOosResult
                    ? (float) ($analysis->bestOosResult->oos_statistics['total_return_percent'] ?? 0)
                    : 0.0;
                $bhRet = $analysis->benchmarkReturn;
                $this->line('  The strategy exhibits reasonable parameter stability but poor capital');
                $this->line('  efficiency. It spends '.number_format($analysis->timeInMarket, 2).'% of the time');
                $this->line('  invested yet captures only '.number_format($stRet, 2).'% return during a');
                $this->line('  period where buy-and-hold returned '.number_format($bhRet, 2).'%.');
            } else {
                $this->line('  Do not deploy in current form. The strategy captures too little');
                $this->line('  of the available market return to justify capital allocation.');
            }
        } elseif ($analysis->economicPerformance === 'strong' && $stabilityOk) {
            $this->line('  Strategy shows promise. Proceed to paper trading / live testing');
            if ($analysis->lowTradeWarning) {
                $this->line('  with cautious position sizing. Collect more trade data to');
                $this->line('  increase statistical confidence.');
            } else {
                $this->line('  with calibrated position sizing.');
            }
        } elseif (! $stabilityOk) {
            $this->line('  Optimization shows signs of overfitting. Expand parameter range,');
            $this->line('  simplify the parameter space, or increase iterations before');
            $this->line('  proceeding to live testing.');
        } else {
            $this->line('  Review results carefully. Mixed signals warrant additional');
            $this->line('  analysis before committing capital.');
        }

        $this->line(str_repeat('=', 60));

        $grade = StrategyGrader::grade($analysis);
        $this->newLine();
        $this->line('<fg=yellow>Final Score:</>');
        $this->line('  Overall:      '.self::colorizeStars($grade['stars']).' '.$grade['label']);
        $this->line('  ('.number_format($grade['score'], 1).'/100)');
        $this->line('  Economic:     '.self::colorizeStars($grade['stars_by_category']['economic']).' '.number_format($grade['breakdown']['economic'], 0).'%');
        $this->line('  Robustness:   '.self::colorizeStars($grade['stars_by_category']['robustness']).' '.number_format($grade['breakdown']['robustness'], 0).'%');
        $this->line('  Risk:         '.self::colorizeStars($grade['stars_by_category']['risk']).' '.number_format($grade['breakdown']['risk'], 0).'%');
        $this->line('  Optimization: '.self::colorizeStars($grade['stars_by_category']['optimization']).' '.number_format($grade['breakdown']['optimization'], 0).'%');
    }

    private static function colorizeStars(string $stars): string
    {
        $count = mb_substr_count($stars, '★');

        return match (true) {
            $count >= 4 => '<fg=green>'.$stars.'</>',
            $count >= 3 => '<fg=yellow>'.$stars.'</>',
            $count >= 2 => '<fg=#FF8800>'.$stars.'</>',
            default => '<fg=red>'.$stars.'</>',
        };
    }
}
