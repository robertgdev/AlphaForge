<?php

namespace App\AlphaForge\Common\Util;

final class Math
{
    /**
     * Calculates the arithmetic mean of an array of string numbers.
     */
    public static function mean(array $stringValues, int $scale): string
    {
        $count = count($stringValues);
        if ($count === 0) {
            return bcadd('0', '0', $scale);
        }

        $sum = '0';
        foreach ($stringValues as $value) {
            $sum = bcadd($sum, $value, $scale + 2);
        }

        return bcdiv($sum, (string) $count, $scale);
    }

    /**
     * Calculates the covariance between two arrays of string numbers.
     */
    public static function covariance(array $stringValues1, array $stringValues2, int $scale, bool $sample = true): string
    {
        $count = count($stringValues1);
        if ($count !== count($stringValues2) || ($sample && $count < 2) || (! $sample && $count < 1)) {
            return bcadd('0', '0', $scale);
        }

        $internalScale = $scale + 4;
        $mean1 = self::mean($stringValues1, $internalScale);
        $mean2 = self::mean($stringValues2, $internalScale);

        $sumOfProducts = '0';
        for ($i = 0; $i < $count; $i++) {
            $dev1 = bcsub($stringValues1[$i], $mean1, $internalScale);
            $dev2 = bcsub($stringValues2[$i], $mean2, $internalScale);
            $sumOfProducts = bcadd($sumOfProducts, bcmul($dev1, $dev2, $internalScale), $internalScale);
        }

        $denominator = $sample ? (string) ($count - 1) : (string) $count;
        if (bccomp($denominator, '0', 0) === 0) {
            return bcadd('0', '0', $scale);
        }

        return bcdiv($sumOfProducts, $denominator, $scale);
    }

    /**
     * Calculates the variance of an array of string numbers.
     */
    public static function variance(array $stringValues, int $scale, bool $sample = true): string
    {
        $count = count($stringValues);

        if (($sample && $count < 2) || (! $sample && $count < 1)) {
            return bcadd('0', '0', $scale);
        }

        $internalScale = $scale + 4;
        $mean = self::mean($stringValues, $internalScale);

        $sumOfSquares = '0';
        foreach ($stringValues as $value) {
            $deviation = bcsub($value, $mean, $internalScale);
            $sumOfSquares = bcadd($sumOfSquares, bcpow($deviation, '2', $internalScale), $internalScale);
        }

        $denominator = $sample ? (string) ($count - 1) : (string) $count;

        if (bccomp($denominator, '0', 0) === 0) {
            return bcadd('0', '0', $scale);
        }

        return bcdiv($sumOfSquares, $denominator, $scale);
    }

    /**
     * Calculates the standard deviation of an array of string numbers.
     */
    public static function standardDeviation(array $stringValues, int $scale, bool $sample = true): string
    {
        $count = count($stringValues);
        if (($sample && $count < 2) || (! $sample && $count < 1)) {
            return bcadd('0', '0', $scale);
        }

        $variance = self::variance($stringValues, $scale + 4, $sample);

        if (bccomp($variance, '0', $scale + 4) < 0 || bccomp($variance, '0', $scale + 4) === 0) {
            return bcadd('0', '0', $scale);
        }

        return bcsqrt($variance, $scale);
    }

    /**
     * Calculates percentage of a value relative to a base.
     *
     * @param  string  $value  The value
     * @param  string  $base  The base value
     * @param  int  $scale  Scale for bcmath
     * @return string The percentage as a decimal (e.g., "0.1" for 10%)
     */
    public static function percentage(string $value, string $base, int $scale = 10): string
    {
        if (bccomp($base, '0', $scale) === 0) {
            return '0';
        }
        return bcdiv($value, $base, $scale);
    }
}