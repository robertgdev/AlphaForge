<?php

use App\AlphaForge\TimeSeries\ArrayTimeSeries;

it('composes crossesAbove with isBelow', function () {
    // fast crosses above slow at index 1 (10<=11 && 12>11)
    $fast = new ArrayTimeSeries([10.0, 12.0, 15.0, 14.0, 13.0]);
    $slow = new ArrayTimeSeries([11.0, 11.0, 11.0, 11.0, 11.0]);

    $crossUp = $fast->crossesAbove($slow);
    $below30 = $fast->isBelow(30.0);

    $combined = $crossUp->and($below30);
    $results = $combined->evaluateAll(5);

    // crosses above at index 1, and 12 < 30 -> T at index 1
    expect($results[1])->toBeTrue();

    // No cross at other indices
    expect($results[0])->toBeFalse()
        ->and($results[2])->toBeFalse()
        ->and($results[3])->toBeFalse()
        ->and($results[4])->toBeFalse();
});

it('composes NOT with cross condition', function () {
    $fast = new ArrayTimeSeries([10.0, 12.0, 15.0, 14.0, 13.0]);
    $slow = new ArrayTimeSeries([11.0, 11.0, 11.0, 11.0, 11.0]);

    $crossUp = $fast->crossesAbove($slow);
    $notCross = $crossUp->not();
    $results = $notCross->evaluateAll(5);

    // Cross at index 1 was true -> now false; others true
    expect($results[0])->toBeTrue()
        ->and($results[1])->toBeFalse()
        ->and($results[2])->toBeTrue();
});
