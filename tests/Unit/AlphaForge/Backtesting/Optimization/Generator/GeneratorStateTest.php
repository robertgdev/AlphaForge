<?php

use App\AlphaForge\Backtesting\Optimization\Generator\GeneticGenerator;
use App\AlphaForge\Backtesting\Optimization\Generator\RandomGenerator;
use App\AlphaForge\Backtesting\Optimization\Generator\GridGenerator;
use App\AlphaForge\Backtesting\Optimization\ParameterDimension;
use App\AlphaForge\Backtesting\Optimization\ParameterSpace;

describe('Generator state serialization', function () {
    it('grid generator preserves index across save/restore', function () {
        $space = new ParameterSpace([
            'a' => new ParameterDimension('a', 1, 5, 2),
            'b' => new ParameterDimension('b', 10, 30, 10),
        ]);

        $gen = new GridGenerator;
        $gen->initialize($space);

        // Consume first 3 parameters
        $gen->next();
        $gen->next();
        $gen->next();
        $pos = $gen->currentIteration();

        // Save state
        $state = $gen->getState();

        // Create new generator and restore
        $gen2 = new GridGenerator;
        $gen2->initialize($space);
        $gen2->restoreState($state);

        expect($gen2->currentIteration())->toBe($pos);
        // The next parameter for gen2 should be the 4th one
        $next2 = $gen2->next();
        $gen->next(); // consume to stay in sync
        // Both should produce same parameter set
        $next1 = $gen->next();
        $next3 = $gen2->next();
        expect($next3)->toBe($next1);
    });

    it('random generator preserves completed count', function () {
        $space = new ParameterSpace([
            'p' => new ParameterDimension('p', 1, 10, 1),
        ]);

        $gen = new RandomGenerator;
        $gen->initialize($space, 5);
        $gen->next();
        $gen->next();

        $state = $gen->getState();
        expect($state['completed'])->toBe(2);

        $gen2 = new RandomGenerator;
        $gen2->initialize($space, 5);
        $gen2->restoreState($state);

        expect($gen2->currentIteration())->toBe(2);
        // Should produce 3 more entries
        $count = 0;
        while ($gen2->next() !== null) {
            $count++;
        }
        expect($count)->toBe(3); // 5 total - 2 already completed
    });

    it('genetic generator preserves generation state', function () {
        $space = new ParameterSpace([
            'a' => new ParameterDimension('a', 1, 10, 1),
        ]);

        $gen = new GeneticGenerator;
        $gen->initialize($space, 10, 3);
        // Consume first generation (10 individuals)
        for ($i = 0; $i < 10; $i++) {
            $gen->next();
            $gen->inform(['a' => 1], (float) $i);
        }

        $state = $gen->getState();
        expect($state['generation'])->toBe(1);

        $gen2 = new GeneticGenerator;
        $gen2->initialize($space, 10, 3);
        $gen2->restoreState($state);

        expect($gen2->currentIteration())->toBe(10);
    });

    it('grid generator total matches after restore', function () {
        $space = new ParameterSpace([
            'a' => new ParameterDimension('a', 1, 3, 1),
        ]);

        $gen = new GridGenerator;
        $gen->initialize($space);
        $gen->next();
        $gen->restoreState(['index' => 0]);
        expect($gen->totalIterations())->toBe(3);
    });
});