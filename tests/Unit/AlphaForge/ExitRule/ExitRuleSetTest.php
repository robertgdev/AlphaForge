<?php

use App\AlphaForge\ExitRule\ExitRuleSet;
use App\AlphaForge\ExitRule\MaxBarsInPosition;
use App\AlphaForge\ExitRule\PriceBasedExitRule;
use App\AlphaForge\ExitRule\StaticStopLoss;
use App\AlphaForge\ExitRule\StaticTakeProfit;

describe('ExitRuleSet', function () {
    it('evaluates price-based rules before signal-based rules', function () {
        $position = makePosition(direction: 'long', stopLoss: '95');
        $context = makeContext($position, low: 94.0, barsInPosition: 100);

        $ruleSet = new ExitRuleSet([
            new MaxBarsInPosition(50),
            new StaticStopLoss,
        ]);

        $result = $ruleSet->evaluate($context);

        expect($result)->not->toBeNull()
            ->and($result->ruleId)->toBe('stop_loss');
    });

    it('returns first triggered rule', function () {
        $position = makePosition(direction: 'long', stopLoss: '95', takeProfit: '110');
        $context = makeContext($position, low: 94.0, high: 111.0);

        $ruleSet = new ExitRuleSet([
            new StaticStopLoss,
            new StaticTakeProfit,
        ]);

        $result = $ruleSet->evaluate($context);

        expect($result)->not->toBeNull()
            ->and($result->ruleId)->toBe('stop_loss');
    });

    it('returns null when no rules trigger', function () {
        $position = makePosition(direction: 'long', stopLoss: '95');
        $context = makeContext($position, low: 96.0);

        $ruleSet = new ExitRuleSet([
            new StaticStopLoss,
        ]);

        $result = $ruleSet->evaluate($context);

        expect($result)->toBeNull();
    });

    it('supports adding rules via add()', function () {
        $position = makePosition(direction: 'long', stopLoss: '95');
        $context = makeContext($position, low: 94.0);

        $ruleSet = new ExitRuleSet;
        $ruleSet->add(new StaticStopLoss);

        $result = $ruleSet->evaluate($context);

        expect($result)->not->toBeNull()
            ->and($result->ruleId)->toBe('stop_loss');
    });

    it('categorizes StaticStopLoss as price-based', function () {
        $rule = new StaticStopLoss;
        expect($rule)->toBeInstanceOf(PriceBasedExitRule::class);
    });

    it('categorizes StaticTakeProfit as price-based', function () {
        $rule = new StaticTakeProfit;
        expect($rule)->toBeInstanceOf(PriceBasedExitRule::class);
    });

    it('categorizes MaxBarsInPosition as signal-based (not price-based)', function () {
        $rule = new MaxBarsInPosition(10);
        expect($rule)->not->toBeInstanceOf(PriceBasedExitRule::class);
    });
});
