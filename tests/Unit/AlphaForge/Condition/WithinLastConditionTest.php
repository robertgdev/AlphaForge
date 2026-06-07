<?php

use App\AlphaForge\Condition\ComparisonCondition;
use App\AlphaForge\Condition\WithinLastCondition;
use App\AlphaForge\TimeSeries\ArrayTimeSeries;

it('returns true at current index when inner is true', function () {
    $ts = new ArrayTimeSeries([3.0, 3.0, 7.0, 3.0]);
    $above5 = new ComparisonCondition($ts, 5.0, '>');

    $cond = new WithinLastCondition($above5, 3);
    $results = $cond->evaluateAll(4);

    expect($results[2])->toBeTrue();
});

it('returns true when inner was true within the window but not at current', function () {
    $ts = new ArrayTimeSeries([3.0, 9.0, 5.0, 3.0, 3.0]);
    $above7 = new ComparisonCondition($ts, 7.0, '>');

    // window = 3 bars. At index 2: inner[1] was true -> true
    // At index 4: inner was last true at index 1 (4 bars ago) -> false
    $cond = new WithinLastCondition($above7, 3);
    $results = $cond->evaluateAll(5);

    expect($results[2])->toBeTrue()
        ->and($results[3])->toBeTrue()
        ->and($results[4])->toBeFalse();
});

it('returns false when inner has not been true in the window', function () {
    $ts = new ArrayTimeSeries([3.0, 3.0, 3.0, 3.0, 8.0]);
    $above5 = new ComparisonCondition($ts, 5.0, '>');

    $cond = new WithinLastCondition($above5, 2);
    $results = $cond->evaluateAll(5);

    expect($results[0])->toBeFalse()
        ->and($results[1])->toBeFalse()
        ->and($results[2])->toBeFalse()
        ->and($results[3])->toBeFalse()
        ->and($results[4])->toBeTrue();
});

it('handles window larger than current index gracefully', function () {
    $ts = new ArrayTimeSeries([5.0, 3.0, 3.0]);
    $above4 = new ComparisonCondition($ts, 4.0, '>');

    // window = 10, only indices 0 and 1 are within window
    $cond = new WithinLastCondition($above4, 10);
    $results = $cond->evaluateAll(3);

    expect($results[0])->toBeTrue()
        ->and($results[1])->toBeTrue()
        ->and($results[2])->toBeTrue();
});

it('single evaluate matches bulk evaluate', function () {
    $ts = new ArrayTimeSeries([1.0, 3.0, 8.0, 2.0, 2.0, 3.0]);
    $above5 = new ComparisonCondition($ts, 5.0, '>');

    $cond = new WithinLastCondition($above5, 3);
    $bulk = $cond->evaluateAll(6);

    for ($i = 0; $i < 6; $i++) {
        expect($cond->evaluate($i))->toBe($bulk[$i], "Mismatch at index {$i}");
    }
});

it('works via interface withinLast() method', function () {
    $ts = new ArrayTimeSeries([1.0, 9.0, 3.0, 3.0]);
    $above5 = $ts->isAbove(5.0);

    $cond = $above5->withinLast(2);
    $results = $cond->evaluateAll(4);

    expect($results[1])->toBeTrue()
        ->and($results[2])->toBeTrue()
        ->and($results[3])->toBeFalse();
});

it('composes withinLast with and', function () {
    $ts = new ArrayTimeSeries([1.0, 6.0, 3.0, 3.0, 8.0]);
    $above5 = $ts->isAbove(5.0);
    $below7 = $ts->isBelow(7.0);

    // withinLast(2) of above5: [F, T, T, F, T]
    // below7:                 [T, T, T, T, F]
    $cond = $above5->withinLast(2)->and($below7);
    $results = $cond->evaluateAll(5);

    expect($results[1])->toBeTrue()
        ->and($results[2])->toBeTrue()
        ->and($results[3])->toBeFalse()
        ->and($results[4])->toBeFalse();
});

it('returns false when window is zero', function () {
    $ts = new ArrayTimeSeries([5.0]);
    $above3 = new ComparisonCondition($ts, 3.0, '>');

    $cond = new WithinLastCondition($above3, 0);
    $results = $cond->evaluateAll(1);

    expect($results[0])->toBeFalse();
});
