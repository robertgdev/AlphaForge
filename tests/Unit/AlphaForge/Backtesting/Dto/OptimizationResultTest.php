<?php

use App\AlphaForge\Backtesting\Dto\OptimizationResult;

describe('OptimizationResult', function () {
    it('creates with required parameters', function () {
        $result = new OptimizationResult(
            parameters: ['fastPeriod' => 10, 'slowPeriod' => 20],
            statistics: ['sharpe_ratio' => '1.5', 'total_return' => '0.45'],
        );

        expect($result->parameters)->toBe(['fastPeriod' => 10, 'slowPeriod' => 20])
            ->and($result->statistics)->toBe(['sharpe_ratio' => '1.5', 'total_return' => '0.45'])
            ->and($result->rank)->toBe(0)
            ->and($result->backtestRunId)->toBeNull();
    });

    it('creates with all parameters', function () {
        $result = new OptimizationResult(
            parameters: ['fastPeriod' => 10],
            statistics: ['sharpe_ratio' => '1.5'],
            rank: 3,
            backtestRunId: 'run-uuid-123',
        );

        expect($result->rank)->toBe(3)
            ->and($result->backtestRunId)->toBe('run-uuid-123');
    });

    describe('getMetricValue', function () {
        it('returns metric value from statistics', function () {
            $result = new OptimizationResult(
                parameters: ['fastPeriod' => 10],
                statistics: ['sharpe_ratio' => '1.5', 'total_return' => '0.45'],
            );

            expect($result->getMetricValue('sharpe_ratio'))->toBe('1.5')
                ->and($result->getMetricValue('total_return'))->toBe('0.45');
        });

        it('returns zero for missing metric', function () {
            $result = new OptimizationResult(
                parameters: ['fastPeriod' => 10],
                statistics: ['sharpe_ratio' => '1.5'],
            );

            expect($result->getMetricValue('unknown_metric'))->toBe('0');
        });

        it('returns zero for empty statistics', function () {
            $result = new OptimizationResult(
                parameters: [],
                statistics: [],
            );

            expect($result->getMetricValue('sharpe_ratio'))->toBe('0');
        });
    });

    describe('toArray', function () {
        it('converts to array representation', function () {
            $result = new OptimizationResult(
                parameters: ['fastPeriod' => 10],
                statistics: ['sharpe_ratio' => '1.5'],
                rank: 2,
                backtestRunId: 'run-123',
            );

            $array = $result->toArray();

            expect($array)->toBe([
                'parameters' => ['fastPeriod' => 10],
                'statistics' => ['sharpe_ratio' => '1.5'],
                'rank' => 2,
                'backtest_run_id' => 'run-123',
            ]);
        });

        it('includes null backtest_run_id', function () {
            $result = new OptimizationResult(
                parameters: [],
                statistics: [],
            );

            expect($result->toArray()['backtest_run_id'])->toBeNull();
        });
    });
});
