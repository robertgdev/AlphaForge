<?php

use App\AlphaForge\ExitRule\StaticTakeProfit;

describe('StaticTakeProfit', function () {
    it('returns null when position has no take profit', function () {
        $position = makePosition(takeProfit: null);
        $context = makeContext($position, high: 110.0);

        $result = (new StaticTakeProfit)->evaluate($context);

        expect($result)->toBeNull();
    });

    it('triggers for long when high reaches TP', function () {
        $position = makePosition(direction: 'long', takeProfit: '110');
        $context = makeContext($position, high: 111.0);

        $result = (new StaticTakeProfit)->evaluate($context);

        expect($result)->not->toBeNull()
            ->and($result->ruleId)->toBe('take_profit')
            ->and($result->exitPrice)->toBe(110.0);
    });

    it('does not trigger for long when high is below TP', function () {
        $position = makePosition(direction: 'long', takeProfit: '110');
        $context = makeContext($position, high: 109.0);

        $result = (new StaticTakeProfit)->evaluate($context);

        expect($result)->toBeNull();
    });

    it('triggers for short when low reaches TP', function () {
        $position = makePosition(direction: 'short', takeProfit: '90');
        $context = makeContext($position, low: 89.0);

        $result = (new StaticTakeProfit)->evaluate($context);

        expect($result)->not->toBeNull()
            ->and($result->ruleId)->toBe('take_profit')
            ->and($result->exitPrice)->toBe(90.0);
    });

    it('does not trigger for short when low is above TP', function () {
        $position = makePosition(direction: 'short', takeProfit: '90');
        $context = makeContext($position, low: 91.0);

        $result = (new StaticTakeProfit)->evaluate($context);

        expect($result)->toBeNull();
    });

    it('triggers when high equals TP exactly', function () {
        $position = makePosition(direction: 'long', takeProfit: '110');
        $context = makeContext($position, high: 110.0);

        $result = (new StaticTakeProfit)->evaluate($context);

        expect($result)->not->toBeNull();
    });
});
