<?php

use App\AlphaForge\Backtesting\Optimization\Objective\CompositeObjective;
use App\AlphaForge\Backtesting\Optimization\Objective\ObjectiveFactory;
use App\AlphaForge\Backtesting\Optimization\Objective\ObjectivePresets;
use App\AlphaForge\Backtesting\Optimization\Objective\SingleMetricObjective;

describe('ObjectiveFactory', function () {
    it('creates single metric objective from string', function () {
        $objective = ObjectiveFactory::create('sharpe_ratio');

        expect($objective)->toBeInstanceOf(SingleMetricObjective::class)
            ->and($objective->label())->toBe('sharpe_ratio');
    });

    it('creates preset objective from known name', function () {
        $objective = ObjectiveFactory::create('balanced');

        expect($objective)->toBeInstanceOf(CompositeObjective::class)
            ->and($objective->label())->toBe('balanced');
    });

    it('creates sharpe_focused preset', function () {
        $objective = ObjectiveFactory::create('sharpe_focused');

        expect($objective)->toBeInstanceOf(CompositeObjective::class)
            ->and($objective->label())->toBe('sharpe_focused');
    });

    it('creates conservative preset', function () {
        $objective = ObjectiveFactory::create('conservative');

        expect($objective)->toBeInstanceOf(CompositeObjective::class)
            ->and($objective->label())->toBe('conservative');
    });

    it('creates aggressive preset', function () {
        $objective = ObjectiveFactory::create('aggressive');

        expect($objective)->toBeInstanceOf(CompositeObjective::class)
            ->and($objective->label())->toBe('aggressive');
    });

    it('returns objective interface directly when passed one', function () {
        $original = new SingleMetricObjective('win_rate');
        $returned = ObjectiveFactory::create($original);

        expect($returned)->toBe($original);
    });
});

describe('ObjectivePresets', function () {
    it('returns all presets', function () {
        $presets = ObjectivePresets::all();

        expect($presets)->toHaveKeys(['sharpe_focused', 'balanced', 'conservative', 'aggressive']);
    });

    it('balanced preset computes score correctly', function () {
        $objective = ObjectivePresets::balanced();

        $stats = [
            'total_return_percent' => '100',
            'max_drawdown_percent' => '20',
            'sharpe_ratio' => '2.0',
            'win_rate' => '0.6',
        ];

        $expected = 1.0 * 100 + (-0.5) * 20 + 10.0 * 2.0 + 0.5 * 0.6;
        expect($objective->score($stats))->toBe($expected);
    });

    it('conservative preset penalizes drawdown heavily', function () {
        $objective = ObjectivePresets::conservative();

        $lowDrawdown = ['max_drawdown_percent' => '5', 'profit_factor' => '1.5', 'sortino_ratio' => '2.0'];
        $highDrawdown = ['max_drawdown_percent' => '40', 'profit_factor' => '2.0', 'sortino_ratio' => '2.5'];

        expect($objective->score($lowDrawdown))->toBeGreaterThan($objective->score($highDrawdown));
    });
});
