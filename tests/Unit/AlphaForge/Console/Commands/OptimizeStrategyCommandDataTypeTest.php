<?php

use App\AlphaForge\Console\Commands\OptimizeStrategyCommand;

describe('OptimizeStrategyCommand Data Type Options', function () {
    describe('command signature', function () {
        it('has --data-type option with default ohlcv', function () {
            $ref = new ReflectionClass(OptimizeStrategyCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('--data-type=')
                ->and($defaultProps['signature'])->toContain('ohlcv');
        });

        it('has --brick-size option', function () {
            $ref = new ReflectionClass(OptimizeStrategyCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('--brick-size=');
        });

        it('has --atr-period option', function () {
            $ref = new ReflectionClass(OptimizeStrategyCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('--atr-period=');
        });
    });
});
