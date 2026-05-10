<?php

use App\AlphaForge\ExitRule\TrailingStop;

describe('TrailingStop::percent', function () {
    it('triggers for long when price drops below trail', function () {
        $rule = TrailingStop::percent(5.0);
        $position = makePosition(direction: 'long', entryPrice: '100');
        $context = makeContext(
            $position,
            low: 103.0,
            close: 105.0,
            highestSinceEntry: 110.0,
        );

        $distance = 105.0 * 0.05;
        $trail = 110.0 - $distance;

        $result = $rule->evaluate($context);

        expect($result)->not->toBeNull()
            ->and($result->ruleId)->toBe('trailing_stop')
            ->and($result->exitPrice)->toBe($trail);
    });

    it('does not trigger for long when price is above trail', function () {
        $rule = TrailingStop::percent(5.0);
        $position = makePosition(direction: 'long', entryPrice: '100');
        $context = makeContext(
            $position,
            low: 106.0,
            close: 108.0,
            highestSinceEntry: 110.0,
        );

        $result = $rule->evaluate($context);

        expect($result)->toBeNull();
    });

    it('triggers for short when price rises above trail', function () {
        $rule = TrailingStop::percent(5.0);
        $position = makePosition(direction: 'short', entryPrice: '100');
        $context = makeContext(
            $position,
            high: 97.0,
            close: 95.0,
            lowestSinceEntry: 90.0,
        );

        $distance = 95.0 * 0.05;
        $trail = 90.0 + $distance;

        $result = $rule->evaluate($context);

        expect($result)->not->toBeNull()
            ->and($result->ruleId)->toBe('trailing_stop')
            ->and($result->exitPrice)->toBe($trail);
    });

    it('does not trigger for short when price is below trail', function () {
        $rule = TrailingStop::percent(5.0);
        $position = makePosition(direction: 'short', entryPrice: '100');
        $context = makeContext(
            $position,
            high: 93.0,
            close: 92.0,
            lowestSinceEntry: 90.0,
        );

        $result = $rule->evaluate($context);

        expect($result)->toBeNull();
    });

    it('uses close price for percent distance calculation', function () {
        $rule = TrailingStop::percent(10.0);
        $position = makePosition(direction: 'long', entryPrice: '100');
        $context = makeContext(
            $position,
            low: 90.0,
            close: 100.0,
            highestSinceEntry: 100.0,
        );

        $trail = 100.0 - 100.0 * 0.10;
        expect($low = 90.0)->toBeLessThan($trail + 1);

        $result = $rule->evaluate($context);
        expect($result)->not->toBeNull()
            ->and($result->exitPrice)->toBe($trail);
    });

    it('behaves like static SL if price never moves favorably', function () {
        $rule = TrailingStop::percent(5.0);
        $position = makePosition(direction: 'long', entryPrice: '100');
        $context = makeContext(
            $position,
            low: 93.0,
            close: 100.0,
            highestSinceEntry: 100.0,
        );

        $trail = 100.0 - 100.0 * 0.05;
        $result = $rule->evaluate($context);

        expect($result)->not->toBeNull()
            ->and($result->exitPrice)->toBe($trail);
    });
});
