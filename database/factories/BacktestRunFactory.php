<?php

namespace Database\Factories;

use App\AlphaForge\Backtesting\Model\BacktestRun;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

class BacktestRunFactory extends Factory
{
    protected $model = BacktestRun::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'strategy_alias' => $this->faker->randomElement(['sma_crossover', 'rsi_reversal', 'macd_trend']),
            'symbols' => ['BTCUSDT'],
            'timeframe' => TimeframeEnum::H1->value,
            'execution_timeframe' => null,
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'stake_currency' => 'USDT',
            'strategy_inputs' => [],
            'commission_config' => [
                'type' => 'percentage',
                'rate' => '0.1',
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
            'final_capital' => bcadd($attributes['initial_capital'] ?? '10000', '5000', 8),
            'statistics' => [
                'total_return' => '5000',
                'total_return_percent' => '50',
                'win_rate' => '0.65',
                'sharpe_ratio' => '1.5',
                'max_drawdown' => '1500',
            ],
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => 'Strategy execution failed',
            'started_at' => now()->subMinutes(30),
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
