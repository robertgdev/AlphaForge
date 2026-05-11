<?php

use App\AlphaForge\Backtesting\Optimization\ParameterDimension;

describe('ParameterDimension', function () {
    it('generates values for integer dimension', function () {
        $dim = new ParameterDimension('fastPeriod', 5, 15, 5, 'int');

        expect($dim->values())->toBe([5, 10, 15]);
    });

    it('generates values for float dimension', function () {
        $dim = new ParameterDimension('threshold', 0.0, 2.0, 1.0, 'float');

        $values = $dim->values();
        expect($values)->toBe([0.0, 1.0, 2.0]);
        foreach ($values as $v) {
            expect($v)->toBeFloat();
        }
    });

    it('generates single value when min equals max', function () {
        $dim = new ParameterDimension('period', 14, 14, 1, 'int');

        expect($dim->values())->toBe([14]);
    });

    it('defaults step to 1', function () {
        $dim = new ParameterDimension('period', 1, 3);

        expect($dim->values())->toBe([1, 2, 3]);
    });

    it('defaults type to int', function () {
        $dim = new ParameterDimension('period', 1, 3);

        expect($dim->type)->toBe('int');
        foreach ($dim->values() as $v) {
            expect($v)->toBeInt();
        }
    });

    it('returns random value from valid set', function () {
        $dim = new ParameterDimension('fastPeriod', 5, 50, 5, 'int');

        for ($i = 0; $i < 20; $i++) {
            $val = $dim->randomValue();
            expect($val)->toBeInt();
            expect($val)->toBeGreaterThanOrEqual(5);
            expect($val)->toBeLessThanOrEqual(50);
            expect($val % 5)->toBe(0);
        }
    });

    it('clamps values to min/max bounds', function () {
        $dim = new ParameterDimension('fastPeriod', 5, 50, 5, 'int');

        expect($dim->clamp(3))->toBe(5);
        expect($dim->clamp(55))->toBe(50);
        expect($dim->clamp(25))->toBe(25);
    });

    it('clamps float values', function () {
        $dim = new ParameterDimension('rate', 0.5, 10.0, 0.5, 'float');

        expect($dim->clamp(-1.0))->toBe(0.5);
        expect($dim->clamp(15.0))->toBe(10.0);
        expect($dim->clamp(5.0))->toBe(5.0);
    });

    it('counts values correctly', function () {
        $dim = new ParameterDimension('fastPeriod', 5, 15, 5, 'int');

        expect($dim->count())->toBe(3);
    });

    it('handles fractional step values', function () {
        $dim = new ParameterDimension('stopLoss', 0.5, 2.0, 0.5, 'float');

        expect($dim->values())->toBe([0.5, 1.0, 1.5, 2.0]);
        expect($dim->count())->toBe(4);
    });
});
