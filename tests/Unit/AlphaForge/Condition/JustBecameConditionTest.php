<?php

use App\AlphaForge\Condition\ComparisonCondition;
use App\AlphaForge\Condition\JustBecameCondition;
use App\AlphaForge\TimeSeries\ArrayTimeSeries;

it('returns true at rising edge (false to true transition)', function () {
    $ts = new ArrayTimeSeries([3.0, 3.0, 7.0, 7.0]);
    $above5 = new ComparisonCondition($ts, 5.0, '>');

    // inner pattern: [F, F, T, T]
    $cond = new JustBecameCondition($above5);
    $results = $cond->evaluateAll(4);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeFalse()
        ->and($results[2])->toBeTrue()
        ->and($results[3])->toBeFalse();
});

it('returns false when condition stays true', function () {
    $ts = new ArrayTimeSeries([7.0, 8.0, 6.0]);
    $above5 = new ComparisonCondition($ts, 5.0, '>');

    // inner pattern: [T, T, T]
    $cond = new JustBecameCondition($above5);
    $results = $cond->evaluateAll(3);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeFalse()
        ->and($results[2])->toBeFalse();
});

it('returns false when condition stays false', function () {
    $ts = new ArrayTimeSeries([3.0, 2.0, 4.0]);
    $above5 = new ComparisonCondition($ts, 5.0, '>');

    // inner pattern: [F, F, F]
    $cond = new JustBecameCondition($above5);
    $results = $cond->evaluateAll(3);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeFalse()
        ->and($results[2])->toBeFalse();
});

it('returns false at index 0 even if inner is true', function () {
    $ts = new ArrayTimeSeries([7.0, 3.0]);
    $above5 = new ComparisonCondition($ts, 5.0, '>');

    $cond = new JustBecameCondition($above5);
    $results = $cond->evaluateAll(2);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeFalse();
});

it('detects multiple rising edges in sequence', function () {
    $ts = new ArrayTimeSeries([3.0, 7.0, 4.0, 8.0, 2.0]);
    $above5 = new ComparisonCondition($ts, 5.0, '>');

    // inner pattern: [F, T, F, T, F]
    $cond = new JustBecameCondition($above5);
    $results = $cond->evaluateAll(5);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeTrue()
        ->and($results[2])->toBeFalse()
        ->and($results[3])->toBeTrue()
        ->and($results[4])->toBeFalse();
});

it('detects rising edge after a single false bar', function () {
    $ts = new ArrayTimeSeries([7.0, 3.0, 7.0]);
    $above5 = new ComparisonCondition($ts, 5.0, '>');

    // inner pattern: [T, F, T]
    $cond = new JustBecameCondition($above5);
    $results = $cond->evaluateAll(3);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeFalse()
        ->and($results[2])->toBeTrue();
});

it('single evaluate matches bulk evaluate', function () {
    $ts = new ArrayTimeSeries([2.0, 5.0, 6.0, 3.0, 8.0, 2.0]);
    $above4 = new ComparisonCondition($ts, 4.0, '>');

    $cond = new JustBecameCondition($above4);
    $bulk = $cond->evaluateAll(6);

    for ($i = 0; $i < 6; $i++) {
        expect($cond->evaluate($i))->toBe($bulk[$i], "Mismatch at index {$i}");
    }
});

it('works via interface justBecame() method', function () {
    $ts = new ArrayTimeSeries([2.0, 6.0, 5.0, 2.0]);
    $above4 = $ts->isAbove(4.0);

    $cond = $above4->justBecame();
    $results = $cond->evaluateAll(4);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeTrue()
        ->and($results[2])->toBeFalse()
        ->and($results[3])->toBeFalse();
});

it('composes justBecame with withinLast', function () {
    $ts = new ArrayTimeSeries([2.0, 6.0, 5.0, 2.0, 2.0, 8.0]);
    $above4 = $ts->isAbove(4.0);

    // inner:         [F, T, T, F, F, T]
    // justBecame():  [F, T, F, F, F, T]
    // withinLast(3): [F, T, T, T, F, T]
    $cond = $above4->justBecame()->withinLast(3);
    $results = $cond->evaluateAll(6);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeTrue()
        ->and($results[2])->toBeTrue()
        ->and($results[3])->toBeTrue()
        ->and($results[4])->toBeFalse()
        ->and($results[5])->toBeTrue();
});

it('handles cross condition becoming true', function () {
    $a = new ArrayTimeSeries([1.0, 1.0, 4.0, 4.0]);
    $b = new ArrayTimeSeries([2.0, 2.0, 2.0, 2.0]);

    $cross = $a->crossesAbove($b);
    $cond = $cross->justBecame();
    $results = $cond->evaluateAll(4);

    // cross pattern: [F, F, T, F]
    // justBecame:    [F, F, T, F]
    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeFalse()
        ->and($results[2])->toBeTrue()
        ->and($results[3])->toBeFalse();
});
