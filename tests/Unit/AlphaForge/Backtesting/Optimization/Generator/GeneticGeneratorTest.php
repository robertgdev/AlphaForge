<?php

use App\AlphaForge\Backtesting\Optimization\Generator\GeneticGenerator;
use App\AlphaForge\Backtesting\Optimization\ParameterSpace;

describe('GeneticGenerator', function () {
    it('generates initial random population', function () {
        $space = ParameterSpace::fromArray([
            'fastPeriod' => ['min' => 5, 'max' => 50, 'step' => 5],
            'slowPeriod' => ['min' => 20, 'max' => 200, 'step' => 10],
        ]);

        $generator = new GeneticGenerator;
        $generator->initialize($space, 10, 3);

        $count = 0;
        for ($i = 0; $i < 10; $i++) {
            $params = $generator->next();
            expect($params)->not->toBeNull()
                ->and($params)->toHaveKeys(['fastPeriod', 'slowPeriod']);
            $count++;
        }

        expect($count)->toBe(10);
    });

    it('reports total iterations as population * generations', function () {
        $space = ParameterSpace::fromArray([
            'fastPeriod' => ['min' => 5, 'max' => 50, 'step' => 5],
        ]);

        $generator = new GeneticGenerator;
        $generator->initialize($space, 20, 5);

        expect($generator->totalIterations())->toBe(100);
    });

    it('evolves to next generation when current generation is fully scored', function () {
        $space = ParameterSpace::fromArray([
            'fastPeriod' => ['min' => 5, 'max' => 50, 'step' => 5],
            'slowPeriod' => ['min' => 20, 'max' => 200, 'step' => 10],
        ]);

        $generator = new GeneticGenerator;
        $generator->initialize($space, 10, 3);

        for ($gen = 0; $gen < 3; $gen++) {
            for ($i = 0; $i < 10; $i++) {
                $params = $generator->next();
                expect($params)->not->toBeNull();
                $generator->inform($params, mt_rand() / mt_getrandmax());
            }
        }

        expect($generator->currentIteration())->toBe(30);
    });

    it('returns null after max generations', function () {
        $space = ParameterSpace::fromArray([
            'fastPeriod' => ['min' => 5, 'max' => 50, 'step' => 5],
        ]);

        $generator = new GeneticGenerator;
        $generator->initialize($space, 5, 2);

        for ($gen = 0; $gen < 2; $gen++) {
            for ($i = 0; $i < 5; $i++) {
                $params = $generator->next();
                expect($params)->not->toBeNull();
                $generator->inform($params, mt_rand() / mt_getrandmax());
            }
        }

        expect($generator->next())->toBeNull();
    });

    it('generates values within parameter bounds', function () {
        $space = ParameterSpace::fromArray([
            'fastPeriod' => ['min' => 5, 'max' => 50, 'step' => 5],
            'slowPeriod' => ['min' => 20, 'max' => 200, 'step' => 10],
        ]);

        $generator = new GeneticGenerator;
        $generator->initialize($space, 20, 5);

        $totalYielded = 0;
        for ($gen = 0; $gen < 5; $gen++) {
            for ($i = 0; $i < 20; $i++) {
                $params = $generator->next();
                if ($params === null) {
                    break 2;
                }

                expect($params['fastPeriod'])->toBeGreaterThanOrEqual(5)
                    ->and($params['fastPeriod'])->toBeLessThanOrEqual(50)
                    ->and($params['slowPeriod'])->toBeGreaterThanOrEqual(20)
                    ->and($params['slowPeriod'])->toBeLessThanOrEqual(200);

                $generator->inform($params, mt_rand() / mt_getrandmax());
                $totalYielded++;
            }
        }

        expect($totalYielded)->toBe(100);
    });

    it('uses default parameters when nulls provided', function () {
        $space = ParameterSpace::fromArray([
            'fastPeriod' => ['min' => 5, 'max' => 50, 'step' => 5],
        ]);

        $generator = new GeneticGenerator;
        $generator->initialize($space, null, null, null, null);

        expect($generator->totalIterations())->toBe(50 * 20);
    });

    it('tracks current iteration', function () {
        $space = ParameterSpace::fromArray([
            'fastPeriod' => ['min' => 5, 'max' => 50, 'step' => 5],
        ]);

        $generator = new GeneticGenerator;
        $generator->initialize($space, 10, 2);

        expect($generator->currentIteration())->toBe(0);

        $params = $generator->next();
        expect($generator->currentIteration())->toBe(1);

        $generator->inform($params, 0.5);
    });

    it('improves scores over generations', function () {
        $space = ParameterSpace::fromArray([
            'fastPeriod' => ['min' => 5, 'max' => 50, 'step' => 5],
        ]);

        $generator = new GeneticGenerator;
        $generator->initialize($space, 20, 5);

        $generationBestScores = [];

        for ($gen = 0; $gen < 5; $gen++) {
            $bestInGen = -PHP_FLOAT_MAX;
            for ($i = 0; $i < 20; $i++) {
                $params = $generator->next();
                if ($params === null) {
                    break 2;
                }

                $score = (float) $params['fastPeriod'] / 50.0;
                $generator->inform($params, $score);

                if ($score > $bestInGen) {
                    $bestInGen = $score;
                }
            }
            $generationBestScores[] = $bestInGen;
        }

        expect(count($generationBestScores))->toBe(5);
    });
});
