<?php

use App\AlphaForge\Backtesting\Optimization\Generator\RandomGenerator;
use App\AlphaForge\Backtesting\Optimization\ParameterSpace;

describe('RandomGenerator', function () {
    it('generates specified number of iterations', function () {
        $space = ParameterSpace::fromArray([
            'fastPeriod' => ['min' => 5, 'max' => 50, 'step' => 5],
            'slowPeriod' => ['min' => 20, 'max' => 200, 'step' => 10],
        ]);

        $generator = new RandomGenerator;
        $generator->initialize($space, 100);

        $count = 0;
        while ($generator->next() !== null) {
            $count++;
        }

        expect($count)->toBe(100);
    });

    it('defaults to 500 iterations', function () {
        $space = ParameterSpace::fromArray([
            'fastPeriod' => ['min' => 5, 'max' => 50, 'step' => 5],
        ]);

        $generator = new RandomGenerator;
        $generator->initialize($space);

        expect($generator->totalIterations())->toBe(500);
    });

    it('returns null after all iterations', function () {
        $space = ParameterSpace::fromArray([
            'period' => ['min' => 1, 'max' => 10, 'step' => 1],
        ]);

        $generator = new RandomGenerator;
        $generator->initialize($space, 5);

        for ($i = 0; $i < 5; $i++) {
            expect($generator->next())->not->toBeNull();
        }

        expect($generator->next())->toBeNull();
    });

    it('generates values within parameter bounds', function () {
        $space = ParameterSpace::fromArray([
            'fastPeriod' => ['min' => 5, 'max' => 50, 'step' => 5],
            'stopLoss' => ['min' => 0.5, 'max' => 5.0, 'step' => 0.5, 'type' => 'float'],
        ]);

        $generator = new RandomGenerator;
        $generator->initialize($space, 200);

        while ($params = $generator->next()) {
            expect($params['fastPeriod'])->toBeInt()
                ->and($params['fastPeriod'])->toBeGreaterThanOrEqual(5)
                ->and($params['fastPeriod'])->toBeLessThanOrEqual(50)
                ->and($params['fastPeriod'] % 5)->toBe(0);

            expect($params['stopLoss'])->toBeFloat()
                ->and($params['stopLoss'])->toBeGreaterThanOrEqual(0.5)
                ->and($params['stopLoss'])->toBeLessThanOrEqual(5.0);
        }
    });

    it('tracks current iteration', function () {
        $space = ParameterSpace::fromArray([
            'period' => ['min' => 1, 'max' => 10, 'step' => 1],
        ]);

        $generator = new RandomGenerator;
        $generator->initialize($space, 10);

        expect($generator->currentIteration())->toBe(0);
        $generator->next();
        expect($generator->currentIteration())->toBe(1);
        $generator->next();
        expect($generator->currentIteration())->toBe(2);
    });

    it('reports total iterations', function () {
        $space = ParameterSpace::fromArray([
            'period' => ['min' => 1, 'max' => 10, 'step' => 1],
        ]);

        $generator = new RandomGenerator;
        $generator->initialize($space, 42);

        expect($generator->totalIterations())->toBe(42);
    });

    it('ignores inform calls', function () {
        $space = ParameterSpace::fromArray([
            'period' => ['min' => 1, 'max' => 10, 'step' => 1],
        ]);

        $generator = new RandomGenerator;
        $generator->initialize($space, 5);

        $generator->inform(['period' => 5], 1.5);

        $count = 0;
        while ($generator->next() !== null) {
            $count++;
        }

        expect($count)->toBe(5);
    });

    it('produces varied parameter combinations', function () {
        $space = ParameterSpace::fromArray([
            'period' => ['min' => 1, 'max' => 100, 'step' => 1],
        ]);

        $generator = new RandomGenerator;
        $generator->initialize($space, 50);

        $values = [];
        while ($params = $generator->next()) {
            $values[] = $params['period'];
        }

        $unique = array_unique($values);
        expect(count($unique))->toBeGreaterThan(10);
    });
});
