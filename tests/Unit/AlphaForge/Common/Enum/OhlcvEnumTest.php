<?php

use App\AlphaForge\Common\Enum\OhlcvEnum;

describe('OhlcvEnum', function () {
    it('has all six OHLCV fields', function () {
        expect(OhlcvEnum::cases())->toHaveCount(6);
    });

    it('has correct values for each case', function () {
        expect(OhlcvEnum::Timestamp->value)->toBe('timestamp')
            ->and(OhlcvEnum::Open->value)->toBe('open')
            ->and(OhlcvEnum::High->value)->toBe('high')
            ->and(OhlcvEnum::Low->value)->toBe('low')
            ->and(OhlcvEnum::Close->value)->toBe('close')
            ->and(OhlcvEnum::Volume->value)->toBe('volume');
    });

    it('can be created from string', function () {
        expect(OhlcvEnum::from('open'))->toBe(OhlcvEnum::Open);
        expect(OhlcvEnum::from('close'))->toBe(OhlcvEnum::Close);
    });

    it('throws for invalid value', function () {
        OhlcvEnum::from('invalid');
    })->throws(ValueError::class);
});
