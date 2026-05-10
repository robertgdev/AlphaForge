<?php

use App\AlphaForge\Condition\TrendCondition;
use App\AlphaForge\TimeSeries\ArrayTimeSeries;

it('detects rising with period 1', function () {
    $ts = new ArrayTimeSeries([1.0, 2.0, 3.0, 2.0, 1.0]);
    $cond = new TrendCondition($ts, 1, 'rising');
    $results = $cond->evaluateAll(5);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeTrue()
        ->and($results[2])->toBeTrue()
        ->and($results[3])->toBeFalse()
        ->and($results[4])->toBeFalse();
});

it('detects falling with period 1', function () {
    $ts = new ArrayTimeSeries([3.0, 2.0, 1.0, 2.0, 3.0]);
    $cond = new TrendCondition($ts, 1, 'falling');
    $results = $cond->evaluateAll(5);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeTrue()
        ->and($results[2])->toBeTrue()
        ->and($results[3])->toBeFalse()
        ->and($results[4])->toBeFalse();
});

it('detects rising with period 2', function () {
    $ts = new ArrayTimeSeries([1.0, 5.0, 2.0, 4.0, 3.0]);
    $cond = new TrendCondition($ts, 2, 'rising');
    $results = $cond->evaluateAll(5);

    // index 2: ts[2]=2.0 vs ts[0]=1.0 -> rising
    // index 3: ts[3]=4.0 vs ts[1]=5.0 -> not rising
    // index 4: ts[4]=3.0 vs ts[2]=2.0 -> rising
    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeFalse()
        ->and($results[2])->toBeTrue()
        ->and($results[3])->toBeFalse()
        ->and($results[4])->toBeTrue();
});

it('returns false when null values', function () {
    $ts = new ArrayTimeSeries([null, 2.0, 3.0]);
    $cond = new TrendCondition($ts, 1, 'rising');
    $results = $cond->evaluateAll(3);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeFalse()
        ->and($results[2])->toBeTrue();
});
