<?php

use App\AlphaForge\Indicator\Model\IndicatorResult;
use App\AlphaForge\TimeSeries\ArrayTimeSeries;

it('get() returns TimeSeries for known key', function () {
    $upper = new ArrayTimeSeries([1.0, 2.0]);
    $lower = new ArrayTimeSeries([0.5, 1.0]);
    $result = new IndicatorResult(['upper' => $upper, 'lower' => $lower]);

    expect($result->get('upper'))->toBe($upper)
        ->and($result->get('lower'))->toBe($lower);
});

it('get() throws for unknown key', function () {
    $result = new IndicatorResult(['value' => new ArrayTimeSeries([1.0])]);

    $result->get('nonexistent');
})->throws(InvalidArgumentException::class);

it('all() returns all series', function () {
    $series = [
        'a' => new ArrayTimeSeries([1.0]),
        'b' => new ArrayTimeSeries([2.0]),
    ];
    $result = new IndicatorResult($series);

    expect($result->all())->toBe($series);
});

it('outputKeys() returns key names', function () {
    $result = new IndicatorResult([
        'macd' => new ArrayTimeSeries([1.0]),
        'signal' => new ArrayTimeSeries([2.0]),
        'histogram' => new ArrayTimeSeries([3.0]),
    ]);

    expect($result->outputKeys())->toBe(['macd', 'signal', 'histogram']);
});
