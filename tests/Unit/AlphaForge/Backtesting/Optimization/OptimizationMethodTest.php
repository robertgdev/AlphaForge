<?php

use App\AlphaForge\Backtesting\Optimization\OptimizationMethod;

describe('OptimizationMethod', function () {
    it('has grid method', function () {
        expect(OptimizationMethod::GRID->value)->toBe('grid');
    });

    it('has random method', function () {
        expect(OptimizationMethod::RANDOM->value)->toBe('random');
    });

    it('has genetic method', function () {
        expect(OptimizationMethod::GENETIC->value)->toBe('genetic');
    });

    it('creates from string value', function () {
        expect(OptimizationMethod::from('grid'))->toBe(OptimizationMethod::GRID)
            ->and(OptimizationMethod::from('random'))->toBe(OptimizationMethod::RANDOM)
            ->and(OptimizationMethod::from('genetic'))->toBe(OptimizationMethod::GENETIC);
    });

    it('returns all cases', function () {
        expect(OptimizationMethod::cases())->toHaveCount(3);
    });
});
