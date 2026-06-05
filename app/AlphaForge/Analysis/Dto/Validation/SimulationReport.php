<?php

namespace App\AlphaForge\Analysis\Dto\Validation;

/**
 * Represents the strategy-level simulation test report.
 */
final readonly class SimulationReport
{
    /**
     * @param  int  $totalTrades  Total number of trades simulated
     * @param  int  $winningTrades  Number of winning trades
     * @param  int  $losingTrades  Number of losing trades
     * @param  float  $winRate  Proportion of winning trades
     * @param  float  $expectedValue  Expected value per trade
     * @param  float  $sharpeRatio  Sharpe ratio of returns
     * @param  float  $maxDrawdown  Maximum drawdown
     * @param  float  $performanceStability  Stability score across time splits
     * @param  array  $periodResults  Performance metrics by period
     * @param  bool  $isProfitable  Whether the strategy is profitable out-of-sample
     */
    public function __construct(
        public int $totalTrades,
        public int $winningTrades,
        public int $losingTrades,
        public float $winRate,
        public float $expectedValue,
        public float $sharpeRatio,
        public float $maxDrawdown,
        public float $performanceStability,
        public array $periodResults,
        public bool $isProfitable
    ) {}

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'total_trades' => $this->totalTrades,
            'winning_trades' => $this->winningTrades,
            'losing_trades' => $this->losingTrades,
            'win_rate' => round($this->winRate, 4),
            'expected_value' => round($this->expectedValue, 6),
            'sharpe_ratio' => round($this->sharpeRatio, 4),
            'max_drawdown' => round($this->maxDrawdown, 4),
            'performance_stability' => round($this->performanceStability, 4),
            'period_results' => $this->periodResults,
            'is_profitable' => $this->isProfitable,
        ];
    }
}
