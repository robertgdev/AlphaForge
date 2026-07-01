<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Model\WalkForwardResult;
use App\AlphaForge\Backtesting\Model\WalkForwardRun;
use App\AlphaForge\Backtesting\Service\BacktestResultFormatter;
use App\AlphaForge\Backtesting\WalkForward\StrategyGrader;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardAnalysis;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardAnalyzer;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardExporter;
use App\AlphaForge\Console\Concerns\HasJsonOutput;
use Illuminate\Console\Command;

use function Safe\file_put_contents;

class ShowWalkForwardRunCommand extends Command
{
    use HasJsonOutput;

    protected $signature = 'alphaforge:walk-forward:show
        {run_id : The walk-forward run ID}
        {--top=20 : Number of top results to display}
        {--format=table : Output format (table, csv, json)}
        {--output= : Write output to file instead of stdout}
        {--json : Output results as JSON}
        {--schema : Display command parameter schema as JSON}
        {--debug : Show peak memory usage on exit}';

    protected $description = 'Show detailed walk-forward run results';

    public function handle(WalkForwardAnalyzer $analyzer, BacktestResultFormatter $formatter, WalkForwardExporter $exporter): int
    {
        if (($code = $this->handleSchemaFlag()) !== null) {
            return $code;
        }

        $runId = $this->argument('run_id');
        $topCount = (int) $this->option('top');
        $format = $this->option('format');
        $outputPath = $this->option('output');

        if (! in_array($format, ['table', 'csv', 'json'])) {
            return $this->outputJsonError("Invalid format: $format. Use: table, csv, json");
        }

        if ($this->jsonEnabled() && $this->input->hasParameterOption('--format')) {
            return $this->outputJsonError('Cannot use --json and --format together. Use one or the other.');
        }

        $wfRun = WalkForwardRun::find($runId);

        if (! $wfRun) {
            return $this->outputJsonError("Walk-forward run not found: $runId");
        }

        $analysis = $analyzer->analyze($wfRun);

        $useJson = $this->jsonEnabled();
        if ($useJson) {
            return $this->outputJson(true, $this->buildWalkForwardJson($wfRun, $topCount), outputPath: $outputPath);
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

        $this->displayRunMetadata($wfRun);
        $this->displaySummary($analysis);
        $this->displayTopResults($analysis, $topCount);

        if ($analysis->bestOosResult) {
            $this->displayBestOosDetail($analysis, $formatter);
        }

        $this->debugMemory();

        return 0;
    }

    private function buildWalkForwardJson(WalkForwardRun $wfRun, int $topCount): array
    {
        $grade = StrategyGrader::grade($wfRun);
        $analyzer = app(WalkForwardAnalyzer::class);
        $analysis = $analyzer->analyze($wfRun);

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
            ], array_slice($analysis->results, 0, $topCount)),
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

    private function displayRunMetadata(WalkForwardRun $run): void
    {
        $this->line('<fg=yellow>Walk-Forward Run Details</>');
        $this->line("  ID: {$run->id}");
        $this->line("  Strategy: {$run->strategy_alias}");
        $this->line('  Symbol: '.($run->symbols[0] ?? '-'));
        $this->line("  Timeframe: {$run->timeframe}");

        if ($run->execution_timeframe) {
            $this->line("  Execution Timeframe: {$run->execution_timeframe}");
        }

        $this->line("  Method: {$run->optimization_method}");
        $this->line("  Objective: {$run->optimization_objective}");

        if ($run->is_start_date) {
            $this->line('  IS Period: '.$run->is_start_date->format('Y-m-d').' → '.$run->is_end_date?->format('Y-m-d'));
        }

        if ($run->oos_start_date) {
            $this->line('  OOS Period: '.$run->oos_start_date->format('Y-m-d').' → '.$run->oos_end_date?->format('Y-m-d'));
        }

        $this->line('  Split Ratio: '.number_format($run->split_ratio * 100, 0).'% IS / '.number_format((1 - $run->split_ratio) * 100, 0).'% OOS');
        $this->line("  Status: {$run->status}");

        if ($run->min_trades_threshold) {
            $this->line("  Min Trades Threshold: {$run->min_trades_threshold}");
        }

        $this->newLine();
    }

    private function displaySummary(WalkForwardAnalysis $analysis): void
    {
        $this->line('<fg=yellow>Summary</>');
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

        $medianText = $analysis->medianDegradation < 0
            ? '↑ +'.number_format(abs($analysis->medianDegradation), 1).'%'
            : '↓ -'.number_format($analysis->medianDegradation, 1).'%';
        $this->line('  Median score degradation: '.$medianText);

        $avgText = $analysis->avgDegradation < 0
            ? '↑ +'.number_format(abs($analysis->avgDegradation), 1).'%'
            : '↓ -'.number_format($analysis->avgDegradation, 1).'%';
        $this->line('  Average score degradation: '.$avgText);

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

        if ($analysis->benchmarkHasData) {
            $this->newLine();
            $this->line('  <fg=yellow>Buy & Hold Benchmark (OOS Period)</>');
            $this->line('  '.str_repeat('─', 60));
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

        $this->newLine();

        $grade = StrategyGrader::grade($analysis);
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

    private function displayTopResults(WalkForwardAnalysis $analysis, int $topCount): void
    {
        $this->line("<fg=yellow>Top {$topCount} Results:</>");

        $slice = array_slice($analysis->results, 0, $topCount);

        if (empty($slice)) {
            $this->line('  No results available.');

            return;
        }

        $rows = [];
        foreach ($slice as $result) {
            $params = collect($result->parameters)
                ->map(fn ($v, $k) => "$k=$v")
                ->implode(', ');

            if (strlen($params) > 30) {
                $params = substr($params, 0, 27).'...';
            }

            $isReturn = number_format((float) ($result->is_statistics['total_return_percent'] ?? 0), 2).'%';
            $oosReturn = number_format((float) ($result->oos_statistics['total_return_percent'] ?? 0), 2).'%';
            $isDd = number_format((float) ($result->is_statistics['max_drawdown_percent'] ?? 0) * 100, 2).'%';
            $oosDd = number_format((float) ($result->oos_statistics['max_drawdown_percent'] ?? 0) * 100, 2).'%';

            $rows[] = [
                $result->rank,
                $params,
                number_format($result->is_score ?? 0, 2),
                number_format($result->oos_score ?? 0, 2),
                $this->formatDegradation($result->score_degradation ?? 0),
                $isReturn,
                $oosReturn,
                $isDd,
                $oosDd,
            ];
        }

        $this->table(
            ['Rank', 'Parameters', 'IS Score', 'OOS Score', 'Degradation', 'IS Return', 'OOS Return', 'IS MaxDD', 'OOS MaxDD'],
            $rows
        );

        $this->newLine();
    }

    private function displayBestOosDetail(WalkForwardAnalysis $analysis, BacktestResultFormatter $formatter): void
    {
        $this->line('<fg=yellow>Best OOS Result Detail:</>');
        $this->line('  Rank: '.$analysis->bestOosRank);

        if ($analysis->bestOosResult->parameters) {
            $this->line('  Parameters:');
            foreach ($analysis->bestOosResult->parameters as $param => $value) {
                $this->line("    - {$param}: {$value}");
            }
        }

        if ($analysis->bestOosResult->is_statistics) {
            $this->line('  IS Statistics:');
            $formattedIs = $formatter->formatStatistics($analysis->bestOosResult->is_statistics);
            foreach ($formattedIs as $label => $value) {
                $this->line("    - {$label}: {$value}");
            }
        }

        if ($analysis->bestOosResult->oos_statistics) {
            $this->line('  OOS Statistics:');
            $formattedOos = $formatter->formatStatistics($analysis->bestOosResult->oos_statistics);
            foreach ($formattedOos as $label => $value) {
                $this->line("    - {$label}: {$value}");
            }
        }

        $this->newLine();
    }

    private function formatDegradation(float $degradation): string
    {
        if ($degradation < 0) {
            return '↑ +'.number_format(abs($degradation), 1).'%';
        }

        return '↓ -'.number_format($degradation, 1).'%';
    }
}
