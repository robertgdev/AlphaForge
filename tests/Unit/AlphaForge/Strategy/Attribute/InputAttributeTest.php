<?php

use App\AlphaForge\Strategy\Attribute\Input;

describe('Input attribute', function () {
    it('creates with default parameters', function () {
        $attr = new Input;

        expect($attr->description)->toBeNull()
            ->and($attr->min)->toBeNull()
            ->and($attr->max)->toBeNull()
            ->and($attr->choices)->toBeNull()
            ->and($attr->arrayType)->toBeNull()
            ->and($attr->minChoices)->toBeNull()
            ->and($attr->maxChoices)->toBeNull()
            ->and($attr->step)->toBeNull();
    });

    it('creates with description only', function () {
        $attr = new Input(description: 'Fast SMA period');

        expect($attr->description)->toBe('Fast SMA period');
    });

    it('creates with min and max', function () {
        $attr = new Input(
            description: 'Period',
            min: 5,
            max: 200,
        );

        expect($attr->min)->toBe(5.0)
            ->and($attr->max)->toBe(200.0);
    });

    it('creates with choices', function () {
        $attr = new Input(
            description: 'Direction',
            choices: ['long', 'short', 'both'],
        );

        expect($attr->choices)->toBe(['long', 'short', 'both']);
    });

    it('creates with step for optimization', function () {
        $attr = new Input(
            description: 'Period',
            min: 5,
            max: 50,
            step: 5,
        );

        expect($attr->step)->toBe(5);
    });

    it('creates with array type and choice constraints', function () {
        $attr = new Input(
            description: 'Timeframes',
            arrayType: TimeframeEnum::class,
            minChoices: 1,
            maxChoices: 3,
        );

        expect($attr->arrayType)->toBe(TimeframeEnum::class)
            ->and($attr->minChoices)->toBe(1)
            ->and($attr->maxChoices)->toBe(3);
    });

    it('accepts float step', function () {
        $attr = new Input(
            description: 'Rate',
            min: 0.5,
            max: 10.0,
            step: 0.5,
        );

        expect($attr->step)->toBe(0.5);
    });
});
