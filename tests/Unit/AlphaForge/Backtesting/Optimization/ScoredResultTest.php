<?php

use App\AlphaForge\Backtesting\Optimization\ScoredResult;

describe('ScoredResult', function () {
    it('stores parameters, statistics, and score', function () {
        $result = new ScoredResult(
            parameters: ['fastPeriod' => 10, 'slowPeriod' => 30],
            statistics: ['sharpe_ratio' => '1.5', 'win_rate' => '0.6'],
            score: 1.23,
        );

        expect($result->parameters)->toBe(['fastPeriod' => 10, 'slowPeriod' => 30])
            ->and($result->statistics)->toBe(['sharpe_ratio' => '1.5', 'win_rate' => '0.6'])
            ->and($result->score)->toBe(1.23);
    });

    it('converts to array', function () {
        $result = new ScoredResult(
            parameters: ['fastPeriod' => 10],
            statistics: ['sharpe_ratio' => '1.5'],
            score: 1.5,
        );

        $arr = $result->toArray();
        expect($arr)->toHaveKeys(['parameters', 'statistics', 'score'])
            ->and($arr['parameters'])->toBe(['fastPeriod' => 10])
            ->and($arr['score'])->toBe(1.5);
    });
});
