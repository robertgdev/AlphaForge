<?php

use App\AlphaForge\Console\Commands\AggregateDataCommand;
use App\AlphaForge\Console\Commands\DataDeleteCommand;
use App\AlphaForge\Console\Commands\DataExportCommand;
use App\AlphaForge\Console\Commands\DataImportCommand;
use App\AlphaForge\Console\Commands\DataInfoCommand;
use App\AlphaForge\Console\Commands\DataListCommand;
use App\AlphaForge\Console\Commands\DataUpdateCommand;
use App\AlphaForge\Console\Commands\ExportOptimizeCommand;
use App\AlphaForge\Console\Commands\ExportTradesCommand;
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
                ->and($defaultProps['signature'])->toContain('--async')
                ->and($defaultProps['signature'])->toContain('--force')
                ->and($defaultProps['signature'])->toContain('--trades=');
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

    describe('DataImportCommand', function () {
        it('has correct command signature', function () {
            $ref = new ReflectionClass(DataImportCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:data:import')
                ->and($defaultProps['signature'])->toContain('{exchange')
                ->and($defaultProps['signature'])->toContain('{market')
                ->and($defaultProps['signature'])->toContain('{timeframe')
                ->and($defaultProps['signature'])->toContain('{startdate')
                ->and($defaultProps['signature'])->toContain('{enddate?')
                ->and($defaultProps['signature'])->toContain('--force');
        });

        it('has description', function () {
            $ref = new ReflectionClass(DataImportCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['description'])->not->toBeEmpty();
        });

        it('extends Command', function () {
            expect(is_a(DataImportCommand::class, Command::class, true))->toBeTrue();
        });
    });

    describe('DataUpdateCommand', function () {
        it('has correct command signature', function () {
            $ref = new ReflectionClass(DataUpdateCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:data:update')
                ->and($defaultProps['signature'])->toContain('{exchange')
                ->and($defaultProps['signature'])->toContain('{market')
                ->and($defaultProps['signature'])->toContain('{timeframe')
                ->and($defaultProps['signature'])->toContain('{enddate?')
                ->and($defaultProps['signature'])->toContain('--with-dependencies');
        });

        it('has description', function () {
            $ref = new ReflectionClass(DataUpdateCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['description'])->not->toBeEmpty();
        });

        it('extends Command', function () {
            expect(is_a(DataUpdateCommand::class, Command::class, true))->toBeTrue();
        });
    });

    describe('DataDeleteCommand', function () {
        it('has correct command signature', function () {
            $ref = new ReflectionClass(DataDeleteCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:data:delete')
                ->and($defaultProps['signature'])->toContain('{exchange')
                ->and($defaultProps['signature'])->toContain('{market')
                ->and($defaultProps['signature'])->toContain('{timeframe')
                ->and($defaultProps['signature'])->toContain('--force');
        });

        it('has description', function () {
            $ref = new ReflectionClass(DataDeleteCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['description'])->not->toBeEmpty();
        });

        it('extends Command', function () {
            expect(is_a(DataDeleteCommand::class, Command::class, true))->toBeTrue();
        });
    });

    describe('DataInfoCommand', function () {
        it('has correct command signature', function () {
            $ref = new ReflectionClass(DataInfoCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:data:info')
                ->and($defaultProps['signature'])->toContain('{exchange')
                ->and($defaultProps['signature'])->toContain('{market')
                ->and($defaultProps['signature'])->toContain('{timeframe');
        });

        it('has description', function () {
            $ref = new ReflectionClass(DataInfoCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['description'])->not->toBeEmpty();
        });

        it('extends Command', function () {
            expect(is_a(DataInfoCommand::class, Command::class, true))->toBeTrue();
        });
    });

    describe('DataListCommand', function () {
        it('has correct command signature', function () {
            $ref = new ReflectionClass(DataListCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:data:list')
                ->and($defaultProps['signature'])->toContain('--exchange-filter=')
                ->and($defaultProps['signature'])->toContain('--symbol-filter=');
        });

        it('has description', function () {
            $ref = new ReflectionClass(DataListCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['description'])->not->toBeEmpty();
        });

        it('extends Command', function () {
            expect(is_a(DataListCommand::class, Command::class, true))->toBeTrue();
        });
    });

    describe('DataExportCommand', function () {
        it('has correct command signature', function () {
            $ref = new ReflectionClass(DataExportCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:data:export')
                ->and($defaultProps['signature'])->toContain('{exchange')
                ->and($defaultProps['signature'])->toContain('{market')
                ->and($defaultProps['signature'])->toContain('{timeframe');
        });

        it('has description', function () {
            $ref = new ReflectionClass(DataExportCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['description'])->not->toBeEmpty();
        });

        it('extends Command', function () {
            expect(is_a(DataExportCommand::class, Command::class, true))->toBeTrue();
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
                ->and($defaultProps['signature'])->toContain('--use-strategy-ranges')
                ->and($defaultProps['signature'])->toContain('--data-type=')
                ->and($defaultProps['signature'])->toContain('--brick-size=')
                ->and($defaultProps['signature'])->toContain('--atr-period=')
                ->and($defaultProps['signature'])->toContain('--progress=')
                ->and($defaultProps['signature'])->toContain('--execution-timeframe=');
        });
    });

    describe('PortfolioOptimizeCommand', function () {
        it('has correct command signature', function () {
            $ref = new ReflectionClass(\App\AlphaForge\Console\Commands\PortfolioOptimizeCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:optimize:portfolio')
                ->and($defaultProps['signature'])->toContain('{strategy')
                ->and($defaultProps['signature'])->toContain('{symbols*')
                ->and($defaultProps['signature'])->toContain('--method=')
                ->and($defaultProps['signature'])->toContain('--timeframe=')
                ->and($defaultProps['signature'])->toContain('--execution-timeframe=')
                ->and($defaultProps['signature'])->toContain('--start-date=')
                ->and($defaultProps['signature'])->toContain('--end-date=');
        });

        it('has description', function () {
            $ref = new ReflectionClass(\App\AlphaForge\Console\Commands\PortfolioOptimizeCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['description'])->not->toBeEmpty();
        });

        it('extends Command', function () {
            expect(is_a(\App\AlphaForge\Console\Commands\PortfolioOptimizeCommand::class, Command::class, true))->toBeTrue();
        });
    });

    describe('AggregateDataCommand', function () {
        it('extends Command', function () {
            expect(is_a(AggregateDataCommand::class, Command::class, true))->toBeTrue();
        });

        it('has correct command signature', function () {
            $ref = new ReflectionClass(AggregateDataCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:data:aggregate')
                ->and($defaultProps['signature'])->toContain('{exchange')
                ->and($defaultProps['signature'])->toContain('{market')
                ->and($defaultProps['signature'])->toContain('{source_timeframe')
                ->and($defaultProps['signature'])->toContain('{target_timeframe')
                ->and($defaultProps['signature'])->toContain('--force')
                ->and($defaultProps['signature'])->toContain('--update');
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

    describe('ExportTradesCommand', function () {
        it('has correct command signature', function () {
            $ref = new ReflectionClass(ExportTradesCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:export:backtest')
                ->and($defaultProps['signature'])->toContain('{backtest_id')
                ->and($defaultProps['signature'])->toContain('--format=')
                ->and($defaultProps['signature'])->toContain('--output=');
        });

        it('has description', function () {
            $ref = new ReflectionClass(ExportTradesCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['description'])->not->toBeEmpty();
        });

        it('extends Command', function () {
            expect(is_a(ExportTradesCommand::class, Command::class, true))->toBeTrue();
        });
    });

    describe('ExportOptimizeCommand', function () {
        it('has correct command signature', function () {
            $ref = new ReflectionClass(ExportOptimizeCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:export:optimize')
                ->and($defaultProps['signature'])->toContain('{optimization_id')
                ->and($defaultProps['signature'])->toContain('--format=')
                ->and($defaultProps['signature'])->toContain('--output=')
                ->and($defaultProps['signature'])->toContain('--top=');
        });

        it('has description', function () {
            $ref = new ReflectionClass(ExportOptimizeCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['description'])->not->toBeEmpty();
        });

        it('extends Command', function () {
            expect(is_a(ExportOptimizeCommand::class, Command::class, true))->toBeTrue();
        });
    });
});
