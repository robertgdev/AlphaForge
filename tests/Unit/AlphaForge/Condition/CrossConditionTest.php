<?php

use App\AlphaForge\Condition\CrossCondition;
use App\AlphaForge\TimeSeries\ArrayTimeSeries;

it('detects cross above correctly', function () {
    // a crosses above b at index 2
    $a = new ArrayTimeSeries([1.0, 2.0, 3.0, 3.0]);
    $b = new ArrayTimeSeries([3.0, 2.5, 2.0, 1.5]);

    $cond = new CrossCondition($a, $b, 'above');
    $results = $cond->evaluateAll(4);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeFalse()
        ->and($results[2])->toBeTrue()
        ->and($results[3])->toBeFalse();
});

it('detects cross below correctly', function () {
    // a crosses below b at index 2
    $a = new ArrayTimeSeries([3.0, 2.5, 2.0, 1.5]);
    $b = new ArrayTimeSeries([1.0, 2.0, 3.0, 3.0]);

    $cond = new CrossCondition($a, $b, 'below');
    $results = $cond->evaluateAll(4);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeFalse()
        ->and($results[2])->toBeTrue()
        ->and($results[3])->toBeFalse();
});

it('returns false at index 0', function () {
    $a = new ArrayTimeSeries([5.0, 1.0]);
    $b = new ArrayTimeSeries([1.0, 5.0]);

    $cond = new CrossCondition($a, $b, 'above');
    expect($cond->evaluate(0))->toBeFalse();
});

it('returns false when null values present', function () {
    $a = new ArrayTimeSeries([null, 2.0, 3.0]);
    $b = new ArrayTimeSeries([3.0, 2.5, 2.0]);

    $cond = new CrossCondition($a, $b, 'above');
    $results = $cond->evaluateAll(3);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeFalse()
        ->and($results[2])->toBeTrue();
});

it('single evaluate matches bulk evaluate', function () {
    $a = new ArrayTimeSeries([1.0, 2.0, 3.0, 2.0, 1.0]);
    $b = new ArrayTimeSeries([2.0, 2.0, 2.0, 2.0, 2.0]);

    $cond = new CrossCondition($a, $b, 'above');
    $bulk = $cond->evaluateAll(5);

    for ($i = 0; $i < 5; $i++) {
        expect($cond->evaluate($i))->toBe($bulk[$i], "Mismatch at index {$i}");
    }
});
