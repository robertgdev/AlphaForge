<?php

use App\AlphaForge\Console\Commands\AggregateDataCommand;
use App\AlphaForge\Console\Commands\DataCommand;
use App\AlphaForge\Console\Commands\OptimizeStrategyCommand;
use App\AlphaForge\Console\Commands\RepairDataCommand;
use App\AlphaForge\Console\Commands\RunBacktestCommand;
use App\AlphaForge\Console\Commands\WalkForwardCommand;
use Illuminate\Console\Command;

describe('Console Command Signatures', function () {
    describe('RunBacktestCommand', function () {
        it('has correct command signature', function () {
            $ref = new ReflectionClass(RunBacktestCommand::class);
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
            $ref = new ReflectionClass(RunBacktestCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['description'])->not->toBeEmpty();
        });

        it('extends Command', function () {
            expect(is_a(RunBacktestCommand::class, Command::class, true))->toBeTrue();
        });
    });

    describe('DataCommand', function () {
        it('has correct command signature', function () {
            $ref = new ReflectionClass(DataCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:data')
                ->and($defaultProps['signature'])->toContain('{action')
                ->and($defaultProps['signature'])->toContain('{exchange?')
                ->and($defaultProps['signature'])->toContain('{market?')
                ->and($defaultProps['signature'])->toContain('{timeframe?')
                ->and($defaultProps['signature'])->toContain('--force')
                ->and($defaultProps['signature'])->toContain('--with-dependencies');
        });

        it('has description', function () {
            $ref = new ReflectionClass(DataCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['description'])->not->toBeEmpty();
        });
    });

    describe('OptimizeStrategyCommand', function () {
        it('has correct command signature', function () {
            $ref = new ReflectionClass(OptimizeStrategyCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:optimize')
                ->and($defaultProps['signature'])->toContain('{strategy')
                ->and($defaultProps['signature'])->toContain('{symbol')
                ->and($defaultProps['signature'])->toContain('--params=')
                ->and($defaultProps['signature'])->toContain('--method=')
                ->and($defaultProps['signature'])->toContain('--objective=')
                ->and($defaultProps['signature'])->toContain('--use-strategy-ranges');
        });
    });

    describe('AggregateDataCommand', function () {
        it('extends Command', function () {
            expect(is_a(AggregateDataCommand::class, Command::class, true))->toBeTrue();
        });

        it('has signature with alphaforge prefix', function () {
            $ref = new ReflectionClass(AggregateDataCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:');
        });
    });

    describe('RepairDataCommand', function () {
        it('extends Command', function () {
            expect(is_a(RepairDataCommand::class, Command::class, true))->toBeTrue();
        });

        it('has signature with alphaforge prefix', function () {
            $ref = new ReflectionClass(RepairDataCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:');
        });
    });

    describe('WalkForwardCommand', function () {
        it('has correct command signature', function () {
            $ref = new ReflectionClass(WalkForwardCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:walk-forward')
                ->and($defaultProps['signature'])->toContain('{strategy')
                ->and($defaultProps['signature'])->toContain('{symbol')
                ->and($defaultProps['signature'])->toContain('--split=')
                ->and($defaultProps['signature'])->toContain('--oos-start=')
                ->and($defaultProps['signature'])->toContain('--method=')
                ->and($defaultProps['signature'])->toContain('--objective=')
                ->and($defaultProps['signature'])->toContain('--top-n=')
                ->and($defaultProps['signature'])->toContain('--use-strategy-ranges')
                ->and($defaultProps['signature'])->toContain('--execution-timeframe=')
                ->and($defaultProps['signature'])->toContain('--min-trades=')
                ->and($defaultProps['signature'])->toContain('--min-oos-days=')
                ->and($defaultProps['signature'])->toContain('--force')
                ->and($defaultProps['signature'])->toContain('--format=')
                ->and($defaultProps['signature'])->toContain('--output=');
        });

        it('has description', function () {
            $ref = new ReflectionClass(WalkForwardCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['description'])->not->toBeEmpty();
        });

        it('extends Command', function () {
            expect(is_a(WalkForwardCommand::class, Command::class, true))->toBeTrue();
        });
    });
});
