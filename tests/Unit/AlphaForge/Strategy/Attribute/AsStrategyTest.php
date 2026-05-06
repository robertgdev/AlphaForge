<?php

use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Strategy\Attribute\AsStrategy;

describe('AsStrategy attribute', function () {
    it('creates with required parameters', function () {
        $attr = new AsStrategy(
            alias: 'sma_crossover',
            name: 'SMA Crossover',
        );

        expect($attr->alias)->toBe('sma_crossover')
            ->and($attr->name)->toBe('SMA Crossover')
            ->and($attr->description)->toBeNull()
            ->and($attr->timeframe)->toBeNull()
            ->and($attr->requiredMarketData)->toBe([]);
    });

    it('creates with all parameters', function () {
        $attr = new AsStrategy(
            alias: 'sma_crossover',
            name: 'SMA Crossover',
            description: 'A simple moving average crossover strategy',
            timeframe: TimeframeEnum::H1,
            requiredMarketData: [TimeframeEnum::H1, TimeframeEnum::H4],
        );

        expect($attr->description)->toBe('A simple moving average crossover strategy')
            ->and($attr->timeframe)->toBe(TimeframeEnum::H1)
            ->and($attr->requiredMarketData)->toBe([TimeframeEnum::H1, TimeframeEnum::H4]);
    });
});
