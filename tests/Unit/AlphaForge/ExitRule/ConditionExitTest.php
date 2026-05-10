<?php

use App\AlphaForge\Condition\ConditionInterface;
use App\AlphaForge\ExitRule\ConditionExit;

describe('ConditionExit', function () {
    it('triggers when condition evaluates true', function () {
        $condition = Mockery::mock(ConditionInterface::class);
        $condition->shouldReceive('evaluate')->with(5)->andReturn(true);

        $rule = ConditionExit::when($condition, tag: 'rsi_overbought');
        $context = makeContext(barIndex: 5, close: 100.0);

        $result = $rule->evaluate($context);

        expect($result)->not->toBeNull()
            ->and($result->ruleId)->toBe('condition_exit')
            ->and($result->exitPrice)->toBe(100.0)
            ->and($result->exitTag)->toBe('rsi_overbought');
    });

    it('returns null when condition evaluates false', function () {
        $condition = Mockery::mock(ConditionInterface::class);
        $condition->shouldReceive('evaluate')->with(5)->andReturn(false);

        $rule = ConditionExit::when($condition);
        $context = makeContext(barIndex: 5, close: 100.0);

        $result = $rule->evaluate($context);

        expect($result)->toBeNull();
    });

    it('uses null tag when no tag provided and condition true', function () {
        $condition = Mockery::mock(ConditionInterface::class);
        $condition->shouldReceive('evaluate')->with(0)->andReturn(true);

        $rule = new ConditionExit($condition);
        $context = makeContext(barIndex: 0, close: 100.0);

        $result = $rule->evaluate($context);

        expect($result->exitTag)->toBeNull();
    });

    it('uses ruleId as tag when no custom tag', function () {
        $condition = Mockery::mock(ConditionInterface::class);
        $condition->shouldReceive('evaluate')->with(0)->andReturn(true);

        $rule = ConditionExit::when($condition);
        $context = makeContext(barIndex: 0, close: 100.0);

        $result = $rule->evaluate($context);

        expect($result->exitTag)->toBeNull();
        expect($result->ruleId)->toBe('condition_exit');
    });
});
