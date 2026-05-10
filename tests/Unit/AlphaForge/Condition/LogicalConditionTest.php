<?php

use App\AlphaForge\Condition\LogicalCondition;
use App\AlphaForge\Condition\ComparisonCondition;
use App\AlphaForge\TimeSeries\ArrayTimeSeries;

it('AND composition', function () {
    $ts = new ArrayTimeSeries([1.0, 5.0, 3.0, 7.0]);
    $above2 = new ComparisonCondition($ts, 2.0, '>');
    $below6 = new ComparisonCondition($ts, 6.0, '<');

    $and = new LogicalCondition($above2, $below6, 'and');
    $results = $and->evaluateAll(4);

    // index 0: 1 > 2 = F, 1 < 6 = T -> F
    // index 1: 5 > 2 = T, 5 < 6 = T -> T
    // index 2: 3 > 2 = T, 3 < 6 = T -> T
    // index 3: 7 > 2 = T, 7 < 6 = F -> F
    expect($results)->toBe([false, true, true, false]);
});

it('OR composition', function () {
    $ts = new ArrayTimeSeries([1.0, 5.0, 3.0, 7.0]);
    $below2 = new ComparisonCondition($ts, 2.0, '<');
    $above6 = new ComparisonCondition($ts, 6.0, '>');

    $or = new LogicalCondition($below2, $above6, 'or');
    $results = $or->evaluateAll(4);

    // index 0: 1 < 2 = T -> T
    // index 1: 5 < 2 = F, 5 > 6 = F -> F
    // index 2: 3 < 2 = F, 3 > 6 = F -> F
    // index 3: 7 < 2 = F, 7 > 6 = T -> T
    expect($results)->toBe([true, false, false, true]);
});

it('chained composition via interface methods', function () {
    $ts = new ArrayTimeSeries([1.0, 5.0, 3.0, 7.0]);
    $above2 = $ts->isAbove(2.0);
    $below6 = $ts->isBelow(6.0);

    $combined = $above2->and($below6);
    $results = $combined->evaluateAll(4);

    expect($results)->toBe([false, true, true, false]);
});
