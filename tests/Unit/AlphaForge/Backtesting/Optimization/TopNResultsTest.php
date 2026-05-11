<?php

use App\AlphaForge\Backtesting\Optimization\Objective\ObjectivePresets;
use App\AlphaForge\Backtesting\Optimization\Objective\SingleMetricObjective;
use App\AlphaForge\Backtesting\Optimization\TopNResults;

describe('TopNResults', function () {
    it('keeps top N results by score', function () {
        $objective = new SingleMetricObjective('sharpe_ratio');
        $topN = new TopNResults(3, $objective);

        $topN->consider(['a' => 1], ['sharpe_ratio' => '0.5'], 0.5);
        $topN->consider(['a' => 2], ['sharpe_ratio' => '1.5'], 1.5);
        $topN->consider(['a' => 3], ['sharpe_ratio' => '1.0'], 1.0);
        $topN->consider(['a' => 4], ['sharpe_ratio' => '2.0'], 2.0);
        $topN->consider(['a' => 5], ['sharpe_ratio' => '0.8'], 0.8);

        $ranked = $topN->ranked();

        expect($ranked)->toHaveCount(3)
            ->and($ranked[0]->score)->toBe(2.0)
            ->and($ranked[1]->score)->toBe(1.5)
            ->and($ranked[2]->score)->toBe(1.0);
    });

    it('returns fewer results if less than N were added', function () {
        $objective = new SingleMetricObjective('sharpe_ratio');
        $topN = new TopNResults(10, $objective);

        $topN->consider(['a' => 1], ['sharpe_ratio' => '0.5'], 0.5);
        $topN->consider(['a' => 2], ['sharpe_ratio' => '1.5'], 1.5);

        $ranked = $topN->ranked();

        expect($ranked)->toHaveCount(2);
    });

    it('counts total results considered', function () {
        $objective = new SingleMetricObjective('sharpe_ratio');
        $topN = new TopNResults(3, $objective);

        $topN->consider(['a' => 1], ['sharpe_ratio' => '0.5'], 0.5);
        $topN->consider(['a' => 2], ['sharpe_ratio' => '1.5'], 1.5);
        $topN->consider(['a' => 3], ['sharpe_ratio' => '1.0'], 1.0);

        expect($topN->count())->toBe(3);
    });

    it('works with composite objective', function () {
        $objective = ObjectivePresets::balanced();
        $topN = new TopNResults(2, $objective);

        $topN->consider(
            ['fast' => 10],
            ['total_return_percent' => '50', 'max_drawdown_percent' => '10', 'sharpe_ratio' => '2.0', 'win_rate' => '0.6'],
            $objective->score(['total_return_percent' => '50', 'max_drawdown_percent' => '10', 'sharpe_ratio' => '2.0', 'win_rate' => '0.6']),
        );
        $topN->consider(
            ['fast' => 20],
            ['total_return_percent' => '30', 'max_drawdown_percent' => '5', 'sharpe_ratio' => '1.5', 'win_rate' => '0.7'],
            $objective->score(['total_return_percent' => '30', 'max_drawdown_percent' => '5', 'sharpe_ratio' => '1.5', 'win_rate' => '0.7']),
        );

        $ranked = $topN->ranked();
        expect($ranked)->toHaveCount(2)
            ->and($ranked[0]->score)->toBeGreaterThan($ranked[1]->score);
    });
});
