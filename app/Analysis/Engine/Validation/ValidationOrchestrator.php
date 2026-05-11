<?php

namespace App\Analysis\Engine\Validation;

use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Services\MarketDataFileService;
use App\Analysis\Config\ValidationConfig;
use App\Analysis\Dto\Validation\CalibrationReport;
use App\Analysis\Dto\Validation\CrossPeriodReport;
use App\Analysis\Dto\Validation\RandomizationReport;
use App\Analysis\Dto\Validation\RegimeReport;
use App\Analysis\Dto\Validation\SimulationReport;
use App\Analysis\Dto\Validation\StabilityReport;
use App\Analysis\Dto\Validation\TrainTestResult;
use App\Analysis\Dto\Validation\UncertaintyReport;
use App\Analysis\Dto\Validation\ValidationResult;
use App\Analysis\Engine\OpenCrossProbabilityEngine;
use App\Analysis\Exception\AnalysisException;

/**
 * Orchestrates all validation tests and aggregates results.
 */
final class ValidationOrchestrator
{
    private TrainTestSplitter $trainTestSplitter;

    private CalibrationTester $calibrationTester;

    private RollingStabilityTester $rollingStabilityTester;

    private RegimeSensitivityAnalyzer $regimeAnalyzer;

    private UncertaintyEstimator $uncertaintyEstimator;

    private RandomizedBaselineGenerator $randomizationGenerator;

    private CrossPeriodComparator $crossPeriodComparator;

    private StrategySimulator $strategySimulator;

    public function __construct(
        private readonly OpenCrossProbabilityEngine $engine,
        private readonly BinaryStorageInterface $binaryStorage,
        private readonly MarketDataFileService $fileService
    ) {
        $this->trainTestSplitter = new TrainTestSplitter($engine);
        $this->calibrationTester = new CalibrationTester;
        $this->rollingStabilityTester = new RollingStabilityTester($engine);
        $this->regimeAnalyzer = new RegimeSensitivityAnalyzer($engine);
        $this->uncertaintyEstimator = new UncertaintyEstimator;
        $this->randomizationGenerator = new RandomizedBaselineGenerator;
        $this->crossPeriodComparator = new CrossPeriodComparator($engine);
        $this->strategySimulator = new StrategySimulator;
    }

    /**
     * Run all validation tests.
     *
     * @param  ValidationConfig  $config  Validation configuration
     * @param  callable|null  $progressCallback  Optional progress callback
     *
     * @throws AnalysisException If validation fails
     */
    public function run(ValidationConfig $config, ?callable $progressCallback = null): ValidationResult
    {
        // Load all records
        $records = $this->loadRecords($config);

        $results = [];
        $totalTests = $this->countTests($config);
        $completedTests = 0;

        // Run train/test split
        if ($config->shouldRunTest(ValidationConfig::TEST_TRAIN_TEST)) {
            $results['train_test'] = $this->runTrainTest($records, $config, function ($current, $total) use (&$completedTests, $progressCallback, $totalTests) {
                $completedTests += $current / $total;
                if ($progressCallback !== null) {
                    $progressCallback((int) ($completedTests / $totalTests * 100), 100);
                }
            });
            $completedTests = (int) ceil($completedTests);
        }

        // Run calibration test
        if ($config->shouldRunTest(ValidationConfig::TEST_CALIBRATION)) {
            $results['calibration'] = $this->runCalibration($records, $config);
            $completedTests++;

            if ($progressCallback !== null) {
                $progressCallback((int) ($completedTests / $totalTests * 100), 100);
            }
        }

        // Run rolling stability test
        if ($config->shouldRunTest(ValidationConfig::TEST_STABILITY)) {
            $results['stability'] = $this->runRollingStability($records, $config, function ($current, $total) use (&$completedTests, $progressCallback, $totalTests) {
                if ($progressCallback !== null) {
                    $progressCallback((int) (($completedTests + $current / $total) / $totalTests * 100), 100);
                }
            });
            $completedTests++;
        }

        // Run regime sensitivity analysis
        if ($config->shouldRunTest(ValidationConfig::TEST_REGIME)) {
            $results['regime'] = $this->runRegimeSensitivity($records, $config, function ($current, $total) use (&$completedTests, $progressCallback, $totalTests) {
                if ($progressCallback !== null) {
                    $progressCallback((int) (($completedTests + $current / $total) / $totalTests * 100), 100);
                }
            });
            $completedTests++;
        }

        // Run uncertainty estimation
        if ($config->shouldRunTest(ValidationConfig::TEST_UNCERTAINTY)) {
            $results['uncertainty'] = $this->runUncertaintyEstimation($records, $config);
            $completedTests++;

            if ($progressCallback !== null) {
                $progressCallback((int) ($completedTests / $totalTests * 100), 100);
            }
        }

        // Run randomized baseline test
        if ($config->shouldRunTest(ValidationConfig::TEST_BASELINE)) {
            $results['randomization'] = $this->runRandomizedBaseline($records, $config, function ($current, $total) use (&$completedTests, $progressCallback, $totalTests) {
                if ($progressCallback !== null) {
                    $progressCallback((int) (($completedTests + $current / $total) / $totalTests * 100), 100);
                }
            });
            $completedTests++;
        }

        // Run cross-period comparison
        if ($config->shouldRunTest(ValidationConfig::TEST_CROSS_PERIOD)) {
            $results['cross_period'] = $this->runCrossPeriod($records, $config, function ($current, $total) use (&$completedTests, $progressCallback, $totalTests) {
                if ($progressCallback !== null) {
                    $progressCallback((int) (($completedTests + $current / $total) / $totalTests * 100), 100);
                }
            });
            $completedTests++;
        }

        // Run strategy simulation
        if ($config->shouldRunTest(ValidationConfig::TEST_SIMULATION)) {
            $results['simulation'] = $this->runStrategySimulation($records, $config);
            $completedTests++;

            if ($progressCallback !== null) {
                $progressCallback((int) ($completedTests / $totalTests * 100), 100);
            }
        }

        // Final progress callback
        if ($progressCallback !== null) {
            $progressCallback(100, 100);
        }

        return ValidationResult::fromResults($config, $results);
    }

    /**
     * Load all records from the data file.
     *
     * @param  ValidationConfig  $config  Configuration
     * @return array<int, array{timestamp: int, open: float, high: float, low: float, close: float, volume: float}>
     *
     * @throws AnalysisException If file not found
     */
    private function loadRecords(ValidationConfig $config): array
    {
        $sourcePath = $this->fileService->generateFilePath(
            $config->exchange,
            $config->market,
            $config->timeframe,
            'ohlcv'
        );

        if (! file_exists($sourcePath)) {
            throw AnalysisException::fileNotFound($sourcePath);
        }

        $records = iterator_to_array(
            $this->binaryStorage->readRecordsSequentially($sourcePath)
        );

        // Apply date filtering if configured
        if ($config->trainStartTimestamp !== null || $config->testEndTimestamp !== null) {
            $minTimestamp = $config->trainStartTimestamp ?? $config->testStartTimestamp ?? 0;
            $maxTimestamp = $config->testEndTimestamp ?? $config->trainEndTimestamp ?? PHP_INT_MAX;

            $records = array_filter($records, function ($record) use ($minTimestamp, $maxTimestamp) {
                return $record['timestamp'] >= $minTimestamp && $record['timestamp'] <= $maxTimestamp;
            });
            $records = array_values($records);
        }

        return $records;
    }

    /**
     * Count the number of tests to run.
     *
     * @param  ValidationConfig  $config  Configuration
     */
    private function countTests(ValidationConfig $config): int
    {
        if (in_array(ValidationConfig::TEST_ALL, $config->testsToRun, true)) {
            return 8;
        }

        return count($config->testsToRun);
    }

    /**
     * Run train/test split validation.
     *
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float, volume: float}>  $records  All records
     * @param  ValidationConfig  $config  Configuration
     * @param  callable|null  $progressCallback  Progress callback
     */
    private function runTrainTest(
        array $records,
        ValidationConfig $config,
        ?callable $progressCallback
    ): ?TrainTestResult {
        if (! $config->hasTrainTestSplit()) {
            return null;
        }

        return $this->trainTestSplitter->splitAndBuild($records, $config, $progressCallback);
    }

    /**
     * Run calibration test.
     *
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float, volume: float}>  $records  All records
     * @param  ValidationConfig  $config  Configuration
     */
    private function runCalibration(array $records, ValidationConfig $config): ?CalibrationReport
    {
        if (! $config->hasTrainTestSplit()) {
            return null;
        }

        // Build surface on training data
        $trainConfig = $config->toAnalysisConfig(
            $config->trainStartTimestamp,
            $config->trainEndTimestamp
        );

        $trainSurface = $this->engine->analyze($trainConfig);

        // Get test records
        $testRecords = array_filter($records, function ($record) use ($config) {
            return $record['timestamp'] >= $config->testStartTimestamp
                && $record['timestamp'] <= $config->testEndTimestamp;
        });

        return $this->calibrationTester->test($trainSurface, array_values($testRecords), $config);
    }

    /**
     * Run rolling stability test.
     *
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float, volume: float}>  $records  All records
     * @param  ValidationConfig  $config  Configuration
     * @param  callable|null  $progressCallback  Progress callback
     */
    private function runRollingStability(
        array $records,
        ValidationConfig $config,
        ?callable $progressCallback
    ): ?StabilityReport {
        try {
            return $this->rollingStabilityTester->test($records, $config, $progressCallback);
        } catch (AnalysisException $e) {
            return null;
        }
    }

    /**
     * Run regime sensitivity analysis.
     *
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float, volume: float}>  $records  All records
     * @param  ValidationConfig  $config  Configuration
     * @param  callable|null  $progressCallback  Progress callback
     */
    private function runRegimeSensitivity(
        array $records,
        ValidationConfig $config,
        ?callable $progressCallback
    ): ?RegimeReport {
        try {
            return $this->regimeAnalyzer->analyze($records, $config, $progressCallback);
        } catch (AnalysisException $e) {
            return null;
        }
    }

    /**
     * Run uncertainty estimation.
     *
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float, volume: float}>  $records  All records
     * @param  ValidationConfig  $config  Configuration
     */
    private function runUncertaintyEstimation(array $records, ValidationConfig $config): UncertaintyReport
    {
        // Build surface on all data
        $analysisConfig = $config->toAnalysisConfig();
        $surface = $this->engine->analyze($analysisConfig);

        return $this->uncertaintyEstimator->estimate($surface, $config);
    }

    /**
     * Run randomized baseline test.
     *
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float, volume: float}>  $records  All records
     * @param  ValidationConfig  $config  Configuration
     * @param  callable|null  $progressCallback  Progress callback
     */
    private function runRandomizedBaseline(
        array $records,
        ValidationConfig $config,
        ?callable $progressCallback
    ): RandomizationReport {
        // Build surface on all data
        $analysisConfig = $config->toAnalysisConfig();
        $surface = $this->engine->analyze($analysisConfig);

        return $this->randomizationGenerator->generate($surface, $records, $config, $progressCallback);
    }

    /**
     * Run cross-period comparison.
     *
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float, volume: float}>  $records  All records
     * @param  ValidationConfig  $config  Configuration
     * @param  callable|null  $progressCallback  Progress callback
     */
    private function runCrossPeriod(
        array $records,
        ValidationConfig $config,
        ?callable $progressCallback
    ): ?CrossPeriodReport {
        try {
            return $this->crossPeriodComparator->compare($records, $config, $progressCallback);
        } catch (AnalysisException $e) {
            return null;
        }
    }

    /**
     * Run strategy simulation.
     *
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float, volume: float}>  $records  All records
     * @param  ValidationConfig  $config  Configuration
     */
    private function runStrategySimulation(array $records, ValidationConfig $config): ?SimulationReport
    {
        if (! $config->hasTrainTestSplit()) {
            return null;
        }

        // Build surface on training data
        $trainConfig = $config->toAnalysisConfig(
            $config->trainStartTimestamp,
            $config->trainEndTimestamp
        );

        $trainSurface = $this->engine->analyze($trainConfig);

        // Get test records
        $testRecords = array_filter($records, function ($record) use ($config) {
            return $record['timestamp'] >= $config->testStartTimestamp
                && $record['timestamp'] <= $config->testEndTimestamp;
        });

        return $this->strategySimulator->simulate($trainSurface, array_values($testRecords), $config);
    }
}
