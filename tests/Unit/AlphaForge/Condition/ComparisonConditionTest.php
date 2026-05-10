<?php

use App\AlphaForge\Condition\ComparisonCondition;
use App\AlphaForge\TimeSeries\ArrayTimeSeries;

it('isAbove with float threshold', function () {
    $ts = new ArrayTimeSeries([1.0, 5.0, 3.0, null]);
    $cond = new ComparisonCondition($ts, 3.0, '>');
    $results = $cond->evaluateAll(4);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeTrue()
        ->and($results[2])->toBeFalse()
        ->and($results[3])->toBeFalse();
});

it('isBelow with float threshold', function () {
    $ts = new ArrayTimeSeries([1.0, 5.0, 3.0, null]);
    $cond = new ComparisonCondition($ts, 3.0, '<');
    $results = $cond->evaluateAll(4);

    expect($results[0])->toBeTrue()
        ->and($results[1])->toBeFalse()
        ->and($results[2])->toBeFalse()
        ->and($results[3])->toBeFalse();
});

it('isAbove with TimeSeries right-hand side', function () {
    $left = new ArrayTimeSeries([1.0, 5.0, 3.0]);
    $right = new ArrayTimeSeries([2.0, 4.0, 3.0]);
    $cond = new ComparisonCondition($left, $right, '>');
    $results = $cond->evaluateAll(3);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeTrue()
        ->and($results[2])->toBeFalse();
});

it('handles gte operator', function () {
    $ts = new ArrayTimeSeries([1.0, 3.0, 5.0]);
    $cond = new ComparisonCondition($ts, 3.0, '>=');
    $results = $cond->evaluateAll(3);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeTrue()
        ->and($results[2])->toBeTrue();
});

it('handles lte operator', function () {
    $ts = new ArrayTimeSeries([1.0, 3.0, 5.0]);
    $cond = new ComparisonCondition($ts, 3.0, '<=');
    $results = $cond->evaluateAll(3);

    expect($results[0])->toBeTrue()
        ->and($results[1])->toBeTrue()
        ->and($results[2])->toBeFalse();
});

it('returns false when null on right-hand TimeSeries', function () {
    $left = new ArrayTimeSeries([1.0, 5.0]);
    $right = new ArrayTimeSeries([null, 4.0]);
    $cond = new ComparisonCondition($left, $right, '>');

    expect($cond->evaluate(0))->toBeFalse()
        ->and($cond->evaluate(1))->toBeTrue();
});
