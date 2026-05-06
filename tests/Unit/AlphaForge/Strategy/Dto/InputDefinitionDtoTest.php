<?php

use App\AlphaForge\Strategy\Dto\InputDefinitionDto;

describe('InputDefinitionDto', function () {
    it('creates with required parameters', function () {
        $dto = new InputDefinitionDto(
            name: 'fastPeriod',
            description: 'Fast SMA period',
            type: 'integer',
        );

        expect($dto->name)->toBe('fastPeriod')
            ->and($dto->description)->toBe('Fast SMA period')
            ->and($dto->type)->toBe('integer')
            ->and($dto->defaultValue)->toBeNull()
            ->and($dto->min)->toBeNull()
            ->and($dto->max)->toBeNull()
            ->and($dto->choices)->toBeNull()
            ->and($dto->minChoices)->toBeNull()
            ->and($dto->maxChoices)->toBeNull();
    });

    it('creates with all parameters', function () {
        $dto = new InputDefinitionDto(
            name: 'period',
            description: 'SMA Period',
            type: 'integer',
            defaultValue: 14,
            min: 5,
            max: 200,
            choices: [5, 10, 20, 50],
            minChoices: 1,
            maxChoices: 3,
        );

        expect($dto->defaultValue)->toBe(14)
            ->and($dto->min)->toBe(5.0)
            ->and($dto->max)->toBe(200.0)
            ->and($dto->choices)->toBe([5, 10, 20, 50])
            ->and($dto->minChoices)->toBe(1)
            ->and($dto->maxChoices)->toBe(3);
    });

    it('accepts string default value', function () {
        $dto = new InputDefinitionDto(
            name: 'stakeAmount',
            description: 'Stake amount',
            type: 'string',
            defaultValue: '1000',
        );

        expect($dto->defaultValue)->toBe('1000');
    });

    it('accepts array default value', function () {
        $dto = new InputDefinitionDto(
            name: 'timeframes',
            description: 'Timeframes',
            type: 'array',
            defaultValue: ['1h', '4h'],
        );

        expect($dto->defaultValue)->toBe(['1h', '4h']);
    });

    it('accepts boolean default value', function () {
        $dto = new InputDefinitionDto(
            name: 'enabled',
            description: 'Enable flag',
            type: 'boolean',
            defaultValue: true,
        );

        expect($dto->defaultValue)->toBeTrue();
    });
});
