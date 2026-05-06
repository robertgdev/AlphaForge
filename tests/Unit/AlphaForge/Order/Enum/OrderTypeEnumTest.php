<?php

use App\AlphaForge\Order\Enum\OrderTypeEnum;

describe('OrderTypeEnum', function () {
    it('has Market, Limit, and Stop cases', function () {
        expect(OrderTypeEnum::cases())->toHaveCount(3);
    });

    it('Market has correct value', function () {
        expect(OrderTypeEnum::Market->value)->toBe('market');
    });

    it('Limit has correct value', function () {
        expect(OrderTypeEnum::Limit->value)->toBe('limit');
    });

    it('Stop has correct value', function () {
        expect(OrderTypeEnum::Stop->value)->toBe('stop');
    });

    it('can be created from string', function () {
        expect(OrderTypeEnum::from('market'))->toBe(OrderTypeEnum::Market)
            ->and(OrderTypeEnum::from('limit'))->toBe(OrderTypeEnum::Limit)
            ->and(OrderTypeEnum::from('stop'))->toBe(OrderTypeEnum::Stop);
    });

    it('throws for invalid value', function () {
        OrderTypeEnum::from('stop_limit');
    })->throws(ValueError::class);
});
