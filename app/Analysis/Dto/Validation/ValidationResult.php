<?php

namespace App\Analysis\Dto\Validation;

use App\Analysis\Config\ValidationConfig;

/**
 * Represents the complete validation result containing all test reports.
 */
final readonly class ValidationResult
{
    /**
     * @param  array  $metadata  Validation metadata
     * @param  TrainTestResult|null  $trainTest  Train/test split results
     * @param  CalibrationReport|null  $calibration  Calibration test results
     * @param  StabilityReport|null  $stability  Rolling stability results
     * @param  RegimeReport|null  $regime  Regime sensitivity results
     * @param  UncertaintyReport|null  $uncertainty  Uncertainty estimation results
     * @param  RandomizationReport|null  $randomization  Randomized baseline results
     * @param  CrossPeriodReport|null  $crossPeriod  Cross-period comparison results
     * @param  SimulationReport|null  $simulation  Strategy simulation results
     * @param  array  $acceptanceCriteria  Acceptance criteria evaluation
     */
    public function __construct(
        public array $metadata,
        public ?TrainTestResult $trainTest,
        public ?CalibrationReport $calibration,
        public ?StabilityReport $stability,
        public ?RegimeReport $regime,
        public ?UncertaintyReport $uncertainty,
        public ?RandomizationReport $randomization,
        public ?CrossPeriodReport $crossPeriod,
        public ?SimulationReport $simulation,
        public array $acceptanceCriteria
    ) {}

    /**
     * Create a validation result from individual test results.
     *
     * @param  ValidationConfig  $config  Configuration used
     * @param  array  $results  Individual test results
     */
    public static function fromResults(ValidationConfig $config, array $results): self
    {
        $metadata = [
            'exchange' => $config->exchange,
            'market' => $config->market,
            'timeframe' => $config->timeframe,
            'validation_timestamp' => date('c'),
            'config' => $config->toArray(),
        ];

        // Evaluate acceptance criteria
        $acceptanceCriteria = self::evaluateAcceptanceCriteria($results);

        return new self(
            metadata: $metadata,
            trainTest: $results['train_test'] ?? null,
            calibration: $results['calibration'] ?? null,
            stability: $results['stability'] ?? null,
            regime: $results['regime'] ?? null,
            uncertainty: $results['uncertainty'] ?? null,
            randomization: $results['randomization'] ?? null,
            crossPeriod: $results['cross_period'] ?? null,
            simulation: $results['simulation'] ?? null,
            acceptanceCriteria: $acceptanceCriteria
        );
    }

    /**
     * Evaluate acceptance criteria based on test results.
     *
     * @param  array  $results  Test results
     */
    private static function evaluateAcceptanceCriteria(array $results): array
    {
        $criteria = [];

        // Out-of-sample calibration
        if (isset($results['train_test'])) {
            $trainTest = $results['train_test'];
            $criteria['out_of_sample_calibration'] = [
                'status' => $trainTest->absoluteCalibrationError < 0.05 ? 'PASS' : 'FAIL',
                'value' => round($trainTest->absoluteCalibrationError, 4),
                'threshold' => 0.05,
            ];
        }

        // Rolling stability
        if (isset($results['stability'])) {
            $stability = $results['stability'];
            $criteria['rolling_stability'] = [
                'status' => $stability->isStable ? 'PASS' : 'FAIL',
                'value' => round($stability->overallStabilityScore, 4),
                'threshold' => 0.85,
            ];
        }

        // Regime differences
        if (isset($results['regime'])) {
            $regime = $results['regime'];
            $criteria['regime_differences'] = [
                'status' => $regime->isExplainable ? 'PASS' : 'FAIL',
                'value' => round($regime->crossRegimeStability, 4),
                'threshold' => 0.80,
            ];
        }

        // Randomized baseline
        if (isset($results['randomization'])) {
            $randomization = $results['randomization'];
            $criteria['randomized_baseline'] = [
                'status' => $randomization->isSignificantlyDifferent ? 'PASS' : 'FAIL',
                'value' => round($randomization->structuralDeviationScore, 4),
                'threshold' => 0.10,
            ];
        }

        // Signal simulation
        if (isset($results['simulation'])) {
            $simulation = $results['simulation'];
            $criteria['signal_simulation'] = [
                'status' => $simulation->isProfitable && $simulation->sharpeRatio > 1.0 ? 'PASS' : 'FAIL',
                'value' => round($simulation->sharpeRatio, 4),
                'threshold' => 1.0,
            ];
        }

        // Overall status
        $allPass = true;
        foreach ($criteria as $criterion) {
            if ($criterion['status'] === 'FAIL') {
                $allPass = false;
                break;
            }
        }
        $criteria['overall'] = [
            'status' => $allPass ? 'PASS' : 'FAIL',
            'tests_passed' => count(array_filter($criteria, fn ($c) => $c['status'] === 'PASS')),
            'tests_total' => count($criteria) - 1, // Exclude 'overall'
        ];

        return $criteria;
    }

    /**
     * Check if all validation tests passed.
     */
    public function isPassed(): bool
    {
        return ($this->acceptanceCriteria['overall']['status'] ?? 'FAIL') === 'PASS';
    }

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'metadata' => $this->metadata,
            'train_test' => $this->trainTest?->toArray(),
            'calibration' => $this->calibration?->toArray(),
            'stability' => $this->stability?->toArray(),
            'regime_sensitivity' => $this->regime?->toArray(),
            'uncertainty' => $this->uncertainty?->toArray(),
            'randomized_baseline' => $this->randomization?->toArray(),
            'cross_period' => $this->crossPeriod?->toArray(),
            'simulation' => $this->simulation?->toArray(),
            'acceptance_criteria' => $this->acceptanceCriteria,
        ];
    }

    /**
     * Convert to JSON string.
     *
     * @param  int  $flags  JSON encoding flags
     */
    public function toJson(int $flags = JSON_PRETTY_PRINT): string
    {
        return json_encode($this->toArray(), $flags);
    }
}
