<?php

namespace App\AlphaForge\Analysis\Dto\Validation;

use App\AlphaForge\Analysis\Dto\OpenCrossProbabilityResult;

/**
 * Represents the train/test split result.
 */
final readonly class TrainTestResult
{
    /**
     * @param  OpenCrossProbabilityResult  $trainSurface  Probability surface built on training data
     * @param  int  $trainObservations  Number of training observations
     * @param  int  $testObservations  Number of test observations
     * @param  array  $trainPeriod  Training period date range
     * @param  array  $testPeriod  Test period date range
     * @param  float  $meanPredictedProbability  Mean predicted probability on test set
     * @param  float  $meanRealizedFrequency  Mean realized frequency on test set
     * @param  float  $absoluteCalibrationError  Absolute calibration error
     */
    public function __construct(
        public OpenCrossProbabilityResult $trainSurface,
        public int $trainObservations,
        public int $testObservations,
        public array $trainPeriod,
        public array $testPeriod,
        public float $meanPredictedProbability,
        public float $meanRealizedFrequency,
        public float $absoluteCalibrationError
    ) {}

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'train_period' => $this->trainPeriod,
            'test_period' => $this->testPeriod,
            'train_observations' => $this->trainObservations,
            'test_observations' => $this->testObservations,
            'metrics' => [
                'mean_predicted_probability' => round($this->meanPredictedProbability, 4),
                'mean_realized_frequency' => round($this->meanRealizedFrequency, 4),
                'absolute_calibration_error' => round($this->absoluteCalibrationError, 4),
            ],
            'train_surface_summary' => [
                'total_blocks' => $this->trainSurface->totalBlocksAnalyzed,
                'total_observations' => $this->trainSurface->totalObservations,
                'surface_points' => count($this->trainSurface->probabilitySurface),
            ],
        ];
    }
}
