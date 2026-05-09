<?php

namespace App\Analysis\Engine\Validation;

use App\Analysis\Config\ValidationConfig;
use App\Analysis\Dto\OpenCrossProbabilityResult;
use App\Analysis\Dto\Validation\BucketUncertainty;
use App\Analysis\Dto\Validation\UncertaintyReport;

/**
 * Estimates statistical uncertainty for probability surface buckets.
 */
final class UncertaintyEstimator
{
    /**
     * Estimate uncertainty for all buckets in a probability surface.
     *
     * @param  OpenCrossProbabilityResult  $surface  Probability surface
     * @param  ValidationConfig  $config  Configuration
     */
    public function estimate(
        OpenCrossProbabilityResult $surface,
        ValidationConfig $config
    ): UncertaintyReport {
        $buckets = [];
        $flaggedCount = 0;
        $totalStandardError = 0.0;

        foreach ($surface->probabilitySurface as $point) {
            $uncertainty = $this->estimateBucketUncertainty(
                $point->distanceBucket,
                $point->minutesRemaining,
                $point->crossProbability,
                $point->samples,
                $config->minimumSamples
            );

            $buckets[] = $uncertainty;

            if (! $uncertainty->isReliable) {
                $flaggedCount++;
            }

            $totalStandardError += $uncertainty->standardError;
        }

        $totalBuckets = count($buckets);
        $avgStandardError = $totalBuckets > 0 ? $totalStandardError / $totalBuckets : 0.0;

        return new UncertaintyReport(
            buckets: $buckets,
            flaggedBuckets: $flaggedCount,
            totalBuckets: $totalBuckets,
            avgStandardError: $avgStandardError
        );
    }

    /**
     * Estimate uncertainty for a single bucket.
     *
     * @param  float  $distanceBucket  Distance bucket value
     * @param  int  $minutesRemaining  Minutes remaining
     * @param  float  $probability  Cross probability
     * @param  int  $samples  Sample count
     * @param  int  $minimumSamples  Minimum samples threshold
     */
    private function estimateBucketUncertainty(
        float $distanceBucket,
        int $minutesRemaining,
        float $probability,
        int $samples,
        int $minimumSamples
    ): BucketUncertainty {
        $standardError = $this->computeStandardError($probability, $samples);
        $confidenceInterval = $this->computeConfidenceInterval($probability, $samples, 0.95);
        $isReliable = $samples >= $minimumSamples;

        return new BucketUncertainty(
            distanceBucket: $distanceBucket,
            minutesRemaining: $minutesRemaining,
            probability: $probability,
            samples: $samples,
            standardError: $standardError,
            confidenceInterval95: $confidenceInterval,
            isReliable: $isReliable
        );
    }

    /**
     * Compute standard error for a probability estimate.
     *
     * SE = sqrt(p(1-p)/n)
     *
     * @param  float  $probability  Probability estimate
     * @param  int  $samples  Sample count
     */
    public function computeStandardError(float $probability, int $samples): float
    {
        if ($samples <= 0) {
            return 1.0; // Maximum uncertainty
        }

        // Clamp probability to avoid numerical issues
        $p = max(0.0, min(1.0, $probability));

        return sqrt($p * (1 - $p) / $samples);
    }

/**
      * Compute confidence interval for a probability estimate.
      *
      * @param  float  $probability  Probability estimate
      * @param  int  $samples  Sample count
      * @param  float  $confidence  Confidence level (e.g., 0.95 for 95%)
      * @return array{0: float, 1: float} [lower, upper]
      */
     public function computeConfidenceInterval(
        float $probability,
        int $samples,
        float $confidence = 0.95
    ): array {
        if ($samples <= 0) {
            return [0.0, 1.0];
        }

        $standardError = $this->computeStandardError($probability, $samples);

        // Z-score for confidence level
        $zScore = $this->getZScore($confidence);

        $margin = $zScore * $standardError;

        $lower = max(0.0, $probability - $margin);
        $upper = min(1.0, $probability + $margin);

        return [$lower, $upper];
    }

    /**
     * Get the Z-score for a given confidence level.
     *
     * @param  float  $confidence  Confidence level
     */
    private function getZScore(float $confidence): float
    {
        // Common Z-scores
        return match ($confidence) {
            0.90 => 1.645,
            0.95 => 1.96,
            0.99 => 2.576,
            default => 1.96, // Default to 95%
        };
    }

/**
      * Compute Wilson score interval for better handling of extreme probabilities.
      *
      * This is more accurate than the normal approximation for probabilities near 0 or 1.
      *
      * @param  float  $probability  Probability estimate
      * @param  int  $samples  Sample count
      * @param  float  $confidence  Confidence level
      * @return array{0: float, 1: float} [lower, upper]
      */
     public function computeWilsonInterval(
        float $probability,
        int $samples,
        float $confidence = 0.95
    ): array {
        if ($samples <= 0) {
            return [0.0, 1.0];
        }

        $z = $this->getZScore($confidence);
        $z2 = $z * $z;

        $p = max(0.0, min(1.0, $probability));
        $n = $samples;

        $denominator = 1 + $z2 / $n;
        $center = ($p + $z2 / (2 * $n)) / $denominator;
        $margin = ($z / $denominator) * sqrt(($p * (1 - $p) + $z2 / (4 * $n)) / $n);

        return [
            max(0.0, $center - $margin),
            min(1.0, $center + $margin),
        ];
    }

/**
      * Compute Agresti-Coull interval (another alternative for extreme probabilities).
      *
      * @param  float  $probability  Probability estimate
      * @param  int  $samples  Sample count
      * @param  float  $confidence  Confidence level
      * @return array{0: float, 1: float} [lower, upper]
      */
     public function computeAgrestiCoullInterval(
        float $probability,
        int $samples,
        float $confidence = 0.95
    ): array {
        if ($samples <= 0) {
            return [0.0, 1.0];
        }

        $z = $this->getZScore($confidence);
        $z2 = $z * $z;

        $p = max(0.0, min(1.0, $probability));
        $n = $samples;

        // Add "pseudo-observations"
        $nTilde = $n + $z2;
        $pTilde = ($n * $p + $z2 / 2) / $nTilde;

        $standardError = sqrt($pTilde * (1 - $pTilde) / $nTilde);
        $margin = $z * $standardError;

        return [
            max(0.0, $pTilde - $margin),
            min(1.0, $pTilde + $margin),
        ];
    }

    /**
     * Compute minimum sample size required for a given margin of error.
     *
     * @param  float  $marginOfError  Desired margin of error
     * @param  float  $confidence  Confidence level
     * @return int Minimum sample size
     */
    public function computeMinimumSampleSize(float $marginOfError, float $confidence = 0.95): int
    {
        $z = $this->getZScore($confidence);

        // Use p = 0.5 for maximum required sample size
        $p = 0.5;

        $n = ($z * $z * $p * (1 - $p)) / ($marginOfError * $marginOfError);

        return (int) ceil($n);
    }

/**
      * Flag buckets that have insufficient samples or high uncertainty.
      *
      * @param  UncertaintyReport  $report  Uncertainty report
      * @param  int  $minimumSamples  Minimum samples threshold
      * @param  float  $maximumStandardError  Maximum acceptable standard error
      * @return array<string, array{distance_bucket: float, minutes_remaining: int, samples: int, standard_error: float, reason: string}> Array of flagged bucket keys
      */
     public function flagProblematicBuckets(
        UncertaintyReport $report,
        int $minimumSamples,
        float $maximumStandardError = 0.1
    ): array {
        $flagged = [];

        foreach ($report->buckets as $bucket) {
            if ($bucket->samples < $minimumSamples || $bucket->standardError > $maximumStandardError) {
                $key = sprintf('%.6f_%d', $bucket->distanceBucket, $bucket->minutesRemaining);
                $flagged[$key] = [
                    'distance_bucket' => $bucket->distanceBucket,
                    'minutes_remaining' => $bucket->minutesRemaining,
                    'samples' => $bucket->samples,
                    'standard_error' => $bucket->standardError,
                    'reason' => $bucket->samples < $minimumSamples ? 'insufficient_samples' : 'high_uncertainty',
                ];
            }
        }

        return $flagged;
    }
}
