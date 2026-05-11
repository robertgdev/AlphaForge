<?php

use App\AlphaForge\Condition\ComparisonCondition;
use App\AlphaForge\Condition\NotCondition;
use App\AlphaForge\TimeSeries\ArrayTimeSeries;

it('negates condition results', function () {
    $ts = new ArrayTimeSeries([1.0, 5.0, 3.0]);
    $above3 = new ComparisonCondition($ts, 3.0, '>');
    $not = new NotCondition($above3);
    $results = $not->evaluateAll(3);

    // original: [F, T, F] -> negated: [T, F, T]
    expect($results)->toBe([true, false, true]);
});

it('works via interface not() method', function () {
    $ts = new ArrayTimeSeries([1.0, 5.0, 3.0]);
    $above3 = $ts->isAbove(3.0);
    $notAbove3 = $above3->not();
    $results = $notAbove3->evaluateAll(3);

    expect($results)->toBe([true, false, true]);
});
