<?php

namespace App\AlphaForge\Analysis\Engine\Validation;

use App\AlphaForge\Analysis\Config\ValidationConfig;
use App\AlphaForge\Analysis\Dto\OpenCrossProbabilityResult;
use App\AlphaForge\Analysis\Dto\Validation\SimulationReport;
use App\AlphaForge\Backtesting\Service\SeriesMetricServiceInterface;

/**
 * Simulates a simple trading strategy based on probability estimates.
 *
 * Statistics calculations are delegated to SeriesMetricServiceInterface
 * to avoid duplicating Backtesting module logic.
 */
final class StrategySimulator
{
    public function __construct(
        private readonly SeriesMetricServiceInterface $seriesMetricService,
    ) {}

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
        $surfaceMap = $this->buildSurfaceMap($surface);

        $blocks = $this->partitionIntoBlocks($testRecords, $config->blockMinutes);

        $trades = [];

        foreach ($blocks as $blockData) {
            $blockTrades = $this->simulateBlock($blockData, $surfaceMap, $config);
            $trades = array_merge($trades, $blockTrades);
        }

        $periodResults = $this->calculatePeriodResults($trades);

        $pnls = array_column($trades, 'pnl');
        $wlStats = $this->seriesMetricService->tradeWinLossStats($pnls);

        return new SimulationReport(
            totalTrades: $wlStats['total_trades'],
            winningTrades: $wlStats['winning_trades'],
            losingTrades: $wlStats['losing_trades'],
            winRate: $wlStats['win_rate'],
            expectedValue: $wlStats['expected_value'],
            sharpeRatio: $this->seriesMetricService->sharpeRatioFromReturns($pnls),
            sortinoRatio: $this->seriesMetricService->sortinoRatioFromReturns($pnls),
            maxDrawdown: $this->seriesMetricService->maxDrawdownFromReturns($pnls),
            performanceStability: $this->seriesMetricService->performanceStabilityFromTrades($trades),
            periodResults: $periodResults,
            isProfitable: $wlStats['expected_value'] > 0
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

            $probability = null;
            if (isset($surfaceMap[$bucketKey][$minutesRemaining])) {
                $probability = $surfaceMap[$bucketKey][$minutesRemaining]['probability'];
            }

            if (! $inPosition && $probability !== null) {
                if ($probability < (1 - $config->simulationThreshold)) {
                    $inPosition = true;
                    $entryPrice = $price;
                    $entryDistance = $distance;
                } elseif ($probability > $config->simulationThreshold) {
                    $inPosition = true;
                    $entryPrice = $price;
                    $entryDistance = $distance;
                }
            } elseif ($inPosition) {
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
        if ($probability !== null) {
            if ($entryDistance > 0 && $probability < 0.3) {
                return true;
            }
            if ($entryDistance < 0 && $probability > 0.7) {
                return true;
            }
        }

        if ($entryDistance > 0 && $currentDistance <= 0) {
            return true;
        }
        if ($entryDistance < 0 && $currentDistance >= 0) {
            return true;
        }

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

        if ($entryDistance > 0) {
            return ($exitPrice - $entryPrice) / $entryPrice;
        }

        return ($entryPrice - $exitPrice) / $entryPrice;
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

        foreach ($periodResults as $month => $data) {
            $periodResults[$month]['win_rate'] = $data['trades'] > 0 ? $data['wins'] / $data['trades'] : 0.0;
            $periodResults[$month]['avg_pnl'] = $data['trades'] > 0 ? $data['total_pnl'] / $data['trades'] : 0.0;
        }

        return $periodResults;
    }
}
