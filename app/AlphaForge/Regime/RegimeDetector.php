<?php

namespace App\AlphaForge\Regime;

use TaLibHybrid\TaLibHybrid;

class RegimeDetector
{
    /**
     * Detect market regime using ADX for trend strength + MA for direction.
     *
     * Classification logic:
     *   - ADX < adxThreshold (default 20) → 'sideways'
     *   - ADX >= adxThreshold AND close > MA(period) → 'bull'
     *   - ADX >= adxThreshold AND close <= MA(period) → 'bear'
     *
     * Bars with insufficient data for ADX/MA computation return null.
     *
     * @param  array<int, float>  $high
     * @param  array<int, float>  $low
     * @param  array<int, float>  $close
     * @param  int  $period  MA lookback and ADX period
     * @param  float  $adxThreshold  Threshold above which market is considered trending
     * @param  int  $maType  Moving average type (TA_MA_TYPE_SMA, TA_MA_TYPE_EMA, etc.)
     * @return array<int, string|null>
     */
    public static function detectAdx(
        array $high,
        array $low,
        array $close,
        int $period = 14,
        float $adxThreshold = 20.0,
        int $maType = 0,
    ): array {
        $total = count($close);
        $adx = TaLibHybrid::adx($high, $low, $close, $period);
        $ma = TaLibHybrid::ma($close, $period, $maType);

        $regimes = [];
        $warmup = $period * 2;

        for ($i = 0; $i < $total; $i++) {
            if ($i < $warmup) {
                $regimes[$i] = null;

                continue;
            }

            $adxVal = $adx[$i] ?? null;
            $maVal = $ma[$i] ?? null;
            $closeVal = $close[$i];

            if ($adxVal === null || $maVal === null) {
                $regimes[$i] = null;

                continue;
            }

            if ($adxVal < $adxThreshold) {
                $regimes[$i] = 'sideways';
            } elseif ($closeVal > $maVal) {
                $regimes[$i] = 'bull';
            } else {
                $regimes[$i] = 'bear';
            }
        }

        return $regimes;
    }

    /**
     * Detect trend regime using moving average relative to price.
     *
     *   - close > MA(period) → 'bull'
     *   - close <= MA(period) → 'bear'
     *
     * @param  array<int, float>  $close
     * @param  int  $period  MA lookback period
     * @param  int  $maType  Moving average type (TA_MA_TYPE_SMA, TA_MA_TYPE_EMA, etc.)
     * @return array<int, string|null>
     */
    public static function detectTrend(
        array $close,
        int $period = 200,
        int $maType = 0,
    ): array {
        $total = count($close);
        $ma = TaLibHybrid::ma($close, $period, $maType);
        $warmup = $period + 1;

        $regimes = [];
        for ($i = 0; $i < $total; $i++) {
            if ($i < $warmup) {
                $regimes[$i] = null;

                continue;
            }

            $maVal = $ma[$i] ?? null;
            if ($maVal === null) {
                $regimes[$i] = null;

                continue;
            }

            $regimes[$i] = $close[$i] > $maVal ? 'bull' : 'bear';
        }

        return $regimes;
    }

    /**
     * Detect volatility regime using ATR ranking.
     *
     * Classifies each bar's ATR value into a percentile bucket relative
     * to the full data range:
     *   - Top 30% of ATR values → 'high_vol'
     *   - Middle 40% of ATR values → 'normal_vol'
     *   - Bottom 30% of ATR values → 'low_vol'
     *
     * @param  array<int, float>  $high
     * @param  array<int, float>  $low
     * @param  array<int, float>  $close
     * @param  int  $period  ATR lookback period
     * @param  float  $highPercentile  Upper threshold percentile (0-1)
     * @param  float  $lowPercentile  Lower threshold percentile (0-1)
     * @return array<int, string|null>
     */
    public static function detectVolatility(
        array $high,
        array $low,
        array $close,
        int $period = 14,
        float $highPercentile = 0.70,
        float $lowPercentile = 0.30,
    ): array {
        $total = count($close);
        $atr = TaLibHybrid::atr($high, $low, $close, $period);
        $warmup = $period + 1;

        $validAtrs = [];
        for ($i = $warmup; $i < $total; $i++) {
            $v = $atr[$i] ?? null;
            if ($v !== null && $v >= 0) {
                $validAtrs[] = $v;
            }
        }

        sort($validAtrs);
        $n = count($validAtrs);

        if ($n === 0) {
            return array_fill(0, $total, null);
        }

        $highCutoff = $validAtrs[(int) floor($n * $highPercentile)] ?? $validAtrs[$n - 1];
        $lowCutoff = $validAtrs[(int) floor($n * $lowPercentile)] ?? $validAtrs[0];

        if ($highCutoff <= 0 || $highCutoff === $lowCutoff) {
            for ($i = 0; $i < $total; $i++) {
                $regimes[$i] = $i < $warmup ? null : 'low_vol';
            }

            return $regimes;
        }

        $regimes = [];
        for ($i = 0; $i < $total; $i++) {
            if ($i < $warmup) {
                $regimes[$i] = null;

                continue;
            }

            $v = $atr[$i] ?? null;
            if ($v === null) {
                $regimes[$i] = null;

                continue;
            }

            if ($v >= $highCutoff) {
                $regimes[$i] = 'high_vol';
            } elseif ($v >= $lowCutoff) {
                $regimes[$i] = 'normal_vol';
            } else {
                $regimes[$i] = 'low_vol';
            }
        }

        return $regimes;
    }

    /**
     * Combined regime: trend (ADX-based) + volatility classification.
     *
     * Returns labels like 'bull_low_vol', 'bear_high_vol', 'sideways_normal_vol', etc.
     * This is the richest classification — captures both market direction and
     * volatility environment in a single label.
     *
     * @param  array<int, float>  $high
     * @param  array<int, float>  $low
     * @param  array<int, float>  $close
     * @param  int  $period  Lookback for ADX/MA/ATR
     * @param  int  $maType  Moving average type (TA_MA_TYPE_SMA, TA_MA_TYPE_EMA, etc.)
     * @return array<int, string|null>
     */
    public static function detectCombined(
        array $high,
        array $low,
        array $close,
        int $period = 14,
        int $maType = 0,
    ): array {
        $trend = self::detectAdx($high, $low, $close, $period, maType: $maType);
        $vol = self::detectVolatility($high, $low, $close, $period);

        $regimes = [];
        foreach ($trend as $i => $t) {
            $v = $vol[$i] ?? null;
            if ($t === null || $v === null) {
                $regimes[$i] = null;

                continue;
            }

            $regimes[$i] = "{$t}_{$v}";
        }

        return $regimes;
    }

    /**
     * Human-readable label for a moving average type constant.
     */
    public static function maTypeLabel(int $maType): string
    {
        return match ($maType) {
            0 => 'SMA',
            1 => 'EMA',
            2 => 'WMA',
            3 => 'DEMA',
            4 => 'TEMA',
            5 => 'TRIMA',
            6 => 'KAMA',
            7 => 'MAMA',
            8 => 'T3',
            default => "MA({$maType})",
        };
    }
}
