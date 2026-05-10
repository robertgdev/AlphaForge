<?php

use App\AlphaForge\Indicator\Model\IndicatorRegistry;

it('has definition for sma', function () {
    $def = IndicatorRegistry::getDefinition('sma');

    expect($def['function'])->toBe('sma')
        ->and($def['inputs'])->toBe(['close'])
        ->and($def['params'])->toBe(['period'])
        ->and($def['outputs'])->toBe(['value']);
});

it('has definition for macd', function () {
    $def = IndicatorRegistry::getDefinition('macd');

    expect($def['function'])->toBe('macd')
        ->and($def['inputs'])->toBe(['close'])
        ->and($def['params'])->toBe(['fastPeriod', 'slowPeriod', 'signalPeriod'])
        ->and($def['outputs'])->toBe(['macd', 'signal', 'histogram']);
});

it('has definition for bbands', function () {
    $def = IndicatorRegistry::getDefinition('bbands');

    expect($def['outputs'])->toBe(['upper', 'middle', 'lower']);
});

it('has definition for atr', function () {
    $def = IndicatorRegistry::getDefinition('atr');

    expect($def['inputs'])->toBe(['high', 'low', 'close'])
        ->and($def['params'])->toBe(['period']);
});

it('throws for unknown indicator', function () {
    IndicatorRegistry::getDefinition('nonexistent');
})->throws(InvalidArgumentException::class);

it('has() returns correct boolean', function () {
    expect(IndicatorRegistry::has('sma'))->toBeTrue()
        ->and(IndicatorRegistry::has('nonexistent'))->toBeFalse();
});

it('getAvailableIndicators returns non-empty array', function () {
    $indicators = IndicatorRegistry::getAvailableIndicators();

    expect($indicators)->not->toBeEmpty()
        ->and($indicators)->toContain('sma')
        ->and($indicators)->toContain('macd')
        ->and($indicators)->toContain('rsi')
        ->and($indicators)->toContain('bbands')
        ->and($indicators)->toContain('atr')
        ->and($indicators)->toContain('stoch');
});

it('register() adds custom indicator', function () {
    IndicatorRegistry::register('custom_test', [
        'function' => 'sma',
        'inputs' => ['close'],
        'params' => ['period'],
        'outputs' => ['value'],
    ]);

    expect(IndicatorRegistry::has('custom_test'))->toBeTrue();
});

it('register() validates required fields', function () {
    IndicatorRegistry::register('bad', ['function' => 'sma']);
})->throws(InvalidArgumentException::class);

it('has cdl pattern indicators', function () {
    expect(IndicatorRegistry::has('cdlhammer'))->toBeTrue()
        ->and(IndicatorRegistry::has('cdlengulfing'))->toBeTrue()
        ->and(IndicatorRegistry::has('cdlabandonedbaby'))->toBeTrue();
});

it('has math transform indicators', function () {
    expect(IndicatorRegistry::has('sqrt'))->toBeTrue()
        ->and(IndicatorRegistry::has('exp'))->toBeTrue();
});

it('has dual input indicators with flag', function () {
    $beta = IndicatorRegistry::getDefinition('beta');
    expect($beta['dualInput'])->toBeTrue();

    $sma = IndicatorRegistry::getDefinition('sma');
    expect($sma['dualInput'])->toBeFalse();
});
