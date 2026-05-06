<?php

use App\AlphaForge\Order\Model\Pricing\FixedCommission;
use App\AlphaForge\Order\Model\Pricing\PercentageCommission;
use App\AlphaForge\Order\Model\Pricing\FixedPerUnitCommission;

describe('Commission Models', function () {
    describe('FixedCommission', function () {
        it('returns the fixed amount regardless of quantity and price', function () {
            $commission = new FixedCommission('5.00');

            expect($commission->calculate('10', '50000'))->toBe('5.00')
                ->and($commission->calculate('100', '1000'))->toBe('5.00')
                ->and($commission->calculate('1', '1'))->toBe('5.00');
        });

        it('returns zero when amount is zero', function () {
            $commission = new FixedCommission('0');

            expect($commission->calculate('10', '50000'))->toBe('0');
        });
    });

    describe('PercentageCommission', function () {
        it('calculates commission as percentage of trade value', function () {
            $commission = new PercentageCommission('0.001');

            $result = $commission->calculate('10', '50000');

            $expectedTradeValue = bcmul('10', '50000');
            $expected = bcmul($expectedTradeValue, '0.001');

            expect($result)->toBe($expected);
        });

        it('returns zero for zero quantity', function () {
            $commission = new PercentageCommission('0.001');

            expect($commission->calculate('0', '50000'))->toBe('0');
        });

        it('returns zero for zero price', function () {
            $commission = new PercentageCommission('0.001');

            expect($commission->calculate('10', '0'))->toBe('0');
        });

        it('applies 0.1% rate correctly', function () {
            $commission = new PercentageCommission('0.001');

            $result = $commission->calculate('2', '50000');
            $expected = bcmul(bcmul('2', '50000'), '0.001');

            expect($result)->toBe($expected);
        });
    });

    describe('FixedPerUnitCommission', function () {
        it('calculates commission as rate multiplied by quantity', function () {
            $commission = new FixedPerUnitCommission('0.50');

            $result = $commission->calculate('10', '50000');

            expect($result)->toBe(bcmul('10', '0.50'));
        });

        it('returns zero for zero quantity', function () {
            $commission = new FixedPerUnitCommission('0.50');

            expect($commission->calculate('0', '50000'))->toBe('0');
        });

        it('ignores price parameter', function () {
            $commission = new FixedPerUnitCommission('1.00');

            $result1 = $commission->calculate('5', '100');
            $result2 = $commission->calculate('5', '99999');

            expect($result1)->toBe($result2)
                ->and($result1)->toBe(bcmul('5', '1.00'));
        });
    });
});
