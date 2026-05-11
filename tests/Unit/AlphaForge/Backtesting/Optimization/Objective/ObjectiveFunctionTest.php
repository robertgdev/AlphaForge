<?php

use App\AlphaForge\Backtesting\Optimization\Objective\CompositeObjective;
use App\AlphaForge\Backtesting\Optimization\Objective\ObjectiveWeight;
use App\AlphaForge\Backtesting\Optimization\Objective\SingleMetricObjective;

describe('SingleMetricObjective', function () {
    it('scores by a single metric', function () {
        $objective = new SingleMetricObjective('sharpe_ratio');

        expect($objective->score(['sharpe_ratio' => '1.5']))->toBe(1.5);
    });

    it('returns 0 for missing metric', function () {
        $objective = new SingleMetricObjective('sharpe_ratio');

        expect($objective->score([]))->toBe(0.0);
    });

    it('negates drawdown metrics (lower is better)', function () {
        $objective = new SingleMetricObjective('max_drawdown_percent');

        expect($objective->score(['max_drawdown_percent' => '0.15']))->toBe(-0.15);
    });

    it('negates metrics containing drawdown', function () {
        $objective = new SingleMetricObjective('max_drawdown');

        expect($objective->score(['max_drawdown' => '500']))->toBe(-500.0);
    });

    it('returns metric name as label', function () {
        $objective = new SingleMetricObjective('sharpe_ratio');

        expect($objective->label())->toBe('sharpe_ratio');
    });

    it('handles numeric string values', function () {
        $objective = new SingleMetricObjective('win_rate');

        expect($objective->score(['win_rate' => '0.65']))->toBe(0.65);
    });
});

describe('CompositeObjective', function () {
    it('computes weighted sum of metrics', function () {
        $objective = new CompositeObjective([
            new ObjectiveWeight('sharpe_ratio', 1.0),
            new ObjectiveWeight('max_drawdown_percent', -0.5),
        ]);

        $score = $objective->score([
            'sharpe_ratio' => '2.0',
            'max_drawdown_percent' => '0.1',
        ]);

        expect($score)->toBe(2.0 + (-0.5 * 0.1));
    });

    it('treats missing metrics as zero', function () {
        $objective = new CompositeObjective([
            new ObjectiveWeight('sharpe_ratio', 1.0),
            new ObjectiveWeight('missing_metric', 2.0),
        ]);

        $score = $objective->score(['sharpe_ratio' => '1.5']);

        expect($score)->toBe(1.5);
    });

    it('returns custom label', function () {
        $objective = new CompositeObjective([], 'my_custom');

        expect($objective->label())->toBe('my_custom');
    });

    it('defaults label to composite', function () {
        $objective = new CompositeObjective([]);

        expect($objective->label())->toBe('composite');
    });
});
