<?php

namespace App\Analysis\Console\Commands;

use App\Analysis\Config\OpenCrossAnalysisConfig;
use App\Analysis\Dto\OpenCrossProbabilityResult;
use App\Analysis\Engine\OpenCrossProbabilityEngine;
use App\Analysis\Exception\AnalysisException;
use App\Analysis\Renderer\ProbabilitySurfaceRenderer;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

/**
 * Artisan command for running Open-Cross Probability analysis.
 */
final class OpenCrossProbabilityCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stoch:analysis:opencross
        {exchange : The exchange identifier (e.g., binance, kraken)}
        {market : The trading pair symbol (e.g., BTC/USDT)}
        {timeframe : The source timeframe (must be 1m)}
        {--block=15 : Block duration in minutes (e.g., 5, 15, 60)}
        {--bucket=0.001 : Distance bucket size as decimal (e.g., 0.001 = 0.1%)}
        {--min-samples=100 : Minimum samples required for high confidence}
        {--use-close : Use close price instead of current price for distance}
        {--symmetric : Merge positive/negative distance buckets by absolute value}
        {--volatility-normalized : Normalize distance by rolling volatility}
        {--volatility-lookback=60 : Lookback period for volatility calculation (minimum 30 recommended)}
        {--startdate= : Start date for analysis (Y-m-d or Y-m-d H:i:s)}
        {--enddate= : End date for analysis (Y-m-d or Y-m-d H:i:s)}
        {--trim-zeros : Automatically trim trailing zero-probability buckets from display}
        {--max-distance= : Limit display to ±N buckets from zero (e.g., 5)}
        {--output=table : Output format (table, json, csv, html, heatmap, summary)}
        {--save= : Optional path to save results}
        {--width=80 : Width for ASCII graph output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze Open-Cross probability for intraday price movements';

    /**
     * @var ProgressBar|null
     */
    protected $progressBar = null;

    /**
     * Execute the console command.
     */
    public function handle(
        OpenCrossProbabilityEngine $engine,
        ProbabilitySurfaceRenderer $renderer
    ): int {
        $exchange = strtolower($this->argument('exchange'));
        $market = strtoupper($this->argument('market'));
        $timeframe = $this->argument('timeframe');

        try {
            $config = OpenCrossAnalysisConfig::fromArray([
                'exchange' => $exchange,
                'market' => $market,
                'timeframe' => $timeframe,
                'block_minutes' => (int) $this->option('block'),
                'bucket_size' => (float) $this->option('bucket'),
                'use_close_price' => (bool) $this->option('use-close'),
                'minimum_samples' => (int) $this->option('min-samples'),
                'merge_symmetric' => (bool) $this->option('symmetric'),
                'volatility_normalized' => (bool) $this->option('volatility-normalized'),
                'volatility_lookback' => (int) $this->option('volatility-lookback'),
                'start_date' => $this->option('startdate') ?: null,
                'end_date' => $this->option('enddate') ?: null,
            ]);
        } catch (AnalysisException $e) {
            error("Configuration error: {$e->getMessage()}");

            return self::FAILURE;
        }

        // Display analysis configuration
        $this->displayConfiguration($config);

        // Get source file info
        try {
            $fileInfo = $engine->getSourceFileInfo($exchange, $market, $timeframe);
        } catch (AnalysisException $e) {
            error("Source file error: {$e->getMessage()}");
            $this->components->twoColumnDetail(
                'Expected Path',
                "marketdata/{$exchange}/".str_replace('/', '_', $market)."/{$timeframe}/ohlcv.stchx"
            );

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Source Records', number_format($fileInfo['numRecords']));
        $this->newLine();

        // Run analysis with progress
        try {
            $this->progressBar = $this->output->createProgressBar(100);
            $this->progressBar->setFormat(' %current:3s%%/%max:3s%% [%bar%] %message%');
            $this->progressBar->setMessage('Analyzing blocks...');
            $this->progressBar->start();

            $result = $engine->analyze($config, function (int $current, int $total) {
                $this->updateProgress($current, $total);
            });

            $this->progressBar->finish();
            $this->newLine(2);
        } catch (AnalysisException $e) {
            $this->finishProgressBarOnError();
            error("Analysis error: {$e->getMessage()}");

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->finishProgressBarOnError();
            error("Unexpected error: {$e->getMessage()}");

            return self::FAILURE;
        }

        // Display results
        $this->displayResults($result, $renderer);

        // Save results if requested
        $savePath = $this->option('save');
        if ($savePath !== null) {
            $this->saveResults($result, $renderer, $savePath);
        }

        return self::SUCCESS;
    }

    /**
     * Display the analysis configuration.
     */
    private function displayConfiguration(OpenCrossAnalysisConfig $config): void
    {
        info('Open-Cross Probability Analysis');
        $this->newLine();

        $this->components->twoColumnDetail('Exchange', $config->exchange);
        $this->components->twoColumnDetail('Market', $config->market);
        $this->components->twoColumnDetail('Timeframe', $config->timeframe);
        $this->components->twoColumnDetail('Block Duration', "{$config->blockMinutes} minutes");
        $this->components->twoColumnDetail('Bucket Size', sprintf('%.4f (%.2f%%)', $config->bucketSize, $config->bucketSize * 100));
        $this->components->twoColumnDetail('Min Samples', (string) $config->minimumSamples);
        $this->components->twoColumnDetail('Use Close Price', $config->useClosePrice ? 'Yes' : 'No');
        $this->components->twoColumnDetail('Symmetric Merge', $config->mergeSymmetric ? 'Yes' : 'No');
        $this->components->twoColumnDetail('Volatility Normalized', $config->volatilityNormalized ? "Yes (lookback: {$config->volatilityLookback})" : 'No');

        // Display date range if specified
        if ($config->hasDateFilter()) {
            $dateRange = $config->getDateRangeString();
            $this->components->twoColumnDetail('Date Range', $dateRange ?? 'All available data');
        }

        $this->newLine();
    }

    /**
     * Display the analysis results.
     */
    private function displayResults(
        OpenCrossProbabilityResult $result,
        ProbabilitySurfaceRenderer $renderer
    ): void {
        $outputFormat = $this->option('output');
        $width = (int) $this->option('width');
        $trimZeros = (bool) $this->option('trim-zeros');
        $maxDistance = $this->option('max-distance') !== null ? (int) $this->option('max-distance') : null;

        // Build render options
        $renderOptions = [
            'trim_zeros' => $trimZeros,
            'max_distance' => $maxDistance,
        ];

        switch ($outputFormat) {
            case 'json':
                $this->line($result->toJson());
                break;

            case 'csv':
                $this->line($result->toCsv());
                break;

            case 'html':
                $this->output->writeln($renderer->renderHtml($result, $renderOptions));
                break;

            case 'heatmap':
                $this->output->writeln($renderer->renderHeatmap($result, $width, $renderOptions));
                break;

            case 'summary':
                $this->output->writeln($renderer->renderSummary($result, $width, $renderOptions));
                break;

            case 'table':
            default:
                // Show summary first
                $this->output->writeln($renderer->renderSummary($result, $width, $renderOptions));
                $this->newLine();

                // Show heatmap
                $this->output->writeln($renderer->renderHeatmap($result, $width, $renderOptions));
                break;
        }
    }

    /**
     * Save results to a file.
     */
    private function saveResults(
        OpenCrossProbabilityResult $result,
        ProbabilitySurfaceRenderer $renderer,
        string $path
    ): void {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        try {
            $content = match ($extension) {
                'json' => $result->toJson(),
                'csv' => $result->toCsv(),
                'html' => $renderer->renderHtml($result, [
                    'trim_zeros' => (bool) $this->option('trim-zeros'),
                    'max_distance' => $this->option('max-distance') !== null ? (int) $this->option('max-distance') : null,
                ]),
                default => $result->toJson(),
            };

            $dir = dirname($path);
            if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new \RuntimeException("Could not create directory: {$dir}");
            }

            if (file_put_contents($path, $content) === false) {
                throw new \RuntimeException("Could not write to file: {$path}");
            }

            info("Results saved to: {$path}");
        } catch (\Throwable $e) {
            error("Failed to save results: {$e->getMessage()}");
        }
    }

    /**
     * Update the progress bar.
     */
    private function updateProgress(int $current, int $total): void
    {
        if ($this->progressBar === null) {
            return;
        }

        $percentComplete = $total > 0 ? (int) round(($current / $total) * 100) : 0;
        $percentComplete = max(0, min(100, $percentComplete));

        $this->progressBar->setProgress($percentComplete);
        $this->progressBar->setMessage("Processing block {$current}/{$total}");
    }

    /**
     * Finish the progress bar on error.
     */
    private function finishProgressBarOnError(): void
    {
        if ($this->progressBar !== null) {
            $this->progressBar->finish();
            $this->newLine(2);
        }
    }
}
