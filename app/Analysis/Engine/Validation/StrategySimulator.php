<?php

namespace App\Analysis\Engine\Validation;

use App\Analysis\Config\ValidationConfig;
use App\Analysis\Dto\OpenCrossProbabilityResult;
use App\Analysis\Dto\Validation\SimulationReport;

/**
 * Simulates a simple trading strategy based on probability estimates.
 */
final class StrategySimulator
{
    /**
     * Simulate a trading strategy based on probability estimates.
     *
     * @param  OpenCrossProbabilityResult  $surface  Probability surface (trained on in-sample data)
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float}>  $testRecords  Test records (out-of-sample)
     * @param  ValidationConfig  $config  Configuration
     */
    public function simulate(
        OpenCrossProbabilityResult $surface,
        array $testRecords,
        ValidationConfig $config
    ): SimulationReport {
        // Build surface lookup map
        $surfaceMap = $this->buildSurfaceMap($surface);

        // Partition test records into blocks
        $blocks = $this->partitionIntoBlocks($testRecords, $config->blockMinutes);

        // Simulate trades
        $trades = [];
        $periodResults = [];

        foreach ($blocks as $blockData) {
            $blockTrades = $this->simulateBlock($blockData, $surfaceMap, $config);
            $trades = array_merge($trades, $blockTrades);
        }

        // Group trades by period for stability analysis
        $periodResults = $this->calculatePeriodResults($trades);

        // Calculate overall metrics
        $metrics = $this->calculatePerformanceMetrics($trades);

        return new SimulationReport(
            totalTrades: $metrics['total_trades'],
            winningTrades: $metrics['winning_trades'],
            losingTrades: $metrics['losing_trades'],
            winRate: $metrics['win_rate'],
            expectedValue: $metrics['expected_value'],
            sharpeRatio: $metrics['sharpe_ratio'],
            maxDrawdown: $metrics['max_drawdown'],
            performanceStability: $metrics['performance_stability'],
            periodResults: $periodResults,
            isProfitable: $metrics['expected_value'] > 0
        );
    }

/**
      * Build a surface lookup map.
      *
      * @param  OpenCrossProbabilityResult  $surface  Probability surface
      * @return array<string, array<int, array{probability: float, confidence: string}>>
      */
    private function buildSurfaceMap(OpenCrossProbabilityResult $surface): array
    {
        $map = [];

        foreach ($surface->probabilitySurface as $point) {
            $bucketKey = sprintf('%.6f', $point->distanceBucket);
            $minutes = $point->minutesRemaining;

            if (! isset($map[$bucketKey])) {
                $map[$bucketKey] = [];
            }

            $map[$bucketKey][$minutes] = [
                'probability' => $point->crossProbability,
                'confidence' => $point->confidence,
            ];
        }

        return $map;
    }

    /**
     * Partition records into blocks.
     *
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float}>  $records  Records
     * @param  int  $blockMinutes  Block duration
     * @return array<int, array{records: array<int, array{timestamp: int, open: float, high: float, low: float, close: float, future_min_low: float, future_max_high: float}>, block_open: float, block_length: int, block_timestamp: int}>
     */
    private function partitionIntoBlocks(array $records, int $blockMinutes): array
    {
        $blocks = [];
        $currentBlock = [];
        $currentBlockStart = null;
        $blockSeconds = $blockMinutes * 60;

        foreach ($records as $record) {
            $timestamp = $record['timestamp'];
            $blockStart = (int) (floor($timestamp / $blockSeconds) * $blockSeconds);

            if ($currentBlockStart === null) {
                $currentBlockStart = $blockStart;
            }

            if ($blockStart !== $currentBlockStart) {
                if (count($currentBlock) > 0) {
                    $blocks[] = $this->finalizeBlock($currentBlock);
                }
                $currentBlock = [];
                $currentBlockStart = $blockStart;
            }

            $currentBlock[] = $record;
        }

        if (count($currentBlock) > 0) {
            $blocks[] = $this->finalizeBlock($currentBlock);
        }

        return $blocks;
    }

    /**
     * Finalize a block.
     *
     * @param  array<int, array{timestamp: int, open: float, high: float, low: float, close: float}>  $records  Block records
     * @return array{records: array<int, array{timestamp: int, open: float, high: float, low: float, close: float, future_min_low: float, future_max_high: float}>, block_open: float, block_length: int, block_timestamp: int}
     */
    private function finalizeBlock(array $records): array
    {
        $count = count($records);

        $futureMinLow = PHP_FLOAT_MAX;
        $futureMaxHigh = PHP_FLOAT_MIN;

        $finalizedRecords = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $tempMinLow = $futureMinLow;
            $tempMaxHigh = $futureMaxHigh;

            $currentLow = (float) $records[$i]['low'];
            $currentHigh = (float) $records[$i]['high'];

            $futureMinLow = min($futureMinLow, $currentLow);
            $futureMaxHigh = max($futureMaxHigh, $currentHigh);

            if ($i < $count - 1) {
                $finalizedRecords[$i] = [
                    'timestamp' => $records[$i]['timestamp'],
                    'open' => $records[$i]['open'],
                    'high' => $records[$i]['high'],
                    'low' => $records[$i]['low'],
                    'close' => $records[$i]['close'],
                    'future_min_low' => $tempMinLow,
                    'future_max_high' => $tempMaxHigh,
                ];
            } else {
                $finalizedRecords[$i] = [
                    'timestamp' => $records[$i]['timestamp'],
                    'open' => $records[$i]['open'],
                    'high' => $records[$i]['high'],
                    'low' => $records[$i]['low'],
                    'close' => $records[$i]['close'],
                    'future_min_low' => $currentLow,
                    'future_max_high' => $currentHigh,
                ];
            }
        }
        $finalizedRecords = array_values($finalizedRecords);

        return [
            'records' => $finalizedRecords,
            'block_open' => (float) $records[0]['open'],
            'block_length' => $count,
            'block_timestamp' => $records[0]['timestamp'],
        ];
    }

/**
      * Simulate trades for a single block.
      *
      * @param  array{records: array<int, array{timestamp: int, open: float, high: float, low: float, close: float, future_min_low: float, future_max_high: float}>, block_open: float, block_length: int, block_timestamp: int}  $blockData  Block data
      * @param  array<string, array<int, array{probability: float, confidence: string|float}>>  $surfaceMap  Surface lookup map
      * @param  ValidationConfig  $config  Configuration
      * @return array<int, array{timestamp: int, entry_price: float, exit_price: float, pnl: float, entry_distance: float}> Trades
      */
    private function simulateBlock(
        array $blockData,
        array $surfaceMap,
        ValidationConfig $config
    ): array {
        $trades = [];
        $records = $blockData['records'];
        $blockOpen = $blockData['block_open'];
        $blockLength = $blockData['block_length'];
        $blockTimestamp = $blockData['block_timestamp'];

        $inPosition = false;
        $entryPrice = 0.0;
        $entryDistance = 0.0;

        foreach ($records as $index => $record) {
            if ($index >= $blockLength - 1) {
                // Force close at end of block
                if ($inPosition) {
                    $exitPrice = (float) $record['close'];
                    $pnl = $this->calculatePnL($entryDistance, $entryPrice, $exitPrice);

                    $trades[] = [
                        'timestamp' => $blockTimestamp,
                        'entry_price' => $entryPrice,
                        'exit_price' => $exitPrice,
                        'pnl' => $pnl,
                        'entry_distance' => $entryDistance,
                    ];

                    $inPosition = false;
                }

                continue;
            }

            $price = (float) $record['close'];
            $distance = $blockOpen > 0 ? ($price - $blockOpen) / $blockOpen : 0.0;

            $bucketKey = sprintf('%.6f', floor($distance / $config->bucketSize) * $config->bucketSize);
            $minutesRemaining = $blockLength - 1 - $index;

            // Get probability from surface
            $probability = null;
            if (isset($surfaceMap[$bucketKey][$minutesRemaining])) {
                $probability = $surfaceMap[$bucketKey][$minutesRemaining]['probability'];
            }

            if (! $inPosition && $probability !== null) {
                // Entry signal: P(cross) < threshold means price is unlikely to cross back
                // So we bet on continuation (trend following)
                // Or P(cross) > (1 - threshold) means price is likely to cross back
                // So we bet on mean reversion

                if ($probability < (1 - $config->simulationThreshold)) {
                    // Low cross probability: trend following
                    // If above open, bet on staying above; if below, bet on staying below
                    $inPosition = true;
                    $entryPrice = $price;
                    $entryDistance = $distance;
                } elseif ($probability > $config->simulationThreshold) {
                    // High cross probability: mean reversion
                    // Bet on price returning to open
                    $inPosition = true;
                    $entryPrice = $price;
                    $entryDistance = $distance;
                }
            } elseif ($inPosition) {
                // Check exit conditions
                $shouldExit = $this->shouldExitPosition(
                    $distance,
                    $entryDistance,
                    $probability,
                    $config,
                    $record,
                    $blockOpen
                );

                if ($shouldExit) {
                    $exitPrice = $price;
                    $pnl = $this->calculatePnL($entryDistance, $entryPrice, $exitPrice);

                    $trades[] = [
                        'timestamp' => $blockTimestamp,
                        'entry_price' => $entryPrice,
                        'exit_price' => $exitPrice,
                        'pnl' => $pnl,
                        'entry_distance' => $entryDistance,
                    ];

                    $inPosition = false;
                }
            }
        }

        return $trades;
    }

    /**
     * Determine if we should exit a position.
     *
     * @param  float  $currentDistance  Current distance from open
     * @param  float  $entryDistance  Entry distance
     * @param  float|null  $probability  Current probability
     * @param  ValidationConfig  $config  Configuration
     * @param  array{timestamp: int, open: float, high: float, low: float, close: float}  $record  Current record
     * @param  float  $blockOpen  Block open price
     */
    private function shouldExitPosition(
        float $currentDistance,
        float $entryDistance,
        ?float $probability,
        ValidationConfig $config,
        array $record,
        float $blockOpen
    ): bool {
        // Exit if probability has flipped significantly
        if ($probability !== null) {
            // If we entered on high cross probability and it's now low, exit
            if ($entryDistance > 0 && $probability < 0.3) {
                return true;
            }
            if ($entryDistance < 0 && $probability > 0.7) {
                return true;
            }
        }

        // Exit if we've crossed the open (target reached for mean reversion)
        if ($entryDistance > 0 && $currentDistance <= 0) {
            return true;
        }
        if ($entryDistance < 0 && $currentDistance >= 0) {
            return true;
        }

        // Exit on stop loss (2% against position)
        $price = (float) $record['close'];
        $priceMove = abs($currentDistance - $entryDistance);

        if ($priceMove > 0.02) {
            return true;
        }

        return false;
    }

    /**
     * Calculate P&L for a trade.
     *
     * @param  float  $entryDistance  Entry distance from open
     * @param  float  $entryPrice  Entry price
     * @param  float  $exitPrice  Exit price
     * @return float P&L as percentage
     */
    private function calculatePnL(float $entryDistance, float $entryPrice, float $exitPrice): float
    {
        if ($entryPrice <= 0) {
            return 0.0;
        }

        // Direction based on strategy
        // If we entered above open (positive distance) with low cross prob, we're long
        // If we entered below open (negative distance) with low cross prob, we're short
        // For mean reversion, we bet on return to open

        if ($entryDistance > 0) {
            // Long position
            return ($exitPrice - $entryPrice) / $entryPrice;
        } else {
            // Short position
            return ($entryPrice - $exitPrice) / $entryPrice;
        }
    }

    /**
     * Calculate performance metrics.
     *
     * @param  array<int, array{timestamp: int, entry_price: float, exit_price: float, pnl: float, entry_distance: float}>  $trades  All trades
     * @return array{total_trades: int, winning_trades: int, losing_trades: int, win_rate: float, expected_value: float, sharpe_ratio: float, max_drawdown: float, performance_stability: float}
     */
    private function calculatePerformanceMetrics(array $trades): array
    {
        if (empty($trades)) {
            return [
                'total_trades' => 0,
                'winning_trades' => 0,
                'losing_trades' => 0,
                'win_rate' => 0.0,
                'expected_value' => 0.0,
                'sharpe_ratio' => 0.0,
                'max_drawdown' => 0.0,
                'performance_stability' => 0.0,
            ];
        }

        $pnls = array_column($trades, 'pnl');
        $winningTrades = count(array_filter($pnls, fn ($pnl) => $pnl > 0));
        $losingTrades = count(array_filter($pnls, fn ($pnl) => $pnl < 0));

        $winRate = $winningTrades / count($trades);
        $expectedValue = array_sum($pnls) / count($trades);

        // Sharpe ratio (simplified, assuming zero risk-free rate)
        $meanPnl = $expectedValue;
        $variance = 0.0;

        foreach ($pnls as $pnl) {
            $variance += ($pnl - $meanPnl) ** 2;
        }

        $stdDev = count($pnls) > 1 ? sqrt($variance / (count($pnls) - 1)) : 0.0;
        $sharpeRatio = $stdDev > 0 ? $meanPnl / $stdDev : 0.0;

        // Maximum drawdown
        $cumulativeReturn = 1.0;
        $peak = 1.0;
        $maxDrawdown = 0.0;

        foreach ($pnls as $pnl) {
            $cumulativeReturn *= (1 + $pnl);
            $peak = max($peak, $cumulativeReturn);
            $drawdown = ($peak - $cumulativeReturn) / $peak;
            $maxDrawdown = max($maxDrawdown, $drawdown);
        }

        // Performance stability (based on consistency of returns)
        $positivePeriods = 0;
        $totalPeriods = 0;
        $periodReturns = [];

        foreach ($trades as $trade) {
            $date = date('Y-m-d', $trade['timestamp']);
            if (! isset($periodReturns[$date])) {
                $periodReturns[$date] = 0.0;
            }
            $periodReturns[$date] += $trade['pnl'];
        }

        foreach ($periodReturns as $return) {
            $totalPeriods++;
            if ($return > 0) {
                $positivePeriods++;
            }
        }

        $performanceStability = $totalPeriods > 0 ? $positivePeriods / $totalPeriods : 0.0;

        return [
            'total_trades' => count($trades),
            'winning_trades' => $winningTrades,
            'losing_trades' => $losingTrades,
            'win_rate' => $winRate,
            'expected_value' => $expectedValue,
            'sharpe_ratio' => $sharpeRatio,
            'max_drawdown' => $maxDrawdown,
            'performance_stability' => $performanceStability,
        ];
    }

    /**
     * Calculate results by period.
     *
     * @param  array<int, array{timestamp: int, entry_price: float, exit_price: float, pnl: float, entry_distance: float}>  $trades  All trades
     * @return array<string, array{trades: int, wins: int, total_pnl: float, win_rate: float, avg_pnl: float}>
     */
    private function calculatePeriodResults(array $trades): array
    {
        $periodResults = [];

        foreach ($trades as $trade) {
            $month = date('Y-m', $trade['timestamp']);

            if (! isset($periodResults[$month])) {
                $periodResults[$month] = [
                    'trades' => 0,
                    'wins' => 0,
                    'total_pnl' => 0.0,
                ];
            }

            $periodResults[$month]['trades']++;
            $periodResults[$month]['total_pnl'] += $trade['pnl'];

            if ($trade['pnl'] > 0) {
                $periodResults[$month]['wins']++;
            }
        }

        // Calculate per-period metrics
        foreach ($periodResults as $month => $data) {
            $periodResults[$month]['win_rate'] = $data['trades'] > 0 ? $data['wins'] / $data['trades'] : 0.0;
            $periodResults[$month]['avg_pnl'] = $data['trades'] > 0 ? $data['total_pnl'] / $data['trades'] : 0.0;
        }

        return $periodResults;
    }
}