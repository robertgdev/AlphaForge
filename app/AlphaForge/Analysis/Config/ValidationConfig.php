<?php

namespace App\AlphaForge\Analysis\Config;

use App\AlphaForge\Analysis\Exception\AnalysisException;

/**
 * Immutable configuration for the Statistical Validation & Robustness testing.
 */
final readonly class ValidationConfig
{
    public const REGIME_VOLATILITY_PERCENTILE = 'volatility_percentile';

    public const REGIME_VOLATILITY_THRESHOLD = 'volatility_threshold';

    public const REGIME_ATR_BASED = 'atr_based';

    public const TEST_TRAIN_TEST = 'train-test';

    public const TEST_CALIBRATION = 'calibration';

    public const TEST_STABILITY = 'stability';

    public const TEST_REGIME = 'regime';

    public const TEST_UNCERTAINTY = 'uncertainty';

    public const TEST_BASELINE = 'baseline';

    public const TEST_CROSS_PERIOD = 'cross-period';

    public const TEST_SIMULATION = 'simulation';

    public const TEST_ALL = 'all';

    /**
     * @param  string  $exchange  The exchange identifier
     * @param  string  $market  The market symbol
     * @param  string  $timeframe  The source timeframe (must be '1m')
     * @param  int  $blockMinutes  Block duration in minutes
     * @param  float  $bucketSize  Distance bucket size
     * @param  bool  $useClosePrice  Use close price for distance calculation
     * @param  int  $minimumSamples  Minimum samples for high confidence
     * @param  bool  $mergeSymmetric  Merge positive/negative buckets by absolute value
     * @param  bool  $volatilityNormalized  Normalize distance by volatility
     * @param  int  $volatilityLookback  Lookback period for volatility calculation
     * @param  int|null  $trainStartTimestamp  Training period start timestamp
     * @param  int|null  $trainEndTimestamp  Training period end timestamp
     * @param  int|null  $testStartTimestamp  Test period start timestamp
     * @param  int|null  $testEndTimestamp  Test period end timestamp
     * @param  int|null  $validationStartTimestamp  Optional validation period start
     * @param  int|null  $validationEndTimestamp  Optional validation period end
     * @param  int  $rollingWindowMonths  Rolling window size in months
     * @param  int  $rollingStepMonths  Rolling step size in months
     * @param  float  $calibrationBinWidth  Width of probability bins for calibration
     * @param  string  $regimeClassifier  Regime classification method
     * @param  float  $regimeThreshold  Threshold for regime classification
     * @param  int  $randomizationIterations  Number of randomization iterations
     * @param  float  $simulationThreshold  Probability threshold for strategy simulation
     * @param  array  $testsToRun  List of tests to run
     * @param  string  $outputFormat  Output format (json, csv, markdown, all)
     */
    public function __construct(
        public string $exchange,
        public string $market,
        public string $timeframe,
        public int $blockMinutes,
        public float $bucketSize,
        public bool $useClosePrice = false,
        public int $minimumSamples = 100,
        public bool $mergeSymmetric = false,
        public bool $volatilityNormalized = false,
        public int $volatilityLookback = 20,
        public ?int $trainStartTimestamp = null,
        public ?int $trainEndTimestamp = null,
        public ?int $testStartTimestamp = null,
        public ?int $testEndTimestamp = null,
        public ?int $validationStartTimestamp = null,
        public ?int $validationEndTimestamp = null,
        public int $rollingWindowMonths = 6,
        public int $rollingStepMonths = 1,
        public float $calibrationBinWidth = 0.05,
        public string $regimeClassifier = self::REGIME_VOLATILITY_PERCENTILE,
        public float $regimeThreshold = 0.7,
        public int $randomizationIterations = 10,
        public float $simulationThreshold = 0.7,
        public array $testsToRun = [self::TEST_ALL],
        public string $outputFormat = 'json'
    ) {
        $this->validate();
    }

    /**
     * Create a configuration from an array of options.
     *
     * @param  array  $data  Configuration data
     *
     * @throws AnalysisException If validation fails
     */
    public static function fromArray(array $data): self
    {
        // Parse date strings to timestamps
        $trainStart = self::parseDateToTimestamp($data['train_start'] ?? null);
        $trainEnd = self::parseDateToTimestamp($data['train_end'] ?? null, true);
        $testStart = self::parseDateToTimestamp($data['test_start'] ?? null);
        $testEnd = self::parseDateToTimestamp($data['test_end'] ?? null, true);
        $validationStart = self::parseDateToTimestamp($data['validation_start'] ?? null);
        $validationEnd = self::parseDateToTimestamp($data['validation_end'] ?? null, true);

        // Parse tests to run
        $testsToRun = self::parseTestsToRun($data['tests'] ?? 'all');

        return new self(
            exchange: strtolower($data['exchange'] ?? ''),
            market: strtoupper($data['market'] ?? ''),
            timeframe: $data['timeframe'] ?? '1m',
            blockMinutes: (int) ($data['block_minutes'] ?? 15),
            bucketSize: (float) ($data['bucket_size'] ?? 0.001),
            useClosePrice: (bool) ($data['use_close_price'] ?? false),
            minimumSamples: (int) ($data['minimum_samples'] ?? 100),
            mergeSymmetric: (bool) ($data['merge_symmetric'] ?? false),
            volatilityNormalized: (bool) ($data['volatility_normalized'] ?? false),
            volatilityLookback: (int) ($data['volatility_lookback'] ?? 20),
            trainStartTimestamp: $trainStart,
            trainEndTimestamp: $trainEnd,
            testStartTimestamp: $testStart,
            testEndTimestamp: $testEnd,
            validationStartTimestamp: $validationStart,
            validationEndTimestamp: $validationEnd,
            rollingWindowMonths: (int) ($data['rolling_window_months'] ?? 6),
            rollingStepMonths: (int) ($data['rolling_step_months'] ?? 1),
            calibrationBinWidth: (float) ($data['calibration_bin_width'] ?? 0.05),
            regimeClassifier: $data['regime_classifier'] ?? self::REGIME_VOLATILITY_PERCENTILE,
            regimeThreshold: (float) ($data['regime_threshold'] ?? 0.7),
            randomizationIterations: (int) ($data['randomization_iterations'] ?? 10),
            simulationThreshold: (float) ($data['simulation_threshold'] ?? 0.7),
            testsToRun: $testsToRun,
            outputFormat: $data['output_format'] ?? 'json'
        );
    }

    /**
     * Parse a date string to a Unix timestamp.
     *
     * @param  string|null  $dateString  Date string (Y-m-d or Y-m-d H:i:s)
     * @param  bool  $endOfDay  If true and only date provided, use end of day
     * @return int|null Unix timestamp or null if not provided
     *
     * @throws AnalysisException If date format is invalid
     */
    private static function parseDateToTimestamp(?string $dateString, bool $endOfDay = false): ?int
    {
        if ($dateString === null || $dateString === '') {
            return null;
        }

        // Try Y-m-d H:i:s format first
        $dateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $dateString);
        if ($dateTime !== false) {
            return $dateTime->getTimestamp();
        }

        // Try Y-m-d format
        $dateTime = \DateTime::createFromFormat('Y-m-d', $dateString);
        if ($dateTime !== false) {
            if ($endOfDay) {
                $dateTime->setTime(23, 59, 59);
            } else {
                $dateTime->setTime(0, 0, 0);
            }

            return $dateTime->getTimestamp();
        }

        throw AnalysisException::invalidConfiguration(
            "Invalid date format: '{$dateString}'. Expected Y-m-d or Y-m-d H:i:s."
        );
    }

    /**
     * Parse the tests to run from a comma-separated string.
     *
     * @param  string  $tests  Comma-separated list of tests
     */
    private static function parseTestsToRun(string $tests): array
    {
        if ($tests === 'all' || $tests === '') {
            return [self::TEST_ALL];
        }

        return array_map('trim', explode(',', $tests));
    }

    /**
     * Validate the configuration.
     *
     * @throws AnalysisException If validation fails
     */
    private function validate(): void
    {
        if (empty($this->exchange)) {
            throw AnalysisException::invalidConfiguration('Exchange cannot be empty.');
        }

        if (empty($this->market)) {
            throw AnalysisException::invalidConfiguration('Market cannot be empty.');
        }

        if ($this->timeframe !== '1m') {
            throw AnalysisException::invalidTimeframe('1m', $this->timeframe);
        }

        if ($this->blockMinutes < 1) {
            throw AnalysisException::invalidConfiguration('Block minutes must be at least 1.');
        }

        if ($this->bucketSize <= 0) {
            throw AnalysisException::invalidConfiguration('Bucket size must be positive.');
        }

        if ($this->minimumSamples < 1) {
            throw AnalysisException::invalidConfiguration('Minimum samples must be at least 1.');
        }

        // Validate train/test period ordering
        if ($this->trainStartTimestamp !== null && $this->trainEndTimestamp !== null) {
            if ($this->trainStartTimestamp > $this->trainEndTimestamp) {
                throw AnalysisException::invalidConfiguration('Train start date must be before or equal to train end date.');
            }
        }

        if ($this->testStartTimestamp !== null && $this->testEndTimestamp !== null) {
            if ($this->testStartTimestamp > $this->testEndTimestamp) {
                throw AnalysisException::invalidConfiguration('Test start date must be before or equal to test end date.');
            }
        }

        // Validate that test period comes after train period (chronological split)
        if ($this->trainEndTimestamp !== null && $this->testStartTimestamp !== null) {
            if ($this->testStartTimestamp <= $this->trainEndTimestamp) {
                throw AnalysisException::invalidConfiguration('Test period must start after train period ends for chronological out-of-sample testing.');
            }
        }

        // Validate rolling window parameters
        if ($this->rollingWindowMonths < 1) {
            throw AnalysisException::invalidConfiguration('Rolling window months must be at least 1.');
        }

        if ($this->rollingStepMonths < 1) {
            throw AnalysisException::invalidConfiguration('Rolling step months must be at least 1.');
        }

        // Validate calibration bin width
        if ($this->calibrationBinWidth <= 0 || $this->calibrationBinWidth > 1) {
            throw AnalysisException::invalidConfiguration('Calibration bin width must be between 0 and 1.');
        }

        // Validate regime classifier
        $validRegimeClassifiers = [
            self::REGIME_VOLATILITY_PERCENTILE,
            self::REGIME_VOLATILITY_THRESHOLD,
            self::REGIME_ATR_BASED,
        ];
        if (! in_array($this->regimeClassifier, $validRegimeClassifiers, true)) {
            throw AnalysisException::invalidConfiguration(
                "Invalid regime classifier: '{$this->regimeClassifier}'. Must be one of: ".implode(', ', $validRegimeClassifiers)
            );
        }

        // Validate regime threshold
        if ($this->regimeThreshold <= 0 || $this->regimeThreshold > 1) {
            throw AnalysisException::invalidConfiguration('Regime threshold must be between 0 and 1.');
        }

        // Validate randomization iterations
        if ($this->randomizationIterations < 1) {
            throw AnalysisException::invalidConfiguration('Randomization iterations must be at least 1.');
        }

        // Validate simulation threshold
        if ($this->simulationThreshold <= 0 || $this->simulationThreshold > 1) {
            throw AnalysisException::invalidConfiguration('Simulation threshold must be between 0 and 1.');
        }

        // Validate output format
        $validOutputFormats = ['json', 'csv', 'markdown', 'all'];
        if (! in_array($this->outputFormat, $validOutputFormats, true)) {
            throw AnalysisException::invalidConfiguration(
                "Invalid output format: '{$this->outputFormat}'. Must be one of: ".implode(', ', $validOutputFormats)
            );
        }
    }

    /**
     * Check if a specific test should be run.
     *
     * @param  string  $test  The test identifier
     */
    public function shouldRunTest(string $test): bool
    {
        return in_array(self::TEST_ALL, $this->testsToRun, true) || in_array($test, $this->testsToRun, true);
    }

    /**
     * Check if train/test split is configured.
     */
    public function hasTrainTestSplit(): bool
    {
        return $this->trainStartTimestamp !== null
            && $this->trainEndTimestamp !== null
            && $this->testStartTimestamp !== null
            && $this->testEndTimestamp !== null;
    }

    /**
     * Check if validation period is configured.
     */
    public function hasValidationPeriod(): bool
    {
        return $this->validationStartTimestamp !== null && $this->validationEndTimestamp !== null;
    }

    /**
     * Get the train date range as a string.
     */
    public function getTrainDateRangeString(): ?string
    {
        if ($this->trainStartTimestamp === null && $this->trainEndTimestamp === null) {
            return null;
        }

        $parts = [];

        if ($this->trainStartTimestamp !== null) {
            $parts[] = date('Y-m-d', $this->trainStartTimestamp);
        }

        $parts[] = 'to';

        if ($this->trainEndTimestamp !== null) {
            $parts[] = date('Y-m-d', $this->trainEndTimestamp);
        }

        return implode(' ', $parts);
    }

    /**
     * Get the test date range as a string.
     */
    public function getTestDateRangeString(): ?string
    {
        if ($this->testStartTimestamp === null && $this->testEndTimestamp === null) {
            return null;
        }

        $parts = [];

        if ($this->testStartTimestamp !== null) {
            $parts[] = date('Y-m-d', $this->testStartTimestamp);
        }

        $parts[] = 'to';

        if ($this->testEndTimestamp !== null) {
            $parts[] = date('Y-m-d', $this->testEndTimestamp);
        }

        return implode(' ', $parts);
    }

    /**
     * Create an OpenCrossAnalysisConfig from this validation config.
     *
     * @param  int|null  $startTimestamp  Override start timestamp
     * @param  int|null  $endTimestamp  Override end timestamp
     */
    public function toAnalysisConfig(?int $startTimestamp = null, ?int $endTimestamp = null): OpenCrossAnalysisConfig
    {
        return new OpenCrossAnalysisConfig(
            exchange: $this->exchange,
            market: $this->market,
            timeframe: $this->timeframe,
            blockMinutes: $this->blockMinutes,
            bucketSize: $this->bucketSize,
            useClosePrice: $this->useClosePrice,
            minimumSamples: $this->minimumSamples,
            mergeSymmetric: $this->mergeSymmetric,
            volatilityNormalized: $this->volatilityNormalized,
            volatilityLookback: $this->volatilityLookback,
            startTimestamp: $startTimestamp,
            endTimestamp: $endTimestamp
        );
    }

    /**
     * Convert the configuration to an array.
     */
    public function toArray(): array
    {
        return [
            'exchange' => $this->exchange,
            'market' => $this->market,
            'timeframe' => $this->timeframe,
            'block_minutes' => $this->blockMinutes,
            'bucket_size' => $this->bucketSize,
            'use_close_price' => $this->useClosePrice,
            'minimum_samples' => $this->minimumSamples,
            'merge_symmetric' => $this->mergeSymmetric,
            'volatility_normalized' => $this->volatilityNormalized,
            'volatility_lookback' => $this->volatilityLookback,
            'train_start_timestamp' => $this->trainStartTimestamp,
            'train_end_timestamp' => $this->trainEndTimestamp,
            'test_start_timestamp' => $this->testStartTimestamp,
            'test_end_timestamp' => $this->testEndTimestamp,
            'validation_start_timestamp' => $this->validationStartTimestamp,
            'validation_end_timestamp' => $this->validationEndTimestamp,
            'rolling_window_months' => $this->rollingWindowMonths,
            'rolling_step_months' => $this->rollingStepMonths,
            'calibration_bin_width' => $this->calibrationBinWidth,
            'regime_classifier' => $this->regimeClassifier,
            'regime_threshold' => $this->regimeThreshold,
            'randomization_iterations' => $this->randomizationIterations,
            'simulation_threshold' => $this->simulationThreshold,
            'tests_to_run' => $this->testsToRun,
            'output_format' => $this->outputFormat,
        ];
    }
}
