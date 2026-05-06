<?php

use App\AlphaForge\Console\Commands\RunBacktestCommand;
use App\AlphaForge\Console\Commands\DataCommand;
use App\AlphaForge\Console\Commands\OptimizeStrategyCommand;
use App\AlphaForge\Console\Commands\AggregateDataCommand;
use App\AlphaForge\Console\Commands\RepairDataCommand;

describe('Console Command Signatures', function () {
    describe('RunBacktestCommand', function () {
        it('has correct command signature', function () {
            $ref = new \ReflectionClass(RunBacktestCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:backtest:run')
                ->and($defaultProps['signature'])->toContain('{strategy')
                ->and($defaultProps['signature'])->toContain('{symbols*')
                ->and($defaultProps['signature'])->toContain('--exchange=')
                ->and($defaultProps['signature'])->toContain('--timeframe=')
                ->and($defaultProps['signature'])->toContain('--capital=')
                ->and($defaultProps['signature'])->toContain('--async');
        });

        it('has description', function () {
            $ref = new \ReflectionClass(RunBacktestCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['description'])->not->toBeEmpty();
        });

        it('extends Command', function () {
            expect(is_a(RunBacktestCommand::class, \Illuminate\Console\Command::class, true))->toBeTrue();
        });
    });

    describe('DataCommand', function () {
        it('has correct command signature', function () {
            $ref = new \ReflectionClass(DataCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:data')
                ->and($defaultProps['signature'])->toContain('{action')
                ->and($defaultProps['signature'])->toContain('{exchange?')
                ->and($defaultProps['signature'])->toContain('{market?')
                ->and($defaultProps['signature'])->toContain('{timeframe?')
                ->and($defaultProps['signature'])->toContain('--force');
        });

        it('has description', function () {
            $ref = new \ReflectionClass(DataCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['description'])->not->toBeEmpty();
        });
    });

    describe('OptimizeStrategyCommand', function () {
        it('has correct command signature', function () {
            $ref = new \ReflectionClass(OptimizeStrategyCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:optimize')
                ->and($defaultProps['signature'])->toContain('{strategy')
                ->and($defaultProps['signature'])->toContain('{symbol')
                ->and($defaultProps['signature'])->toContain('--params=')
                ->and($defaultProps['signature'])->toContain('--metric=')
                ->and($defaultProps['signature'])->toContain('--use-strategy-ranges');
        });
    });

    describe('AggregateDataCommand', function () {
        it('extends Command', function () {
            expect(is_a(AggregateDataCommand::class, \Illuminate\Console\Command::class, true))->toBeTrue();
        });

        it('has signature with alphaforge prefix', function () {
            $ref = new \ReflectionClass(AggregateDataCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:');
        });
    });

    describe('RepairDataCommand', function () {
        it('extends Command', function () {
            expect(is_a(RepairDataCommand::class, \Illuminate\Console\Command::class, true))->toBeTrue();
        });

        it('has signature with alphaforge prefix', function () {
            $ref = new \ReflectionClass(RepairDataCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:');
        });
    });
});
