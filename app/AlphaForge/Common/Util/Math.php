<?php

namespace App\AlphaForge\Common\Util;

/**
 * @deprecated Use \RobertGDev\AlphaforgeStatistics\Math\Math instead.
 * For now this delegates all calls to the package Math class.
 */
final class Math
{
    public static function mean(array $stringValues, int $scale): string
    {
        return \RobertGDev\AlphaforgeStatistics\Math\Math::mean($stringValues, $scale);
    }

    public static function covariance(array $stringValues1, array $stringValues2, int $scale, bool $sample = true): string
    {
        return \RobertGDev\AlphaforgeStatistics\Math\Math::covariance($stringValues1, $stringValues2, $scale, $sample);
    }

    public static function variance(array $stringValues, int $scale, bool $sample = true): string
    {
        return \RobertGDev\AlphaforgeStatistics\Math\Math::variance($stringValues, $scale, $sample);
    }

    public static function standardDeviation(array $stringValues, int $scale, bool $sample = true): string
    {
        return \RobertGDev\AlphaforgeStatistics\Math\Math::standardDeviation($stringValues, $scale, $sample);
    }

    public static function percentage(string $value, string $base, int $scale = 10): string
    {
        return \RobertGDev\AlphaforgeStatistics\Math\Math::percentage($value, $base, $scale);
    }
}