<?php

use App\AlphaForge\Console\Commands\OptimizeStrategyCommand;
use Illuminate\Console\Command;

describe('OptimizeStrategyCommand validation', function () {
    it('has execution-timeframe in signature', function () {
        $ref = new ReflectionClass(OptimizeStrategyCommand::class);
        $defaultProps = $ref->getDefaultProperties();

        expect($defaultProps['signature'])->toContain('--execution-timeframe=');
    });

    it('has all key options in signature', function () {
        $ref = new ReflectionClass(OptimizeStrategyCommand::class);
        $defaultProps = $ref->getDefaultProperties();
        $signature = $defaultProps['signature'];

        expect($signature)->toContain('--timeframe=')
            ->and($signature)->toContain('--execution-timeframe=')
            ->and($signature)->toContain('--params=')
            ->and($signature)->toContain('--method=')
            ->and($signature)->toContain('--objective=')
            ->and($signature)->toContain('--use-strategy-ranges')
            ->and($signature)->toContain('--data-type=')
            ->and($signature)->toContain('--progress=');
    });

    it('has description', function () {
        $ref = new ReflectionClass(OptimizeStrategyCommand::class);
        $defaultProps = $ref->getDefaultProperties();
        expect($defaultProps['description'])->not->toBeEmpty();
    });

    it('extends Command', function () {
        expect(is_a(OptimizeStrategyCommand::class, Command::class, true))->toBeTrue();
    });

    it('has handle method with correct dependencies', function () {
        $ref = new ReflectionClass(OptimizeStrategyCommand::class);
        $method = $ref->getMethod('handle');
        $params = $method->getParameters();

        expect($params)->toHaveCount(3)
            ->and($params[0]->getName())->toBe('optimizer')
            ->and($params[1]->getName())->toBe('inputParser')
            ->and($params[2]->getName())->toBe('dataAutoGenerator');
    });
});
