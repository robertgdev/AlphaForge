<?php

namespace Database\Factories\AlphaForge\Backtesting\Model;

use App\AlphaForge\Backtesting\Model\BacktestRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BacktestRun>
 */
class BacktestRunFactory extends Factory
{
    protected $model = BacktestRun::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'strategy_alias' => 'SampleStrategy',
            'symbols' => ['BTC/USDT', 'ETH/USDT'],
            'timeframe' => '1h',
            'execution_timeframe' => null,
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'stake_currency' => 'USDT',
            'strategy_inputs' => [],
            'commission_config' => [
                'maker' => '0.001',
                'taker' => '0.001',
            ],
            'start_date' => now()->subYear(),
            'end_date' => now(),
            'status' => 'pending',
            'final_capital' => null,
            'statistics' => null,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'final_capital' => '12500.50000000',
            'statistics' => [
                'totalReturn' => 25.0,
                'annualizedReturn' => 25.0,
                'maxDrawdown' => 5.5,
                'sharpeRatio' => 1.8,
                'winRate' => 60.0,
                'totalTrades' => 100,
                'profitFactor' => 1.5,
            ],
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => 'Data file not found for BTC/USDT',
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
        ]);
    }

    public function withExecutionTimeframe(string $executionTimeframe = '1m'): static
    {
        return $this->state(fn (array $attributes) => [
            'execution_timeframe' => $executionTimeframe,
        ]);
    }
}
