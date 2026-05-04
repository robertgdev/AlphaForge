<?php

namespace Database\Factories\Stochastix\Backtesting\Model;

use App\Models\User;
use App\Stochastix\Backtesting\Model\BacktestRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BacktestRun>
 */
class BacktestRunFactory extends Factory
{
    protected $model = BacktestRun::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
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

    /**
     * Indicate that the backtest is running.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /**
     * Indicate that the backtest is completed.
     */
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

    /**
     * Indicate that the backtest has failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => 'Data file not found for BTC/USDT',
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the backtest uses dual-timeframe mode.
     */
    public function withExecutionTimeframe(string $executionTimeframe = '1m'): static
    {
        return $this->state(fn (array $attributes) => [
            'execution_timeframe' => $executionTimeframe,
        ]);
    }
}
