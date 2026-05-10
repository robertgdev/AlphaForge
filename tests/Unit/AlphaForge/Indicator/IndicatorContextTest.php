<?php

use App\AlphaForge\Backtesting\Model\BacktestCursor;
use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Condition\ConditionInterface;
use App\AlphaForge\Indicator\Model\IndicatorContext;
use App\AlphaForge\TimeSeries\ArrayTimeSeries;

function buildOhlcv(int $bars = 50): OhlcvSeries
{
    $cursor = new BacktestCursor;
    $timestamps = [];
    $opens = [];
    $highs = [];
    $lows = [];
    $closes = [];
    $volumes = [];

    $baseTime = 1700000000;
    for ($i = 0; $i < $bars; $i++) {
        $timestamps[] = $baseTime + ($i * 3600);
        $price = 100 + sin($i * 0.3) * 10 + $i * 0.5;
        $opens[] = round($price, 2);
        $highs[] = round($price + abs(sin($i * 0.7)) * 3, 2);
        $lows[] = round($price - abs(cos($i * 0.7)) * 3, 2);
        $closes[] = round($price + sin($i * 0.2) * 2, 2);
        $volumes[] = 1000 + $i * 10;
    }

    return new OhlcvSeries([
        'timestamp' => $timestamps,
        'open' => $opens,
        'high' => $highs,
        'low' => $lows,
        'close' => $closes,
        'volume' => $volumes,
    ], $cursor);
}

describe('IndicatorContext::priceSeries', function () {
    it('returns ArrayTimeSeries for close', function () {
        $ctx = new IndicatorContext(buildOhlcv());
        $closes = $ctx->priceSeries('close');

        expect($closes)->toBeInstanceOf(ArrayTimeSeries::class)
            ->and($closes->count())->toBe(50);
    });

    it('returns ArrayTimeSeries for each valid field', function () {
        $ohlcv = buildOhlcv();
        $ctx = new IndicatorContext($ohlcv);

        foreach (['open', 'high', 'low', 'close', 'volume', 'hlc3'] as $field) {
            $series = $ctx->priceSeries($field);
            expect($series)->toBeInstanceOf(ArrayTimeSeries::class)
                ->and($series->count())->toBe(50);
        }
    });

    it('returns values matching ohlcv source for close', function () {
        $ohlcv = buildOhlcv();
        $ctx = new IndicatorContext($ohlcv);

        $closes = $ctx->priceSeries('close');
        $rawCloses = $ohlcv->getCloses()->getVector()->toArray();

        for ($i = 0; $i < 50; $i++) {
            expect(abs($closes->get($i) - (float) $rawCloses[$i]))->toBeLessThan(0.0001);
        }
    });

    it('returns values matching ohlcv source for hlc3', function () {
        $ohlcv = buildOhlcv();
        $ctx = new IndicatorContext($ohlcv);

        $hlc3 = $ctx->priceSeries('hlc3');
        $rawHlc3 = $ohlcv->getHlc3()->getVector()->toArray();

        for ($i = 0; $i < 50; $i++) {
            expect(abs($hlc3->get($i) - (float) $rawHlc3[$i]))->toBeLessThan(0.0001);
        }
    });

    it('caches and returns same instance', function () {
        $ctx = new IndicatorContext(buildOhlcv());

        $a = $ctx->priceSeries('high');
        $b = $ctx->priceSeries('high');

        expect($a)->toBe($b);
    });

    it('throws for invalid field', function () {
        $ctx = new IndicatorContext(buildOhlcv());
        $ctx->priceSeries('invalid');
    })->throws(InvalidArgumentException::class);

    it('different fields return different instances', function () {
        $ctx = new IndicatorContext(buildOhlcv());

        $highs = $ctx->priceSeries('high');
        $lows = $ctx->priceSeries('low');

        expect($highs)->not->toBe($lows);
    });
});

describe('IndicatorContext::indicator with inputOverrides', function () {
    it('sma of highs differs from sma of closes', function () {
        $ohlcv = buildOhlcv(30);
        $ctx = new IndicatorContext($ohlcv);

        $smaClose = $ctx->sma(10);
        $highs = $ctx->priceSeries('high');
        $smaHigh = $ctx->indicator('sma', ['period' => 10], ['close' => $highs]);

        $lastClose = $smaClose->get(29);
        $lastHigh = $smaHigh->get(29);

        expect($lastClose)->not->toBeNull()
            ->and($lastHigh)->not->toBeNull()
            ->and($lastHigh)->toBeGreaterThan($lastClose);
    });

    it('caches separately for different input overrides', function () {
        $ohlcv = buildOhlcv(30);
        $ctx = new IndicatorContext($ohlcv);

        $smaClose = $ctx->sma(10);
        $highs = $ctx->priceSeries('high');
        $smaHigh = $ctx->indicator('sma', ['period' => 10], ['close' => $highs]);

        $smaCloseAgain = $ctx->sma(10);

        expect($smaClose)->toBe($smaCloseAgain)
            ->and($smaClose)->not->toBe($smaHigh);
    });

    it('non-overridden inputs still resolve from ohlcv', function () {
        $ohlcv = buildOhlcv(30);
        $ctx = new IndicatorContext($ohlcv);

        $closes = $ctx->priceSeries('close');
        $smaOverride = $ctx->indicator('sma', ['period' => 10], ['close' => $closes]);
        $smaDefault = $ctx->sma(10);

        $lastOverride = $smaOverride->get(29);
        $lastDefault = $smaDefault->get(29);

        expect($lastOverride)->not->toBeNull()
            ->and($lastDefault)->not->toBeNull()
            ->and(abs($lastOverride - $lastDefault))->toBeLessThan(0.0001);
    });
});

describe('IndicatorContext convenience methods with $input', function () {
    it('sma with input=high uses highs', function () {
        $ohlcv = buildOhlcv(30);
        $ctx = new IndicatorContext($ohlcv);

        $smaHigh = $ctx->sma(10, input: 'high');
        $highs = $ctx->priceSeries('high');
        $smaExplicit = $ctx->indicator('sma', ['period' => 10], ['close' => $highs]);

        $lastConvenience = $smaHigh->get(29);
        $lastExplicit = $smaExplicit->get(29);

        expect($lastConvenience)->not->toBeNull()
            ->and(abs($lastConvenience - $lastExplicit))->toBeLessThan(0.0001);
    });

    it('ema with input=low uses lows', function () {
        $ohlcv = buildOhlcv(30);
        $ctx = new IndicatorContext($ohlcv);

        $emaLow = $ctx->ema(10, input: 'low');
        $lows = $ctx->priceSeries('low');
        $emaExplicit = $ctx->indicator('ema', ['period' => 10], ['close' => $lows]);

        $lastConvenience = $emaLow->get(29);
        $lastExplicit = $emaExplicit->get(29);

        expect($lastConvenience)->not->toBeNull()
            ->and(abs($lastConvenience - $lastExplicit))->toBeLessThan(0.0001);
    });

    it('rsi with input=close same as default', function () {
        $ohlcv = buildOhlcv(30);
        $ctx = new IndicatorContext($ohlcv);

        $rsiExplicit = $ctx->rsi(14, input: 'close');
        $rsiDefault = $ctx->rsi(14);

        $lastExplicit = $rsiExplicit->get(29);
        $lastDefault = $rsiDefault->get(29);

        expect($lastExplicit)->not->toBeNull()
            ->and($lastDefault)->not->toBeNull()
            ->and(abs($lastExplicit - $lastDefault))->toBeLessThan(0.0001);
    });

    it('sma without input produces same values as sma with input=close', function () {
        $ohlcv = buildOhlcv(30);
        $ctx = new IndicatorContext($ohlcv);

        $smaDefault = $ctx->sma(10);
        $smaClose = $ctx->sma(10, input: 'close');

        expect($smaDefault->count())->toBe($smaClose->count());

        for ($i = 0; $i < 30; $i++) {
            $default = $smaDefault->get($i);
            $close = $smaClose->get($i);

            if ($default === null || $close === null) {
                expect($default)->toBe($close);
            } else {
                expect(abs($default - $close))->toBeLessThan(0.0001);
            }
        }
    });
});

describe('IndicatorContext::priceSeries with conditions', function () {
    it('price series works with condition methods', function () {
        $ohlcv = buildOhlcv(30);
        $ctx = new IndicatorContext($ohlcv);

        $highs = $ctx->priceSeries('high');
        $sma = $ctx->sma(10);

        $crossCondition = $highs->crossesAbove($sma);
        $compareCondition = $highs->isAbove($sma);
        $trendCondition = $highs->isRising(3);

        expect($crossCondition)->toBeInstanceOf(ConditionInterface::class)
            ->and($compareCondition)->toBeInstanceOf(ConditionInterface::class)
            ->and($trendCondition)->toBeInstanceOf(ConditionInterface::class);
    });

    it('evaluateAll on price-vs-indicator condition produces correct length', function () {
        $ohlcv = buildOhlcv(30);
        $ctx = new IndicatorContext($ohlcv);

        $highs = $ctx->priceSeries('high');
        $sma = $ctx->sma(10);

        $condition = $highs->isAbove($sma);
        $results = $condition->evaluateAll(30);

        expect($results)->toHaveCount(30);
    });
});
