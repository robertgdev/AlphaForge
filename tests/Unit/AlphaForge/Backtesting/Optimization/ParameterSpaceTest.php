<?php

use App\AlphaForge\Backtesting\Optimization\ParameterDimension;
use App\AlphaForge\Backtesting\Optimization\ParameterSpace;
use App\AlphaForge\Strategy\Dto\BarData;
use App\AlphaForge\Strategy\Dto\InitializeData;
use App\AlphaForge\ExitRule\ExitRuleSet;
use App\AlphaForge\Strategy\Attribute\Input;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;
use App\AlphaForge\Strategy\StrategyInterface;

describe('ParameterSpace', function () {
    it('creates from array of ranges', function () {
        $space = ParameterSpace::fromArray([
            'fastPeriod' => ['min' => 5, 'max' => 15, 'step' => 5],
            'slowPeriod' => ['min' => 20, 'max' => 40, 'step' => 10],
        ]);

        expect($space->dimensions)->toHaveCount(2)
            ->and($space->dimensions['fastPeriod'])->toBeInstanceOf(ParameterDimension::class)
            ->and($space->dimensions['fastPeriod']->min)->toBe(5.0)
            ->and($space->dimensions['slowPeriod']->max)->toBe(40.0);
    });

    it('defaults step to 1 when not provided', function () {
        $space = ParameterSpace::fromArray([
            'period' => ['min' => 1, 'max' => 5],
        ]);

        expect($space->dimensions['period']->step)->toBe(1.0);
    });

    it('defaults type to int when not provided', function () {
        $space = ParameterSpace::fromArray([
            'period' => ['min' => 1, 'max' => 5],
        ]);

        expect($space->dimensions['period']->type)->toBe('int');
    });

    it('detects float type from config', function () {
        $space = ParameterSpace::fromArray([
            'rate' => ['min' => 0.5, 'max' => 2.0, 'step' => 0.5, 'type' => 'float'],
        ]);

        expect($space->dimensions['rate']->type)->toBe('float');
    });

    it('calculates grid size correctly', function () {
        $space = ParameterSpace::fromArray([
            'fastPeriod' => ['min' => 5, 'max' => 15, 'step' => 5],
            'slowPeriod' => ['min' => 20, 'max' => 40, 'step' => 10],
        ]);

        expect($space->gridSize())->toBe(3 * 3);
    });

    it('returns 0 grid size for empty space', function () {
        $space = ParameterSpace::fromArray([]);

        expect($space->gridSize())->toBe(0);
    });

    it('converts to array representation', function () {
        $space = ParameterSpace::fromArray([
            'fastPeriod' => ['min' => 5, 'max' => 15, 'step' => 5, 'type' => 'int'],
        ]);

        $arr = $space->toArray();
        expect($arr)->toHaveKey('fastPeriod')
            ->and($arr['fastPeriod']['min'])->toBe(5.0)
            ->and($arr['fastPeriod']['max'])->toBe(15.0)
            ->and($arr['fastPeriod']['step'])->toBe(5.0)
            ->and($arr['fastPeriod']['type'])->toBe('int');
    });

    it('creates from strategy', function () {
        $registry = Mockery::mock(StrategyRegistryInterface::class);
        $strategy = new class implements StrategyInterface
        {
            #[Input(min: 5, max: 15, step: 5)]
            private int $fastPeriod = 10;

            #[Input(min: 20, max: 40, step: 10)]
            private int $slowPeriod = 30;

            public function configure(array $inputs): void {}

            public function initialize(InitializeData $data): void {}

            public function onBar(BarData $data): array
            {
                return [];
            }

            public function getExitRules(): ?ExitRuleSet
            {
                return null;
            }
        };

        $registry->shouldReceive('get')->with('test_strategy')->andReturn($strategy);

        $space = ParameterSpace::fromStrategy('test_strategy', $registry);

        expect($space->dimensions)->toHaveCount(2)
            ->and($space->dimensions['fastPeriod']->min)->toBe(5.0)
            ->and($space->dimensions['slowPeriod']->max)->toBe(40.0);
    });
});
