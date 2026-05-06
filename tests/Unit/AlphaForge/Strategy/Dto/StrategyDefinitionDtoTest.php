<?php

use App\AlphaForge\Strategy\Dto\InputDefinitionDto;
use App\AlphaForge\Strategy\Dto\StrategyDefinitionDto;

describe('StrategyDefinitionDto', function () {
    it('creates with required parameters', function () {
        $dto = new StrategyDefinitionDto(
            alias: 'sma_crossover',
            name: 'SMA Crossover',
            description: 'A crossover strategy',
            inputs: [],
        );

        expect($dto->alias)->toBe('sma_crossover')
            ->and($dto->name)->toBe('SMA Crossover')
            ->and($dto->description)->toBe('A crossover strategy')
            ->and($dto->inputs)->toBe([])
            ->and($dto->timeframe)->toBeNull()
            ->and($dto->requiredMarketData)->toBe([]);
    });

    it('creates with all parameters', function () {
        $inputDto = new InputDefinitionDto(
            name: 'fastPeriod',
            description: 'Fast period',
            type: 'integer',
            defaultValue: 10,
            min: 5,
            max: 50,
        );

        $dto = new StrategyDefinitionDto(
            alias: 'sma_crossover',
            name: 'SMA Crossover',
            description: 'A crossover strategy',
            inputs: [$inputDto],
            timeframe: '1h',
            requiredMarketData: ['1h', '4h'],
        );

        expect($dto->inputs)->toHaveCount(1)
            ->and($dto->inputs[0]->name)->toBe('fastPeriod')
            ->and($dto->timeframe)->toBe('1h')
            ->and($dto->requiredMarketData)->toBe(['1h', '4h']);
    });

    it('accepts null description', function () {
        $dto = new StrategyDefinitionDto(
            alias: 'test',
            name: 'Test',
            description: null,
            inputs: [],
        );

        expect($dto->description)->toBeNull();
    });
});
