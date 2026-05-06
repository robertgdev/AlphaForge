<?php

use App\AlphaForge\Backtesting\Service\ParameterOptimizerService;
use App\AlphaForge\Backtesting\Service\Backtester;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;

describe('ParameterOptimizerService', function () {
    describe('generateCombinations', function () {
        beforeEach(function () {
            $backtester = Mockery::mock(Backtester::class);
            $strategyRegistry = Mockery::mock(StrategyRegistryInterface::class);
            $this->service = new ParameterOptimizerService($backtester, $strategyRegistry);
        });

        it('generates combinations for a single parameter', function () {
            $ranges = [
                'fastPeriod' => ['min' => 5, 'max' => 15, 'step' => 5],
            ];

            $combinations = $this->service->generateCombinations($ranges);

            expect($combinations)->toHaveCount(3)
                ->and($combinations[0])->toBe(['fastPeriod' => 5])
                ->and($combinations[1])->toBe(['fastPeriod' => 10])
                ->and($combinations[2])->toBe(['fastPeriod' => 15]);
        });

        it('generates cartesian product for two parameters', function () {
            $ranges = [
                'fastPeriod' => ['min' => 5, 'max' => 10, 'step' => 5],
                'slowPeriod' => ['min' => 20, 'max' => 30, 'step' => 10],
            ];

            $combinations = $this->service->generateCombinations($ranges);

            expect($combinations)->toHaveCount(4)
                ->and($combinations[0])->toBe(['fastPeriod' => 5, 'slowPeriod' => 20])
                ->and($combinations[1])->toBe(['fastPeriod' => 5, 'slowPeriod' => 30])
                ->and($combinations[2])->toBe(['fastPeriod' => 10, 'slowPeriod' => 20])
                ->and($combinations[3])->toBe(['fastPeriod' => 10, 'slowPeriod' => 30]);
        });

        it('generates cartesian product for three parameters', function () {
            $ranges = [
                'p1' => ['min' => 1, 'max' => 2, 'step' => 1],
                'p2' => ['min' => 10, 'max' => 20, 'step' => 10],
                'p3' => ['min' => 100, 'max' => 200, 'step' => 100],
            ];

            $combinations = $this->service->generateCombinations($ranges);

            expect($combinations)->toHaveCount(8);
        });

        it('returns single combination when min equals max', function () {
            $ranges = [
                'period' => ['min' => 14, 'max' => 14, 'step' => 1],
            ];

            $combinations = $this->service->generateCombinations($ranges);

            expect($combinations)->toHaveCount(1)
                ->and($combinations[0])->toBe(['period' => 14]);
        });

        it('defaults step to 1 when not provided', function () {
            $ranges = [
                'period' => ['min' => 1, 'max' => 3],
            ];

            $combinations = $this->service->generateCombinations($ranges);

            expect($combinations)->toHaveCount(3)
                ->and($combinations[0])->toBe(['period' => 1])
                ->and($combinations[1])->toBe(['period' => 2])
                ->and($combinations[2])->toBe(['period' => 3]);
        });

        it('casts values to int by default', function () {
            $ranges = [
                'period' => ['min' => 5, 'max' => 10, 'step' => 5],
            ];

            $combinations = $this->service->generateCombinations($ranges);

            foreach ($combinations as $combo) {
                expect($combo['period'])->toBeInt();
            }
        });

        it('casts values to float when type is float', function () {
            $ranges = [
                'threshold' => ['min' => 0, 'max' => 2, 'step' => 1, 'type' => 'float'],
            ];

            $combinations = $this->service->generateCombinations($ranges);

            foreach ($combinations as $combo) {
                expect($combo['threshold'])->toBeFloat();
            }
        });

        it('returns empty array for empty ranges', function () {
            $combinations = $this->service->generateCombinations([]);

            expect($combinations)->toBe([[]]);
        });

        it('handles fractional step values correctly', function () {
            $ranges = [
                'fastPeriod' => ['min' => 5, 'max' => 15, 'step' => 5],
                'slowPeriod' => ['min' => 20, 'max' => 25, 'step' => 5],
            ];

            $combinations = $this->service->generateCombinations($ranges);

            expect($combinations)->toHaveCount(6);
        });
    });
});
