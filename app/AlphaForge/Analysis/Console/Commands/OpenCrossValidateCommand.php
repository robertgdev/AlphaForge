<?php

namespace App\AlphaForge\Analysis\Console\Commands;

use App\AlphaForge\Analysis\Config\ValidationConfig;
use App\AlphaForge\Analysis\Dto\Validation\ValidationResult;
use App\AlphaForge\Analysis\Engine\Validation\ValidationOrchestrator;
use App\AlphaForge\Analysis\Exception\AnalysisException;
use App\AlphaForge\Analysis\Renderer\ValidationRenderer;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Safe\file_put_contents;
use function Safe\mkdir;

/**
 * Artisan command for running statistical validation on Open-Cross Probability analysis.
 */
final class OpenCrossValidateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alphaforge:analysis:opencross-validate
        {exchange : The exchange identifier (e.g., binance, kraken)}
        {market : The trading pair symbol (e.g., BTC/USDT)}
        {timeframe : The source timeframe (must be 1m)}

        {--train-start= : Training period start date (Y-m-d)}
        {--train-end= : Training period end date (Y-m-d)}
        {--test-start= : Test period start date (Y-m-d)}
        {--test-end= : Test period end date (Y-m-d)}

        {--block=15 : Block duration in minutes}
        {--bucket=0.001 : Distance bucket size}
        {--min-samples=100 : Minimum samples for high confidence}

        {--volatility-normalized : Use volatility normalization}
        {--volatility-lookback=20 : Volatility lookback period}
        {--symmetric : Merge symmetric buckets}

        {--rolling-window=6 : Rolling window size in months}
        {--rolling-step=1 : Rolling step size in months}

        {--calibration-bin=0.05 : Calibration bin width}
        {--regime-classifier=volatility_percentile : Regime classification method}
        {--regime-threshold=0.7 : Regime classification threshold}

        {--random-iterations=10 : Randomization iterations}
        {--simulation-threshold=0.7 : Strategy simulation threshold}

        {--tests=all : Comma-separated list of tests to run}
        {--output=json : Output format (json, csv, markdown, all)}
        {--save= : Path to save results}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run statistical validation tests on Open-Cross Probability analysis';

    /**
     * @var ProgressBar|null
     */
    protected $progressBar = null;

    /**
     * Execute the console command.
     */
    public function handle(
        ValidationOrchestrator $orchestrator,
        ValidationRenderer $renderer
    ): int {
        $exchange = strtolower($this->argument('exchange'));
        $market = strtoupper($this->argument('market'));
        $timeframe = $this->argument('timeframe');

        try {
            $config = ValidationConfig::fromArray([
                'exchange' => $exchange,
                'market' => $market,
                'timeframe' => $timeframe,
                'train_start' => $this->option('train-start') ?: null,
                'train_end' => $this->option('train-end') ?: null,
                'test_start' => $this->option('test-start') ?: null,
                'test_end' => $this->option('test-end') ?: null,
                'block_minutes' => (int) $this->option('block'),
                'bucket_size' => (float) $this->option('bucket'),
                'minimum_samples' => (int) $this->option('min-samples'),
                'merge_symmetric' => (bool) $this->option('symmetric'),
                'volatility_normalized' => (bool) $this->option('volatility-normalized'),
                'volatility_lookback' => (int) $this->option('volatility-lookback'),
                'rolling_window_months' => (int) $this->option('rolling-window'),
                'rolling_step_months' => (int) $this->option('rolling-step'),
                'calibration_bin_width' => (float) $this->option('calibration-bin'),
                'regime_classifier' => $this->option('regime-classifier'),
                'regime_threshold' => (float) $this->option('regime-threshold'),
                'randomization_iterations' => (int) $this->option('random-iterations'),
                'simulation_threshold' => (float) $this->option('simulation-threshold'),
                'tests' => $this->option('tests'),
                'output_format' => $this->option('output'),
            ]);
        } catch (AnalysisException $e) {
            error("Configuration error: {$e->getMessage()}");

            return self::FAILURE;
        }

        // Display configuration
        $this->displayConfiguration($config);

        // Run validation
        try {
            $verbose = $this->output->isVerbose();

            if ($verbose) {
                $this->progressBar = $this->output->createProgressBar(100);
                $this->progressBar->setFormat(' %current:3s%%/%max:3s%% [%bar%] %message%');
                $this->progressBar->setMessage('Running validation tests...');
                $this->progressBar->start();
            }

            $result = $orchestrator->run($config, function (int $current, int $total) use ($verbose) {
                if ($verbose && $this->progressBar !== null) {
                    $this->progressBar->setProgress($current);
                    $this->progressBar->setMessage("Processing... {$current}%");
                }
            });

            if ($this->progressBar !== null) {
                $this->progressBar->finish();
                $this->newLine(2);
            }
        } catch (AnalysisException $e) {
            $this->finishProgressBarOnError();
            error("Validation error: {$e->getMessage()}");

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

        return $result->isPassed() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Display the validation configuration.
     */
    private function displayConfiguration(ValidationConfig $config): void
    {
        info('Statistical Validation for Open-Cross Probability Analysis');
        $this->newLine();

        $this->components->twoColumnDetail('Exchange', $config->exchange);
        $this->components->twoColumnDetail('Market', $config->market);
        $this->components->twoColumnDetail('Timeframe', $config->timeframe);
        $this->components->twoColumnDetail('Block Duration', "{$config->blockMinutes} minutes");
        $this->components->twoColumnDetail('Bucket Size', sprintf('%.4f (%.2f%%)', $config->bucketSize, $config->bucketSize * 100));
        $this->components->twoColumnDetail('Min Samples', (string) $config->minimumSamples);

        if ($config->hasTrainTestSplit()) {
            $this->newLine();
            $this->components->twoColumnDetail('Train Period', $config->getTrainDateRangeString() ?? 'N/A');
            $this->components->twoColumnDetail('Test Period', $config->getTestDateRangeString() ?? 'N/A');
        }

        $this->newLine();
        $this->components->twoColumnDetail('Tests to Run', implode(', ', $config->testsToRun));
        $this->components->twoColumnDetail('Output Format', $config->outputFormat);

        $this->newLine();
    }

    /**
     * Display the validation results.
     */
    private function displayResults(
        ValidationResult $result,
        ValidationRenderer $renderer
    ): void {
        $outputFormat = $this->option('output');

        switch ($outputFormat) {
            case 'json':
                $this->line($renderer->toJson($result));
                break;

            case 'csv':
                $this->line($renderer->toCsv($result));
                break;

            case 'markdown':
                $this->line($renderer->toMarkdown($result));
                break;

            case 'all':
            default:
                // Show summary first
                $this->output->writeln($renderer->renderSummary($result, 80));
                $this->newLine();
                break;
        }
    }

    /**
     * Save results to a file.
     */
    private function saveResults(
        ValidationResult $result,
        ValidationRenderer $renderer,
        string $path
    ): void {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        try {
            $content = match ($extension) {
                'json' => $renderer->toJson($result),
                'csv' => $renderer->toCsv($result),
                'md' => $renderer->toMarkdown($result),
                default => $renderer->toJson($result),
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
