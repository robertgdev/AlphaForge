<?php

use App\AlphaForge\Condition\CrossCondition;
use App\AlphaForge\Condition\ComparisonCondition;
use App\AlphaForge\Condition\TrendCondition;
use App\AlphaForge\TimeSeries\ArrayTimeSeries;

it('returns correct values via get()', function () {
    $ts = new ArrayTimeSeries([1.0, 2.0, 3.0, null, 5.0]);

    expect($ts->get(0))->toBe(1.0)
        ->and($ts->get(2))->toBe(3.0)
        ->and($ts->get(3))->toBeNull()
        ->and($ts->get(10))->toBeNull();
});

it('returns correct toArray()', function () {
    $data = [1.0, 2.0, null, 4.0];
    $ts = new ArrayTimeSeries($data);

    expect($ts->toArray())->toBe($data);
});

it('returns correct count()', function () {
    $ts = new ArrayTimeSeries([1.0, 2.0, 3.0]);

    expect($ts->count())->toBe(3);
});

it('converts int values to float', function () {
    $ts = new ArrayTimeSeries([1, 2, 3]);

    expect($ts->get(0))->toBe(1.0)
        ->and($ts->get(1))->toBe(2.0);
});

it('crossesAbove returns CrossCondition', function () {
    $a = new ArrayTimeSeries([1.0, 2.0]);
    $b = new ArrayTimeSeries([2.0, 1.0]);

    $cond = $a->crossesAbove($b);
    expect($cond)->toBeInstanceOf(CrossCondition::class);
});

it('crossesBelow returns CrossCondition', function () {
    $a = new ArrayTimeSeries([2.0, 1.0]);
    $b = new ArrayTimeSeries([1.0, 2.0]);

    $cond = $a->crossesBelow($b);
    expect($cond)->toBeInstanceOf(CrossCondition::class);
});

it('isAbove returns ComparisonCondition', function () {
    $ts = new ArrayTimeSeries([5.0]);

    expect($ts->isAbove(3.0))->toBeInstanceOf(ComparisonCondition::class)
        ->and($ts->isAbove($ts))->toBeInstanceOf(ComparisonCondition::class);
});

it('isBelow returns ComparisonCondition', function () {
    $ts = new ArrayTimeSeries([1.0]);

    expect($ts->isBelow(3.0))->toBeInstanceOf(ComparisonCondition::class)
        ->and($ts->isBelow($ts))->toBeInstanceOf(ComparisonCondition::class);
});

it('isRising returns TrendCondition', function () {
    $ts = new ArrayTimeSeries([1.0, 2.0, 3.0]);

    expect($ts->isRising())->toBeInstanceOf(TrendCondition::class);
});

it('isFalling returns TrendCondition', function () {
    $ts = new ArrayTimeSeries([3.0, 2.0, 1.0]);

    expect($ts->isFalling())->toBeInstanceOf(TrendCondition::class);
});
