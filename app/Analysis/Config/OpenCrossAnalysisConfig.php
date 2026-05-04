<?php

namespace App\Analysis\Config;

use App\Analysis\Exception\AnalysisException;

/**
 * Immutable configuration for the Open-Cross Probability analysis.
 */
final readonly class OpenCrossAnalysisConfig
{
    /**
     * @param  string  $exchange  The exchange identifier (e.g., 'binance')
     * @param  string  $market  The market symbol (e.g., 'BTC/USDT')
     * @param  string  $timeframe  The source timeframe (must be '1m')
     * @param  int  $blockMinutes  Block duration in minutes
     * @param  float  $bucketSize  Distance bucket size (as decimal, e.g., 0.001 = 0.1%)
     * @param  bool  $useClosePrice  Use close price for distance calculation
     * @param  int  $minimumSamples  Minimum samples for high confidence
     * @param  bool  $mergeSymmetric  Merge positive/negative buckets by absolute value
     * @param  bool  $volatilityNormalized  Normalize distance by volatility
     * @param  int  $volatilityLookback  Lookback period for volatility calculation
     * @param  int|null  $startTimestamp  Unix timestamp for start of analysis range
     * @param  int|null  $endTimestamp  Unix timestamp for end of analysis range
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
        public int $volatilityLookback = 60,
        public ?int $startTimestamp = null,
        public ?int $endTimestamp = null
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
        $startTimestamp = self::parseDateToTimestamp($data['start_date'] ?? null);
        $endTimestamp = self::parseDateToTimestamp($data['end_date'] ?? null, true);

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
            startTimestamp: $startTimestamp,
            endTimestamp: $endTimestamp
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
     * Check if date filtering is enabled.
     */
    public function hasDateFilter(): bool
    {
        return $this->startTimestamp !== null || $this->endTimestamp !== null;
    }

    /**
     * Check if a timestamp is within the configured date range.
     *
     * @param  int  $timestamp  Unix timestamp to check
     */
    public function isWithinDateRange(int $timestamp): bool
    {
        if ($this->startTimestamp !== null && $timestamp < $this->startTimestamp) {
            return false;
        }

        if ($this->endTimestamp !== null && $timestamp > $this->endTimestamp) {
            return false;
        }

        return true;
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

        if ($this->volatilityNormalized && $this->volatilityLookback < 2) {
            throw AnalysisException::invalidConfiguration('Volatility lookback must be at least 2 when volatility normalization is enabled.');
        }

        if ($this->startTimestamp !== null && $this->endTimestamp !== null && $this->startTimestamp > $this->endTimestamp) {
            throw AnalysisException::invalidConfiguration('Start date must be before or equal to end date.');
        }
    }

    /**
     * Get the bucket key for a given distance value.
     *
     * @param  float  $distance  The distance value
     * @return float The bucket key
     */
    public function getBucketKey(float $distance): float
    {
        $bucket = floor($distance / $this->bucketSize) * $this->bucketSize;

        if ($this->mergeSymmetric) {
            return abs($bucket);
        }

        return $bucket;
    }

    /**
     * Get formatted date range string for display.
     */
    public function getDateRangeString(): ?string
    {
        if (! $this->hasDateFilter()) {
            return null;
        }

        $parts = [];

        if ($this->startTimestamp !== null) {
            $parts[] = 'from '.date('Y-m-d', $this->startTimestamp);
        }

        if ($this->endTimestamp !== null) {
            $parts[] = 'to '.date('Y-m-d', $this->endTimestamp);
        }

        return implode(' ', $parts);
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
            'start_timestamp' => $this->startTimestamp,
            'end_timestamp' => $this->endTimestamp,
        ];
    }
}
