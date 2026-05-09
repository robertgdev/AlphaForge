<?php

namespace App\Analysis\Engine;

/**
 * Calculates volatility metrics for normalization.
 *
 * Uses standard deviation of log returns for proper sigma-based normalization.
 */
final class VolatilityCalculator
{
/**
      * Calculate rolling volatility for a series of records.
      *
      * Computes the standard deviation of log returns over a rolling window.
      * This is the correct measure for sigma-based distance normalization.
      *
      * @param  array<int, array{close: float}>  $records  Array of OHLC records with 'close' key
      * @param  int  $lookbackPeriod  Number of periods for rolling calculation (minimum 30 recommended)
      * @return array<int, float> Array of volatility values (as decimals, not percentages)
      */
     public function calculateRollingVolatility(array $records, int $lookbackPeriod): array
    {
        $count = count($records);

        if ($count < 2) {
            return array_fill(0, $count, 0.0);
        }

        // Calculate log returns
        $logReturns = $this->calculateLogReturns($records);

        // Calculate rolling standard deviation using EWMA for stability
        $volatilities = $this->calculateRollingStdDev($logReturns, $lookbackPeriod);

        return $volatilities;
    }

/**
      * Calculate log returns for a series of records.
      *
      * @param  array<int, array{close: float}>  $records  Array of OHLC records
      * @return array<int, float> Array of log return values
      */
     private function calculateLogReturns(array $records): array
    {
        $count = count($records);
        $logReturns = [];
        $logReturns[0] = 0.0;

        for ($i = 1; $i < $count; $i++) {
            $currentClose = (float) $records[$i]['close'];
            $previousClose = (float) $records[$i - 1]['close'];

            if ($previousClose > 0 && $currentClose > 0) {
                $logReturns[$i] = log($currentClose / $previousClose);
            } else {
                $logReturns[$i] = 0.0;
            }
        }

        return $logReturns;
    }

/**
      * Calculate rolling standard deviation of log returns.
      *
      * Uses exponentially weighted moving average (EWMA) for more stable estimates.
      *
      * @param  array<int, float>  $logReturns  Array of log return values
      * @param  int  $lookbackPeriod  Number of periods for calculation
      * @return array<int, float> Array of volatility values
      */
     private function calculateRollingStdDev(array $logReturns, int $lookbackPeriod): array
    {
        $count = count($logReturns);
        $volatilities = [];

        // EWMA lambda (decay factor) - higher = more weight on recent observations
        $lambda = 1 - (2 / ($lookbackPeriod + 1));

        // Initialize with simple std dev for first window
        $mean = 0.0;
        $variance = 0.0;
        $ewmaVariance = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $return = $logReturns[$i];

            if ($i < $lookbackPeriod) {
                // Build up initial estimate using simple rolling calculation
                $window = array_slice($logReturns, 0, $i + 1);
                $windowCount = count($window);

                if ($windowCount < 2) {
                    $volatilities[$i] = 0.0;

                    continue;
                }

                $mean = array_sum($window) / $windowCount;
                $variance = 0.0;
                foreach ($window as $value) {
                    $variance += ($value - $mean) ** 2;
                }
                $volatilities[$i] = sqrt($variance / ($windowCount - 1));
                $ewmaVariance = $variance / $windowCount;
            } else {
                // Use EWMA for stable rolling estimate
                // EWMA variance: σ²_t = λ * σ²_{t-1} + (1-λ) * r²_t
                $ewmaVariance = $lambda * $ewmaVariance + (1 - $lambda) * ($return ** 2);
                $volatilities[$i] = sqrt($ewmaVariance);
            }
        }

        return $volatilities;
    }

/**
      * Calculate the volatility for a single block of records.
      *
      * @param  array<int, array{close: float}>  $blockRecords  Array of OHLC records
      * @return float The average volatility for the block
      */
     public function calculateBlockVolatility(array $blockRecords): float
    {
        $count = count($blockRecords);

        if ($count < 2) {
            return 0.0;
        }

        $logReturns = $this->calculateLogReturns($blockRecords);

        // Remove first element (always 0)
        array_shift($logReturns);

        if (count($logReturns) < 2) {
            return 0.0;
        }

        $mean = array_sum($logReturns) / count($logReturns);
        $variance = 0.0;

        foreach ($logReturns as $value) {
            $variance += ($value - $mean) ** 2;
        }

        return sqrt($variance / (count($logReturns) - 1));
    }

/**
      * Get the volatility value to use for normalization at a given position.
      *
      * When volatility normalization is enabled, this returns the appropriate
      * volatility value for converting raw distance to sigma-based distance.
      *
      * @param  array<int, float>  $volatilities  Array of volatility values
      * @param  int  $index  Current index
      * @return float Volatility value (minimum 0.001 to avoid extreme z-scores)
      */
     public function getVolatilityForNormalization(array $volatilities, int $index): float
    {
        $volatility = $volatilities[$index] ?? 0.0;

        // Minimum volatility floor of 0.1% (0.001) to prevent extreme z-scores
        // This is a reasonable floor for liquid markets
        return max($volatility, 0.001);
    }
}
