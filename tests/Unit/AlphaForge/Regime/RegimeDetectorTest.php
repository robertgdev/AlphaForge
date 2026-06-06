<?php

use App\AlphaForge\Regime\RegimeDetector;
use App\AlphaForge\Regime\RegimeSeries;

describe('RegimeDetector', function () {
    describe('detectAdx()', function () {
        it('classifies trending uptrend as bull', function () {
            $n = 100;
            $close = [];
            $high = [];
            $low = [];
            for ($i = 0; $i < $n; $i++) {
                $close[] = 100.0 + $i * 0.5; // steady uptrend
                $high[] = $close[$i] + 2.0;
                $low[] = $close[$i] - 2.0;
            }

            $regimes = RegimeDetector::detectAdx($high, $low, $close, 14, 20.0);

            $valid = array_filter($regimes, fn ($v) => $v !== null);
            expect($valid)->not->toBeEmpty();
            // In a strong uptrend, at least some bars should be 'bull'
            $bullCount = count(array_filter($valid, fn ($v) => $v === 'bull'));
            expect($bullCount)->toBeGreaterThan(0);
        });

        it('classifies ranging market as sideways', function () {
            $n = 300;
            $close = [];
            $high = [];
            $low = [];
            $price = 100.0;
            for ($i = 0; $i < $n; $i++) {
                $price += (mt_rand(-100, 100) / 1000.0); // small random drift
                $close[] = $price;
                $high[] = $price + 0.3;
                $low[] = $price - 0.3;
            }

            $regimes = RegimeDetector::detectAdx($high, $low, $close, 14, 25.0);

            $valid = array_filter($regimes, fn ($v) => $v !== null);
            expect($valid)->not->toBeEmpty();

            $sidewaysCount = count(array_filter($valid, fn ($v) => $v === 'sideways'));
            expect($sidewaysCount)->toBeGreaterThan(0);
        });

        it('returns null for warmup bars', function () {
            $n = 30;
            $close = array_fill(0, $n, 100.0);
            $high = array_fill(0, $n, 102.0);
            $low = array_fill(0, $n, 98.0);

            $regimes = RegimeDetector::detectAdx($high, $low, $close, 14);

            // First 28 bars (14*2) should be null
            for ($i = 0; $i < 28; $i++) {
                expect($regimes[$i])->toBeNull();
            }
        });

        it('correct count of returned bars', function () {
            $n = 50;
            $close = array_fill(0, $n, 100.0);
            $high = array_fill(0, $n, 102.0);
            $low = array_fill(0, $n, 98.0);

            $regimes = RegimeDetector::detectAdx($high, $low, $close, 14);

            expect($regimes)->toHaveCount($n);
        });

        it('works with EMA moving average type', function () {
            $n = 100;
            $close = [];
            $high = [];
            $low = [];
            for ($i = 0; $i < $n; $i++) {
                $close[] = 100.0 + $i * 0.3;
                $high[] = $close[$i] + 2.0;
                $low[] = $close[$i] - 2.0;
            }

            $regimesSma = RegimeDetector::detectAdx($high, $low, $close, 14, maType: TA_MA_TYPE_SMA);
            $regimesEma = RegimeDetector::detectAdx($high, $low, $close, 14, maType: TA_MA_TYPE_EMA);

            expect($regimesSma)->toHaveCount($n);
            expect($regimesEma)->toHaveCount($n);
            // EMA responds faster — should produce results that are not always identical
        });

        it('only produces valid regime labels', function () {
            $n = 50;
            $close = [];
            $high = [];
            $low = [];
            for ($i = 0; $i < $n; $i++) {
                $close[] = 100.0 + $i * 0.2;
                $high[] = $close[$i] + 1.5;
                $low[] = $close[$i] - 1.5;
            }

            $regimes = RegimeDetector::detectAdx($high, $low, $close);
            $valid = array_values(array_filter($regimes, fn ($v) => $v !== null));

            foreach ($valid as $regime) {
                expect(in_array($regime, ['bull', 'bear', 'sideways'], true))->toBeTrue();
            }
        });
    });

    describe('detectTrend()', function () {
        it('classifies price above MA as bull', function () {
            $n = 250;
            $close = [];
            for ($i = 0; $i < $n; $i++) {
                $close[] = 100.0 + $i * 0.3;
            }

            $regimes = RegimeDetector::detectTrend($close, 50);

            $valid = array_filter($regimes, fn ($v) => $v !== null);
            $bullCount = count(array_filter($valid, fn ($v) => $v === 'bull'));
            expect($bullCount)->toBeGreaterThan(0);
        });

        it('classifies price below MA as bear', function () {
            $n = 250;
            $close = [];
            for ($i = 0; $i < $n; $i++) {
                $close[] = 100.0 - $i * 0.3;
            }

            $regimes = RegimeDetector::detectTrend($close, 50);

            $valid = array_filter($regimes, fn ($v) => $v !== null);
            $bearCount = count(array_filter($valid, fn ($v) => $v === 'bear'));
            expect($bearCount)->toBeGreaterThan(0);
        });

        it('handles different MA types', function () {
            $n = 250;
            $close = [];
            for ($i = 0; $i < $n; $i++) {
                $close[] = 100.0 + $i * 0.4;
            }

            $ema = RegimeDetector::detectTrend($close, 50, TA_MA_TYPE_EMA);
            $wma = RegimeDetector::detectTrend($close, 50, TA_MA_TYPE_WMA);

            expect($ema)->toHaveCount($n);
            expect($wma)->toHaveCount($n);
        });

        it('returns null for warmup bars', function () {
            $n = 60;
            $close = array_fill(0, $n, 100.0);

            $regimes = RegimeDetector::detectTrend($close, 50);

            for ($i = 0; $i < 51; $i++) {
                expect($regimes[$i])->toBeNull();
            }
            // bar 51 onwards should have a value (all equal → 'bear' since not > MA)
            expect($regimes[51])->toBe('bear');
        });
    });

    describe('detectVolatility()', function () {
        it('classifies high variance data as high_vol', function () {
            $n = 100;
            $close = [];
            $high = [];
            $low = [];
            for ($i = 0; $i < $n; $i++) {
                $close[] = 100.0;
                $high[] = 100.0 + (($i % 10 === 0) ? 20.0 : 1.0); // occasional spikes
                $low[] = 100.0 - (($i % 10 === 0) ? 20.0 : 1.0);
            }

            $regimes = RegimeDetector::detectVolatility($high, $low, $close, 14, 0.70, 0.30);

            $valid = array_filter($regimes, fn ($v) => $v !== null);
            $highCount = count(array_filter($valid, fn ($v) => $v === 'high_vol'));
            expect($highCount)->toBeGreaterThan(0);
        });

        it('classifies all-equal data as low_vol', function () {
            $n = 50;
            $close = array_fill(0, $n, 100.0);
            $high = array_fill(0, $n, 100.0);
            $low = array_fill(0, $n, 100.0);

            $regimes = RegimeDetector::detectVolatility($high, $low, $close, 14);

            $valid = array_values(array_filter($regimes, fn ($v) => $v !== null));
            expect($valid)->not->toBeEmpty();
            foreach ($valid as $v) {
                expect($v)->toBe('low_vol');
            }
        });

        it('returns only valid volatility labels', function () {
            $n = 50;
            $close = [];
            $high = [];
            $low = [];
            for ($i = 0; $i < $n; $i++) {
                $close[] = 100.0 + sin($i * 0.5) * 5.0;
                $high[] = $close[$i] + abs(sin($i)) * 3.0;
                $low[] = $close[$i] - abs(sin($i)) * 2.0;
            }

            $regimes = RegimeDetector::detectVolatility($high, $low, $close);
            $valid = array_values(array_filter($regimes, fn ($v) => $v !== null));

            foreach ($valid as $regime) {
                expect(in_array($regime, ['high_vol', 'normal_vol', 'low_vol'], true))->toBeTrue();
            }
        });
    });

    describe('detectCombined()', function () {
        it('produces trend_vol labels', function () {
            $n = 100;
            $close = [];
            $high = [];
            $low = [];
            for ($i = 0; $i < $n; $i++) {
                $close[] = 100.0 + $i * 0.5;
                $high[] = $close[$i] + 2.0;
                $low[] = $close[$i] - 2.0;
            }

            $regimes = RegimeDetector::detectCombined($high, $low, $close, 14);

            $valid = array_filter($regimes, fn ($v) => $v !== null);
            expect($valid)->not->toBeEmpty();

            // Labels should be in format "trend_vol" like "bull_normal_vol"
            $first = $valid[array_key_first($valid)];
            expect(str_contains($first, '_'))->toBeTrue();
        });

        it('passes maType through to trend detection', function () {
            $n = 100;
            $close = [];
            $high = [];
            $low = [];
            for ($i = 0; $i < $n; $i++) {
                $close[] = 100.0 + $i * 0.3;
                $high[] = $close[$i] + 2.0;
                $low[] = $close[$i] - 2.0;
            }

            $sma = RegimeDetector::detectCombined($high, $low, $close, 14, TA_MA_TYPE_SMA);
            $ema = RegimeDetector::detectCombined($high, $low, $close, 14, TA_MA_TYPE_EMA);

            expect($sma)->toHaveCount($n);
            expect($ema)->toHaveCount($n);
        });
    });

    describe('maTypeLabel()', function () {
        it('returns correct labels', function () {
            expect(RegimeDetector::maTypeLabel(0))->toBe('SMA');
            expect(RegimeDetector::maTypeLabel(1))->toBe('EMA');
            expect(RegimeDetector::maTypeLabel(2))->toBe('WMA');
            expect(RegimeDetector::maTypeLabel(8))->toBe('T3');
        });

        it('returns fallback for unknown type', function () {
            expect(RegimeDetector::maTypeLabel(99))->toBe('MA(99)');
        });
    });
});

describe('RegimeSeries', function () {
    it('returns regime label by index', function () {
        $series = new RegimeSeries(['bull', 'bull', 'bear', 'sideways']);

        expect($series->get(0))->toBe('bull');
        expect($series->get(2))->toBe('bear');
        expect($series->get(3))->toBe('sideways');
    });

    it('returns null for out of bounds index', function () {
        $series = new RegimeSeries(['bull', 'bear']);

        expect($series->get(5))->toBeNull();
    });

    it('returns unique sorted labels excluding null', function () {
        $series = new RegimeSeries(['bear', null, 'bull', null, 'bear', 'sideways']);

        expect($series->labels())->toBe(['bear', 'bull', 'sideways']);
    });

    it('returns correct count', function () {
        $series = new RegimeSeries(['bull', 'bear', 'bull']);

        expect($series->count())->toBe(3);
    });

    it('toArray returns full array including nulls', function () {
        $data = ['bull', null, 'bear'];
        $series = new RegimeSeries($data);

        expect($series->toArray())->toBe($data);
        expect(count($series->toArray()))->toBe(3);
    });

    it('handles empty series', function () {
        $series = new RegimeSeries([]);

        expect($series->count())->toBe(0);
        expect($series->labels())->toBe([]);
        expect($series->get(0))->toBeNull();
    });

    it('filters out all null values from labels', function () {
        $series = new RegimeSeries([null, null, null]);

        expect($series->labels())->toBe([]);
    });
});