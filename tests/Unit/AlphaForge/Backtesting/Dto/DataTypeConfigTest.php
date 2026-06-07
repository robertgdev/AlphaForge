<?php

use App\AlphaForge\Backtesting\Dto\DataTypeConfig;

describe('DataTypeConfig', function () {
    describe('fromOptions()', function () {
        it('defaults to ohlcv, null brickSize, null atrPeriod', function () {
            $config = DataTypeConfig::fromOptions(null, null, null);

            expect($config->dataType)->toBe('ohlcv')
                ->and($config->brickSize)->toBeNull()
                ->and($config->atrPeriod)->toBeNull()
                ->and($config->warnings)->toBeEmpty();
        });

        it('accepts heikenashi data type', function () {
            $config = DataTypeConfig::fromOptions('heikenashi', null, null);

            expect($config->dataType)->toBe('heikenashi')
                ->and($config->brickSize)->toBeNull()
                ->and($config->atrPeriod)->toBeNull()
                ->and($config->warnings)->toBeEmpty();
        });

        it('accepts renko with valid brick size', function () {
            $config = DataTypeConfig::fromOptions('renko', '0.001', null);

            expect($config->dataType)->toBe('renko')
                ->and($config->brickSize)->toBe(0.001)
                ->and($config->atrPeriod)->toBeNull()
                ->and($config->warnings)->toBeEmpty();
        });

        it('accepts atr_renko with valid atr period', function () {
            $config = DataTypeConfig::fromOptions('atr_renko', null, '14');

            expect($config->dataType)->toBe('atr_renko')
                ->and($config->brickSize)->toBeNull()
                ->and($config->atrPeriod)->toBe(14)
                ->and($config->warnings)->toBeEmpty();
        });

        it('throws on invalid data type', function () {
            expect(fn () => DataTypeConfig::fromOptions('invalid', null, null))
                ->toThrow(InvalidArgumentException::class, "Invalid data-type 'invalid'");
        });

        it('throws when renko has no brick size', function () {
            expect(fn () => DataTypeConfig::fromOptions('renko', null, null))
                ->toThrow(InvalidArgumentException::class, 'data-type=renko requires --brick-size');
        });

        it('throws when renko has zero brick size', function () {
            expect(fn () => DataTypeConfig::fromOptions('renko', '0', null))
                ->toThrow(InvalidArgumentException::class, 'data-type=renko requires --brick-size');
        });

        it('throws when renko has negative brick size', function () {
            expect(fn () => DataTypeConfig::fromOptions('renko', '-5', null))
                ->toThrow(InvalidArgumentException::class, 'data-type=renko requires --brick-size');
        });

        it('throws when atr_renko has no atr period', function () {
            expect(fn () => DataTypeConfig::fromOptions('atr_renko', null, null))
                ->toThrow(InvalidArgumentException::class, 'data-type=atr_renko requires --atr-period');
        });

        it('throws when atr_renko has zero atr period', function () {
            expect(fn () => DataTypeConfig::fromOptions('atr_renko', null, '0'))
                ->toThrow(InvalidArgumentException::class, 'data-type=atr_renko requires --atr-period');
        });

        it('auto-upgrades renko to atr_renko when brick size is missing but atr-period is set', function () {
            $config = DataTypeConfig::fromOptions('renko', null, '14');

            expect($config->dataType)->toBe('atr_renko')
                ->and($config->brickSize)->toBeNull()
                ->and($config->atrPeriod)->toBe(14)
                ->and($config->warnings)->toHaveCount(1)
                ->and($config->warnings[0])->toContain('Auto-upgraded');
        });

        it('auto-upgrades renko to atr_renko when brick size is zero and atr-period is set', function () {
            $config = DataTypeConfig::fromOptions('renko', '0', '14');

            expect($config->dataType)->toBe('atr_renko')
                ->and($config->brickSize)->toBeNull()
                ->and($config->atrPeriod)->toBe(14)
                ->and($config->warnings)->toHaveCount(1);
        });

        it('does not auto-upgrade when renko has valid brick size and atr-period together', function () {
            $config = DataTypeConfig::fromOptions('renko', '0.001', '14');

            expect($config->dataType)->toBe('renko')
                ->and($config->brickSize)->toBe(0.001)
                ->and($config->atrPeriod)->toBeNull();
        });

        it('warns when brick-size is given with non-renko data type', function () {
            $config = DataTypeConfig::fromOptions('ohlcv', '0.001', null);

            expect($config->dataType)->toBe('ohlcv')
                ->and($config->warnings)->toHaveCount(1)
                ->and($config->warnings[0])->toContain('--brick-size is ignored');
        });

        it('warns when atr-period is given with non-renko data type', function () {
            $config = DataTypeConfig::fromOptions('heikenashi', null, '14');

            expect($config->dataType)->toBe('heikenashi')
                ->and($config->warnings)->toHaveCount(1)
                ->and($config->warnings[0])->toContain('--atr-period is ignored');
        });

        it('warns when both brick-size and atr-period given with ohlcv', function () {
            $config = DataTypeConfig::fromOptions('ohlcv', '0.001', '14');

            expect($config->dataType)->toBe('ohlcv')
                ->and($config->warnings)->toHaveCount(2);
        });

        it('treats empty brick-size and atr-period strings as null', function () {
            $config = DataTypeConfig::fromOptions('ohlcv', '', '');

            expect($config->dataType)->toBe('ohlcv')
                ->and($config->brickSize)->toBeNull()
                ->and($config->atrPeriod)->toBeNull();
        });

        it('returns null brickSize and atrPeriod for ohlcv even when given', function () {
            $config = DataTypeConfig::fromOptions('ohlcv', '10', '20');

            expect($config->dataType)->toBe('ohlcv')
                ->and($config->brickSize)->toBeNull()
                ->and($config->atrPeriod)->toBeNull();
        });

        it('returns null atrPeriod for renko even when given', function () {
            $config = DataTypeConfig::fromOptions('renko', '0.001', '14');

            expect($config->dataType)->toBe('renko')
                ->and($config->brickSize)->toBe(0.001)
                ->and($config->atrPeriod)->toBeNull();
        });

        it('returns null brickSize for atr_renko even when given', function () {
            $config = DataTypeConfig::fromOptions('atr_renko', '0.001', '14');

            expect($config->dataType)->toBe('atr_renko')
                ->and($config->brickSize)->toBeNull()
                ->and($config->atrPeriod)->toBe(14);
        });

        it('parses brick size as float from string', function () {
            $config = DataTypeConfig::fromOptions('renko', '100', null);

            expect($config->brickSize)->toBe(100.0);
        });

        it('parses atr period as int from string', function () {
            $config = DataTypeConfig::fromOptions('atr_renko', null, '20');

            expect($config->atrPeriod)->toBe(20);
        });
    });

    describe('fromArray()', function () {
        it('parses data_type with snake_case keys', function () {
            $config = DataTypeConfig::fromArray([
                'data_type' => 'renko',
                'brick_size' => 0.001,
                'atr_period' => null,
            ]);

            expect($config->dataType)->toBe('renko')
                ->and($config->brickSize)->toBe(0.001)
                ->and($config->atrPeriod)->toBeNull();
        });

        it('parses dataType with camelCase keys', function () {
            $config = DataTypeConfig::fromArray([
                'dataType' => 'renko',
                'brickSize' => 10.0,
                'atrPeriod' => null,
            ]);

            expect($config->dataType)->toBe('renko')
                ->and($config->brickSize)->toBe(10.0)
                ->and($config->atrPeriod)->toBeNull();
        });

        it('parses atr_renko with snake_case keys', function () {
            $config = DataTypeConfig::fromArray([
                'data_type' => 'atr_renko',
                'brick_size' => null,
                'atr_period' => 14,
            ]);

            expect($config->dataType)->toBe('atr_renko')
                ->and($config->brickSize)->toBeNull()
                ->and($config->atrPeriod)->toBe(14);
        });

        it('parses atr_renko with camelCase keys', function () {
            $config = DataTypeConfig::fromArray([
                'dataType' => 'atr_renko',
                'brickSize' => null,
                'atrPeriod' => 20,
            ]);

            expect($config->dataType)->toBe('atr_renko')
                ->and($config->brickSize)->toBeNull()
                ->and($config->atrPeriod)->toBe(20);
        });

        it('defaults to ohlcv when no data_type provided', function () {
            $config = DataTypeConfig::fromArray([]);

            expect($config->dataType)->toBe('ohlcv')
                ->and($config->brickSize)->toBeNull()
                ->and($config->atrPeriod)->toBeNull();
        });

        it('auto-upgrades renko to atr_renko when brick_size is missing but atr_period is provided', function () {
            $config = DataTypeConfig::fromArray([
                'data_type' => 'renko',
                'brick_size' => null,
                'atr_period' => 14,
            ]);

            expect($config->dataType)->toBe('atr_renko')
                ->and($config->brickSize)->toBeNull()
                ->and($config->atrPeriod)->toBe(14);
        });

        it('does not collect warnings in fromArray mode', function () {
            $config = DataTypeConfig::fromArray([
                'dataType' => 'ohlcv',
                'brickSize' => 0.001,
                'atrPeriod' => 14,
            ]);

            expect($config->warnings)->toBeEmpty();
        });

        it('throws on invalid data_type in array', function () {
            expect(fn () => DataTypeConfig::fromArray(['data_type' => 'invalid']))
                ->toThrow(InvalidArgumentException::class, "Invalid data-type 'invalid'");
        });
    });
});
