<?php

use App\AlphaForge\Common\Enum\DirectionEnum;

describe('DirectionEnum', function () {
    it('has LONG and SHORT cases', function () {
        expect(DirectionEnum::cases())->toHaveCount(2);
    });

    it('LONG has value LONG', function () {
        expect(DirectionEnum::LONG->value)->toBe('LONG');
    });

    it('SHORT has value SHORT', function () {
        expect(DirectionEnum::SHORT->value)->toBe('SHORT');
    });

    it('can be created from string', function () {
        expect(DirectionEnum::from('LONG'))->toBe(DirectionEnum::LONG);
        expect(DirectionEnum::from('SHORT'))->toBe(DirectionEnum::SHORT);
    });

    it('throws for invalid value', function () {
        DirectionEnum::from('INVALID');
    })->throws(ValueError::class);
});
