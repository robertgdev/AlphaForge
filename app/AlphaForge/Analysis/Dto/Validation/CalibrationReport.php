<?php

namespace App\AlphaForge\Analysis\Dto\Validation;

/**
 * Represents the complete calibration test report.
 */
final readonly class CalibrationReport
{
    /**
     * @param  array<CalibrationBin>  $bins  Calibration bins
     * @param  float  $brierScore  Mean squared error of predictions
     * @param  float  $meanAbsoluteCalibrationError  Mean absolute calibration error
     * @param  float  $maxCalibrationDeviation  Maximum calibration deviation
     * @param  bool  $isCalibrated  Whether the model passes calibration criteria
     * @param  int  $totalSamples  Total number of samples in calibration test
     */
    public function __construct(
        public array $bins,
        public float $brierScore,
        public float $meanAbsoluteCalibrationError,
        public float $maxCalibrationDeviation,
        public bool $isCalibrated,
        public int $totalSamples
    ) {}

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'brier_score' => round($this->brierScore, 4),
            'mean_absolute_calibration_error' => round($this->meanAbsoluteCalibrationError, 4),
            'max_calibration_deviation' => round($this->maxCalibrationDeviation, 4),
            'is_calibrated' => $this->isCalibrated,
            'total_samples' => $this->totalSamples,
            'bins' => array_map(fn (CalibrationBin $bin) => $bin->toArray(), $this->bins),
        ];
    }

    /**
     * Convert to CSV format.
     */
    public function toCsv(): string
    {
        $header = "bin_start,bin_end,samples,avg_predicted,observed_frequency,calibration_error\n";
        $rows = [];

        foreach ($this->bins as $bin) {
            $rows[] = sprintf(
                '%.4f,%.4f,%d,%.4f,%.4f,%.4f',
                $bin->binStart,
                $bin->binEnd,
                $bin->samples,
                $bin->avgPredictedProbability,
                $bin->observedFrequency,
                $bin->calibrationError
            );
        }

        return $header.implode("\n", $rows);
    }
}
