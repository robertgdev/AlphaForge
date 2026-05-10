<?php

use App\AlphaForge\ExitRule\StaticStopLoss;

describe('StaticStopLoss', function () {
    it('returns null when position has no stop loss', function () {
        $position = makePosition(stopLoss: null);
        $context = makeContext($position, low: 90.0);

        $result = (new StaticStopLoss)->evaluate($context);

        expect($result)->toBeNull();
    });

    it('triggers for long when low breaches SL', function () {
        $position = makePosition(direction: 'long', stopLoss: '95');
        $context = makeContext($position, low: 94.0);

        $result = (new StaticStopLoss)->evaluate($context);

        expect($result)->not->toBeNull()
            ->and($result->ruleId)->toBe('stop_loss')
            ->and($result->exitPrice)->toBe(95.0);
    });

    it('does not trigger for long when low is above SL', function () {
        $position = makePosition(direction: 'long', stopLoss: '95');
        $context = makeContext($position, low: 96.0);

        $result = (new StaticStopLoss)->evaluate($context);

        expect($result)->toBeNull();
    });

    it('triggers for short when high breaches SL', function () {
        $position = makePosition(direction: 'short', stopLoss: '105');
        $context = makeContext($position, high: 106.0);

        $result = (new StaticStopLoss)->evaluate($context);

        expect($result)->not->toBeNull()
            ->and($result->ruleId)->toBe('stop_loss')
            ->and($result->exitPrice)->toBe(105.0);
    });

    it('does not trigger for short when high is below SL', function () {
        $position = makePosition(direction: 'short', stopLoss: '105');
        $context = makeContext($position, high: 104.0);

        $result = (new StaticStopLoss)->evaluate($context);

        expect($result)->toBeNull();
    });

    it('triggers when low equals SL exactly', function () {
        $position = makePosition(direction: 'long', stopLoss: '95');
        $context = makeContext($position, low: 95.0);

        $result = (new StaticStopLoss)->evaluate($context);

        expect($result)->not->toBeNull();
    });
});
