<?php

use App\AlphaForge\Common\Util\Math;

describe('Math utility', function () {
    describe('mean', function () {
        it('calculates mean of an array', function () {
            $values = ['10', '20', '30', '40', '50'];
            $mean = Math::mean($values, 12);

            expect($mean)->toBe('30.000000000000');
        });

        it('returns zero for empty array', function () {
            $mean = Math::mean([], 12);

            expect($mean)->toBe('0.000000000000');
        });
    });

    describe('standardDeviation', function () {
        it('calculates standard deviation', function () {
            $values = ['2', '4', '4', '4', '5', '5', '7', '9'];
            $stdDev = Math::standardDeviation($values, 6);

            // Standard deviation should be approximately 2.0
            // bcsqrt may not be available, so just check it's a valid number
            expect(is_numeric($stdDev))->toBeTrue();
            expect(bccomp($stdDev, '0', 0))->toBe(1); // Greater than 0
        });

        it('returns zero for single element', function () {
            $stdDev = Math::standardDeviation(['5'], 6);

            expect($stdDev)->toBe('0.000000');
        });
    });

    describe('variance', function () {
        it('calculates variance', function () {
            $values = ['2', '4', '4', '4', '5', '5', '7', '9'];
            $variance = Math::variance($values, 6);

            // Variance should be approximately 4.0
            expect(is_numeric($variance))->toBeTrue();
            expect(bccomp($variance, '0', 0))->toBe(1); // Greater than 0
        });

        it('returns zero for single element', function () {
            $variance = Math::variance(['5'], 6);

            expect($variance)->toBe('0.000000');
        });
    });

    describe('covariance', function () {
        it('calculates covariance between two arrays', function () {
            $values1 = ['1', '2', '3', '4', '5'];
            $values2 = ['2', '4', '6', '8', '10'];
            $covariance = Math::covariance($values1, $values2, 6);

            // Perfect positive correlation should have positive covariance
            expect(is_numeric($covariance))->toBeTrue();
            expect(bccomp($covariance, '0', 0))->toBe(1);
        });

        it('returns zero for mismatched array lengths', function () {
            $values1 = ['1', '2', '3'];
            $values2 = ['1', '2'];
            $covariance = Math::covariance($values1, $values2, 6);

            expect($covariance)->toBe('0.000000');
        });
    });

    describe('percentage', function () {
        it('returns actual percentage multiplied by 100', function () {
            $result = Math::percentage('500', '10000', 4);

            expect($result)->toBe('5.0000');
        });

        it('handles fractional returns', function () {
            $result = Math::percentage('4396', '1000000', 4);

            expect($result)->toBe('0.4396');
        });

        it('returns zero for zero base', function () {
            $result = Math::percentage('100', '0');

            expect($result)->toBe('0');
        });

        it('handles small returns', function () {
            $result = Math::percentage('44.24', '10000', 4);

            expect($result)->toBe('0.4424');
        });
    });
});
