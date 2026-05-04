<?php

use App\Stochastix\Common\Enum\TimeframeEnum;

describe('TimeframeEnum', function () {
    it('has all expected cases', function () {
        expect(TimeframeEnum::cases())->toHaveCount(9);

        $expectedValues = ['1m', '5m', '15m', '30m', '1h', '4h', '1d', '1w', '1M'];

        foreach ($expectedValues as $value) {
            expect(TimeframeEnum::tryFrom($value))->not->toBeNull();
        }
    });

    it('returns correct value', function () {
        expect(TimeframeEnum::M1->value)->toBe('1m');
        expect(TimeframeEnum::H1->value)->toBe('1h');
        expect(TimeframeEnum::D1->value)->toBe('1d');
        expect(TimeframeEnum::MN1->value)->toBe('1M');
    });

    it('can be created from string', function () {
        $timeframe = TimeframeEnum::from('1h');
        expect($timeframe)->toBe(TimeframeEnum::H1);
    });

    it('throws exception for invalid value', function () {
        TimeframeEnum::from('invalid');
    })->throws(ValueError::class);

    it('converts to seconds correctly', function () {
        expect(TimeframeEnum::M1->toSeconds())->toBe(60);
        expect(TimeframeEnum::M5->toSeconds())->toBe(300);
        expect(TimeframeEnum::H1->toSeconds())->toBe(3600);
        expect(TimeframeEnum::D1->toSeconds())->toBe(86400);
        expect(TimeframeEnum::W1->toSeconds())->toBe(604800);
    });

    it('converts to milliseconds correctly', function () {
        expect(TimeframeEnum::M1->toMilliseconds())->toBe(60000);
        expect(TimeframeEnum::H1->toMilliseconds())->toBe(3600000);
    });
});
