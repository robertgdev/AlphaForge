<?php

use App\AlphaForge\Backtesting\Optimization\Generator\GridGenerator;
use App\AlphaForge\Backtesting\Optimization\ParameterSpace;

describe('GridGenerator', function () {
    it('generates all cartesian product combinations', function () {
        $space = ParameterSpace::fromArray([
            'fastPeriod' => ['min' => 5, 'max' => 15, 'step' => 5],
            'slowPeriod' => ['min' => 20, 'max' => 40, 'step' => 10],
        ]);

        $generator = new GridGenerator;
        $generator->initialize($space);

        $combinations = [];
        while ($params = $generator->next()) {
            $combinations[] = $params;
        }

        expect($combinations)->toHaveCount(9);
    });

    it('returns null after all combinations exhausted', function () {
        $space = ParameterSpace::fromArray([
            'period' => ['min' => 1, 'max' => 3, 'step' => 1],
        ]);

        $generator = new GridGenerator;
        $generator->initialize($space);

        $generator->next();
        $generator->next();
        $generator->next();

        expect($generator->next())->toBeNull();
    });

    it('tracks current iteration', function () {
        $space = ParameterSpace::fromArray([
            'period' => ['min' => 1, 'max' => 5, 'step' => 1],
        ]);

        $generator = new GridGenerator;
        $generator->initialize($space);

        expect($generator->currentIteration())->toBe(0);
        $generator->next();
        expect($generator->currentIteration())->toBe(1);
        $generator->next();
        expect($generator->currentIteration())->toBe(2);
    });

    it('reports total iterations', function () {
        $space = ParameterSpace::fromArray([
            'fastPeriod' => ['min' => 5, 'max' => 15, 'step' => 5],
            'slowPeriod' => ['min' => 20, 'max' => 40, 'step' => 10],
        ]);

        $generator = new GridGenerator;
        $generator->initialize($space);

        expect($generator->totalIterations())->toBe(9);
    });

    it('handles single parameter', function () {
        $space = ParameterSpace::fromArray([
            'period' => ['min' => 5, 'max' => 15, 'step' => 5],
        ]);

        $generator = new GridGenerator;
        $generator->initialize($space);

        $combinations = [];
        while ($params = $generator->next()) {
            $combinations[] = $params;
        }

        expect($combinations)->toBe([
            ['period' => 5],
            ['period' => 10],
            ['period' => 15],
        ]);
    });

    it('handles empty space', function () {
        $space = ParameterSpace::fromArray([]);

        $generator = new GridGenerator;
        $generator->initialize($space);

        expect($generator->next())->toBe([]);
        expect($generator->next())->toBeNull();
    });

    it('ignores inform calls', function () {
        $space = ParameterSpace::fromArray([
            'period' => ['min' => 1, 'max' => 2, 'step' => 1],
        ]);

        $generator = new GridGenerator;
        $generator->initialize($space);

        $generator->inform(['period' => 1], 1.5);

        $combinations = [];
        while ($params = $generator->next()) {
            $combinations[] = $params;
        }

        expect($combinations)->toHaveCount(2);
    });

    it('generates correct combinations for two params', function () {
        $space = ParameterSpace::fromArray([
            'fastPeriod' => ['min' => 5, 'max' => 10, 'step' => 5],
            'slowPeriod' => ['min' => 20, 'max' => 30, 'step' => 10],
        ]);

        $generator = new GridGenerator;
        $generator->initialize($space);

        $combinations = [];
        while ($params = $generator->next()) {
            $combinations[] = $params;
        }

        expect($combinations)->toHaveCount(4);
        foreach ($combinations as $combo) {
            expect($combo)->toHaveKeys(['fastPeriod', 'slowPeriod']);
        }
    });
});
