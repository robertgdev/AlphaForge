<?php

use App\AlphaForge\Condition\ComparisonCondition;
use App\AlphaForge\Condition\PersistedForCondition;
use App\AlphaForge\TimeSeries\ArrayTimeSeries;

it('returns true when inner held for exactly N consecutive bars', function () {
    $ts = new ArrayTimeSeries([3.0, 5.0, 6.0, 3.0]);
    $above4 = new ComparisonCondition($ts, 4.0, '>');

    // inner pattern: [F, T, T, F]
    $cond = new PersistedForCondition($above4, 2);
    $results = $cond->evaluateAll(4);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeFalse()
        ->and($results[2])->toBeTrue()
        ->and($results[3])->toBeFalse();
});

it('returns true when inner held for more than N consecutive bars', function () {
    $ts = new ArrayTimeSeries([3.0, 6.0, 8.0, 7.0, 2.0]);
    $above5 = new ComparisonCondition($ts, 5.0, '>');

    // inner pattern: [F, T, T, T, F]
    // persistedFor(2): [F, F, T, T, F]
    $cond = new PersistedForCondition($above5, 2);
    $results = $cond->evaluateAll(5);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeFalse()
        ->and($results[2])->toBeTrue()
        ->and($results[3])->toBeTrue()
        ->and($results[4])->toBeFalse();
});

it('returns false when inner held for fewer than N bars', function () {
    $ts = new ArrayTimeSeries([5.0, 3.0, 6.0]);
    $above4 = new ComparisonCondition($ts, 4.0, '>');

    // inner pattern: [T, F, T]
    $cond = new PersistedForCondition($above4, 2);
    $results = $cond->evaluateAll(3);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeFalse()
        ->and($results[2])->toBeFalse();
});

it('resets on a gap in consecutive trues', function () {
    $ts = new ArrayTimeSeries([6.0, 6.0, 3.0, 6.0, 6.0]);
    $above5 = new ComparisonCondition($ts, 5.0, '>');

    // inner pattern: [T, T, F, T, T]
    // persistedFor(2): [F, T, F, F, T]
    $cond = new PersistedForCondition($above5, 2);
    $results = $cond->evaluateAll(5);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeTrue()
        ->and($results[2])->toBeFalse()
        ->and($results[3])->toBeFalse()
        ->and($results[4])->toBeTrue();
});

it('returns false when N > total bars with trues', function () {
    $ts = new ArrayTimeSeries([6.0, 6.0]);
    $above5 = new ComparisonCondition($ts, 5.0, '>');

    $cond = new PersistedForCondition($above5, 3);
    $results = $cond->evaluateAll(2);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeFalse();
});

it('returns false at index 0 when N > 1', function () {
    $ts = new ArrayTimeSeries([6.0, 6.0, 6.0]);
    $above5 = new ComparisonCondition($ts, 5.0, '>');

    $cond = new PersistedForCondition($above5, 2);
    $results = $cond->evaluateAll(3);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeTrue()
        ->and($results[2])->toBeTrue();
});

it('single evaluate matches bulk evaluate', function () {
    $ts = new ArrayTimeSeries([3.0, 7.0, 6.0, 2.0, 8.0, 8.0, 4.0]);
    $above5 = new ComparisonCondition($ts, 5.0, '>');

    $cond = new PersistedForCondition($above5, 2);
    $bulk = $cond->evaluateAll(7);

    for ($i = 0; $i < 7; $i++) {
        expect($cond->evaluate($i))->toBe($bulk[$i], "Mismatch at index {$i}");
    }
});

it('works via interface persistedFor() method', function () {
    $ts = new ArrayTimeSeries([3.0, 6.0, 7.0, 3.0]);
    $above5 = $ts->isAbove(5.0);

    $cond = $above5->persistedFor(2);
    $results = $cond->evaluateAll(4);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeFalse()
        ->and($results[2])->toBeTrue()
        ->and($results[3])->toBeFalse();
});

it('composes persistedFor with or', function () {
    $ts = new ArrayTimeSeries([3.0, 6.0, 7.0, 2.0, 2.0]);
    $above5 = $ts->isAbove(5.0);
    $below3 = $ts->isBelow(3.0);

    // persistedFor(2) of above5: [F, F, T, F, F]
    // below3:                    [F, F, F, T, T]
    $cond = $above5->persistedFor(2)->or($below3);
    $results = $cond->evaluateAll(5);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeFalse()
        ->and($results[2])->toBeTrue()
        ->and($results[3])->toBeTrue()
        ->and($results[4])->toBeTrue();
});

it('N=1 is equivalent to inner condition', function () {
    $ts = new ArrayTimeSeries([3.0, 7.0, 2.0, 6.0]);
    $above5 = new ComparisonCondition($ts, 5.0, '>');

    $cond = new PersistedForCondition($above5, 1);
    $bulk = $cond->evaluateAll(4);
    $inner = $above5->evaluateAll(4);

    for ($i = 0; $i < 4; $i++) {
        expect($bulk[$i])->toBe($inner[$i]);
    }
});