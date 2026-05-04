<?php

namespace App\Analysis\Dto;

/**
 * Represents a single point in the probability surface.
 */
final readonly class ProbabilitySurfacePoint
{
    /**
     * @param  float  $distanceBucket  The distance bucket value
     * @param  int  $minutesRemaining  Minutes remaining in the block
     * @param  int  $samples  Number of samples in this bucket
     * @param  float  $crossProbability  Probability of crossing (0.0 to 1.0)
     * @param  string  $confidence  Confidence level: 'high', 'medium', or 'low'
     */
    public function __construct(
        public float $distanceBucket,
        public int $minutesRemaining,
        public int $samples,
        public float $crossProbability,
        public string $confidence
    ) {}

    /**
     * Create a point from accumulated statistics.
     *
     * @param  float  $bucket  The distance bucket
     * @param  int  $minutesRemaining  Minutes remaining
     * @param  int  $total  Total observations
     * @param  int  $crosses  Number of crosses
     * @param  int  $minSamplesHigh  Minimum samples for high confidence
     * @param  int  $minSamplesMedium  Minimum samples for medium confidence
     */
    public static function fromStats(
        float $bucket,
        int $minutesRemaining,
        int $total,
        int $crosses,
        int $minSamplesHigh = 100,
        int $minSamplesMedium = 30
    ): self {
        $probability = $total > 0 ? $crosses / $total : 0.0;
        $confidence = self::determineConfidence($total, $minSamplesHigh, $minSamplesMedium);

        return new self(
            distanceBucket: $bucket,
            minutesRemaining: $minutesRemaining,
            samples: $total,
            crossProbability: $probability,
            confidence: $confidence
        );
    }

    /**
     * Determine the confidence level based on sample count.
     *
     * @param  int  $samples  Number of samples
     * @param  int  $minHigh  Minimum for high confidence
     * @param  int  $minMedium  Minimum for medium confidence
     */
    private static function determineConfidence(int $samples, int $minHigh, int $minMedium): string
    {
        if ($samples >= $minHigh) {
            return 'high';
        }
        if ($samples >= $minMedium) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Convert to an array representation.
     */
    public function toArray(): array
    {
        return [
            'distance_bucket' => $this->distanceBucket,
            'minutes_remaining' => $this->minutesRemaining,
            'samples' => $this->samples,
            'cross_probability' => round($this->crossProbability, 4),
            'confidence' => $this->confidence,
        ];
    }

    /**
     * Convert to CSV row.
     */
    public function toCsvRow(): string
    {
        return sprintf(
            '%s,%d,%d,%s,%s',
            $this->distanceBucket,
            $this->minutesRemaining,
            $this->samples,
            round($this->crossProbability, 4),
            $this->confidence
        );
    }
}
