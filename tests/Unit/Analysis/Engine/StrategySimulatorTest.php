<?php

use App\AlphaForge\Analysis\Engine\Validation\StrategySimulator;

describe('StrategySimulator', function () {
    beforeEach(function () {
        $this->simulator = new StrategySimulator;
    });

    describe('calculatePerformanceMetrics', function () {
        it('returns zero sharpe and sortino for empty trades', function () {
            $reflection = new ReflectionMethod(StrategySimulator::class, 'calculatePerformanceMetrics');

            $metrics = $reflection->invoke($this->simulator, []);

            expect($metrics['total_trades'])->toBe(0)
                ->and($metrics['sharpe_ratio'])->toBe(0.0)
                ->and($metrics['sortino_ratio'])->toBe(0.0);
        });

        it('returns non-zero sharpe and sortino with mixed trades', function () {
            $reflection = new ReflectionMethod(StrategySimulator::class, 'calculatePerformanceMetrics');

            $trades = [];
            for ($i = 0; $i < 20; $i++) {
                $trades[] = [
                    'timestamp' => $i,
                    'entry_price' => 100.0,
                    'exit_price' => 105.0,
                    'pnl' => $i % 3 === 0 ? -0.01 - ($i * 0.001) : 0.05,
                    'entry_distance' => 0.01,
                ];
            }

            $metrics = $reflection->invoke($this->simulator, $trades);

            expect($metrics['sharpe_ratio'])->toBeGreaterThan(0)
                ->and($metrics['sortino_ratio'])->toBeGreaterThan(0);
        });

        it('returns zero sortino when no downside returns', function () {
            $reflection = new ReflectionMethod(StrategySimulator::class, 'calculatePerformanceMetrics');

            $trades = [];
            for ($i = 0; $i < 20; $i++) {
                $trades[] = [
                    'timestamp' => $i,
                    'entry_price' => 100.0,
                    'exit_price' => 105.0,
                    'pnl' => 0.03 + ($i * 0.001),
                    'entry_distance' => 0.01,
                ];
            }

            $metrics = $reflection->invoke($this->simulator, $trades);

            expect($metrics['sharpe_ratio'])->toBeGreaterThan(0)
                ->and($metrics['sortino_ratio'])->toBe(0.0);
        });

        it('returns different sharpe and sortino with mixed returns', function () {
            $reflection = new ReflectionMethod(StrategySimulator::class, 'calculatePerformanceMetrics');

            $trades = [];
            for ($i = 0; $i < 30; $i++) {
                $trades[] = [
                    'timestamp' => $i,
                    'entry_price' => 100.0,
                    'exit_price' => 105.0,
                    'pnl' => $i % 2 === 0 ? 0.06 : -0.01 - ($i * 0.0005),
                    'entry_distance' => 0.01,
                ];
            }

            $metrics = $reflection->invoke($this->simulator, $trades);

            expect($metrics['sharpe_ratio'])->toBeGreaterThan(0)
                ->and($metrics['sortino_ratio'])->toBeGreaterThan(0)
                ->and($metrics['sortino_ratio'])->not->toEqual($metrics['sharpe_ratio']);
        });
    });
});
