<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Dto\WalkForwardConfiguration;
use App\AlphaForge\Backtesting\Optimization\OptimizationMethod;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardAnalysis;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardAnalyzer;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardExporter;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardService;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Strategy\Service\StrategyInputParser;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Safe\DateTimeImmutable;

use function Safe\file_put_contents;

class WalkForwardCommand extends Command
{
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
        {--output= : Write output to file instead of stdout}';

    protected $description = 'Run walk-forward analysis: optimize on in-sample data, validate on out-of-sample data';

    public function handle(WalkForwardService $service, WalkForwardAnalyzer $analyzer, WalkForwardExporter $exporter, StrategyInputParser $inputParser): int
    {
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

        $timeframe = TimeframeEnum::tryFrom($timeframeValue);
        if (! $timeframe) {
            $this->error("Invalid timeframe: $timeframeValue");

            return 1;
        }

        $executionTimeframe = null;
        if ($executionTimeframeValue) {
            $executionTimeframe = TimeframeEnum::tryFrom($executionTimeframeValue);
            if (! $executionTimeframe) {
                $this->error("Invalid execution timeframe: $executionTimeframeValue. Valid values: 1m, 5m, 15m, 30m, 1h, 4h, 1d, 1w, 1M");

                return 1;
            }

            if ($executionTimeframe->toSeconds() >= $timeframe->toSeconds()) {
                $this->error("Execution timeframe ({$executionTimeframe->value}) must be lower (finer granularity) than the signal timeframe ({$timeframe->value}).");

                return 1;
            }
        }

        $method = OptimizationMethod::tryFrom($methodValue);
        if (! $method) {
            $this->error("Invalid method: $methodValue. Use: grid, random, genetic");

            return 1;
        }

        if ($splitRatio <= 0 || $splitRatio >= 1) {
            $this->error('--split must be between 0 and 1 (exclusive)');

            return 1;
        }

        if (! in_array($format, ['table', 'csv', 'json'])) {
            $this->error("Invalid format: $format. Use: table, csv, json");

            return 1;
        }

        $validDataTypes = ['ohlcv', 'heikenashi', 'renko', 'atr_renko'];
        if (! in_array($dataTypeValue, $validDataTypes, true)) {
            $this->error("Invalid data-type '{$dataTypeValue}'. Valid values: ".implode(', ', $validDataTypes));

            return 1;
        }

        if ($dataTypeValue === 'renko') {
            if ($brickSize === null || ! is_numeric($brickSize) || (float) $brickSize <= 0) {
                $this->error('data-type=renko requires --brick-size with a positive numeric value (e.g., 0.001, 10, 100).');

                return 1;
            }
        } elseif ($dataTypeValue === 'atr_renko') {
            if ($atrPeriod === null || ! is_numeric($atrPeriod) || (int) $atrPeriod <= 0) {
                $this->error('data-type=atr_renko requires --atr-period with a positive integer value (e.g., 14).');

                return 1;
            }
        } else {
            if ($brickSize !== null) {
                $this->warn('--brick-size is ignored for data-type '.$dataTypeValue);
            }
            if ($atrPeriod !== null) {
                $this->warn('--atr-period is ignored for data-type '.$dataTypeValue);
            }
        }

        $startDate = $startDateOption ? Carbon::parse($startDateOption) : null;
        $endDate = $endDateOption ? Carbon::parse($endDateOption) : null;

        $parameterOverrides = null;

        if ($useStrategyRanges) {
            $this->info('Using strategy-defined parameter ranges.');
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

        $this->info('Starting Walk-Forward Analysis...');
        $this->newLine();
        $this->line("  Strategy: $strategyAlias");
        $this->line("  Symbol: $symbol");
        $this->line("  Timeframe: {$timeframe->value}");

        if ($executionTimeframe !== null) {
            $this->line("  Execution Timeframe: {$executionTimeframe->value}");
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

        $this->newLine();

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
        $config->dataType = $dataTypeValue;
        $config->brickSize = $dataTypeValue === 'renko' ? (float) $brickSize : null;
        $config->atrPeriod = $dataTypeValue === 'atr_renko' ? (int) $atrPeriod : null;
        $config->dataType = $dataTypeValue;
        $config->brickSize = $dataTypeValue === 'renko' ? (float) $brickSize : null;
        $config->atrPeriod = $dataTypeValue === 'atr_renko' ? (int) $atrPeriod : null;

        try {
            [$isStart, $isEnd, $oosStart, $oosEnd] = $service->computeDateSplit($config);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        if (! $force && $minOosDays > 0) {
            $oosDays = $oosStart->diffInDays($oosEnd);
            if ($oosDays < $minOosDays) {
                $this->warn("OOS period is only {$oosDays} days — results may not be statistically meaningful.");
                $this->line('  Consider using a longer date range or a lower split ratio.');
            }
        }

        $this->info('Phase 1: Optimization (In-Sample)');
        $this->line('  Period: '.$isStart->toDateString().' → '.$isEnd->toDateString());
        $this->newLine();

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
            $this->error('Walk-forward analysis failed: '.$e->getMessage());

            return 1;
        } finally {
            $bar?->finish();
            if ($bar !== null) {
                $this->newLine();
            }
        }

        if ($wfRun->hasFailed()) {
            $this->error('Walk-forward analysis failed: '.($wfRun->error_message ?? 'Unknown error'));

            return 1;
        }

        $this->newLine();
        $this->info('Phase 2: Forward Validation (Out-of-Sample)');
        $this->line('  Period: '.$oosStart->toDateString().' → '.$oosEnd->toDateString());
        $this->line('  Tested top '.$topN.' parameter sets');
        $this->newLine();

        $analysis = $analyzer->analyze($wfRun, $minTrades);

        if ($format === 'csv') {
            $csv = $exporter->toCsv($analysis);
            $this->outputResult($csv, $outputPath);

            return 0;
        }

        if ($format === 'json') {
            $json = $exporter->toJson($analysis);
            $this->outputResult($json, $outputPath);

            return 0;
        }

        $this->displayResultsTable($analysis);

        $this->displaySummary($analysis);

        $this->newLine();
        $this->line("  Walk-Forward Run ID: {$wfRun->id}");
        if ($wfRun->optimization_run_id) {
            $this->line("  Optimization Run ID: {$wfRun->optimization_run_id}");
        }

        return 0;
    }

    private function outputResult(string $content, ?string $outputPath): void
    {
        if ($outputPath) {
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
                number_format($result->score_degradation ?? 0, 1).'%',
            ];
        }

        $this->table($headers, $rows);

        $totalResults = count($analysis->results);
        if ($totalResults > 20) {
            $this->line('  ... and '.($totalResults - 20).' more results (use Walk-Forward Run ID to query all)');
        }
    }

    private function displaySummary(WalkForwardAnalysis $analysis): void
    {
        $this->newLine();
        $this->info('Walk-Forward Summary');
        $this->line(str_repeat('─', 40));

        $classification = strtoupper($analysis->classification);
        $this->line("  Classification: {$classification} — {$analysis->interpretation}");

        $this->line('  Walk-Forward Efficiency: '.number_format($analysis->walkForwardEfficiency, 1).'%');
        $this->line('  Robust parameters (profitable OOS): '.$analysis->robustCount.'/'.count($analysis->results).' ('.number_format($analysis->robustRatio * 100, 1).'%)');

        if ($analysis->reliableCount > 0 || $analysis->minTrades > 0) {
            $this->line("  Statistically reliable (≥{$analysis->minTrades} trades, profitable OOS): {$analysis->reliableCount}/".count($analysis->results).' ('.number_format($analysis->reliableRatio * 100, 1).'%)');
        }

        $this->line('  Average score degradation: '.number_format($analysis->avgDegradation, 1).'%');
        $this->line('  Median score degradation: '.number_format($analysis->medianDegradation, 1).'%');

        if ($analysis->rankCorrelation !== null) {
            $this->line('  IS-OOS Rank Correlation (Spearman): '.number_format($analysis->rankCorrelation, 3).' ('.$analysis->rankStabilityLabel.')');
        }

        if ($analysis->bestOosResult) {
            $bestParams = collect($analysis->bestOosResult->parameters)
                ->map(fn ($v, $k) => "$k=$v")
                ->implode(', ');

            $this->line('  Best OOS: Rank '.$analysis->bestOosRank.' ('.$bestParams.')');
        }
    }
}
