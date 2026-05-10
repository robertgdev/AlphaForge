<?php

use App\AlphaForge\ExitRule\MaxBarsInPosition;

describe('MaxBarsInPosition', function () {
    it('triggers when bars in position equals max', function () {
        $rule = new MaxBarsInPosition(50);
        $context = makeContext(barsInPosition: 50, close: 100.0);

        $result = $rule->evaluate($context);

        expect($result)->not->toBeNull()
            ->and($result->ruleId)->toBe('max_bars')
            ->and($result->exitPrice)->toBe(100.0);
    });

    it('triggers when bars in position exceeds max', function () {
        $rule = new MaxBarsInPosition(50);
        $context = makeContext(barsInPosition: 60, close: 100.0);

        $result = $rule->evaluate($context);

        expect($result)->not->toBeNull();
    });

    it('returns null when bars in position is below max', function () {
        $rule = new MaxBarsInPosition(50);
        $context = makeContext(barsInPosition: 49, close: 100.0);

        $result = $rule->evaluate($context);

        expect($result)->toBeNull();
    });

    it('returns null when bars in position is zero', function () {
        $rule = new MaxBarsInPosition(50);
        $context = makeContext(barsInPosition: 0, close: 100.0);

        $result = $rule->evaluate($context);

        expect($result)->toBeNull();
    });
});
