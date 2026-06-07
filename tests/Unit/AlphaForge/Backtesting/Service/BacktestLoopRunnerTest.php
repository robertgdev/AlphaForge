<?php

use App\AlphaForge\Backtesting\Service\BacktestLoopRunner;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\ExitRule\ExitTrigger;
use App\AlphaForge\Order\Dto\PositionDto;
use Carbon\Carbon;
use Ds\Map;
use Ds\Vector;

function makeRunner(array $overrides = []): BacktestLoopRunner
{
    $ohlcvData = new Map;
    $ohlcvData->put('BTC/USDT', Mockery::mock(OhlcvSeries::class));

    return new BacktestLoopRunner(
        initialCapital: $overrides['initialCapital'] ?? '10000',
        commissionConfig: $overrides['commissionConfig'] ?? [],
        barTimestamps: $overrides['barTimestamps'] ?? [1700000000, 1700003600],
        barOpens: $overrides['barOpens'] ?? [100.0, 101.0],
        barHighs: $overrides['barHighs'] ?? [105.0, 106.0],
        barLows: $overrides['barLows'] ?? [95.0, 96.0],
        barCloses: $overrides['barCloses'] ?? [100.0, 101.0],
        barVolumes: $overrides['barVolumes'] ?? [1000.0, 1100.0],
        execTimestamps: $overrides['execTimestamps'] ?? null,
        execOpens: $overrides['execOpens'] ?? null,
        execHighs: $overrides['execHighs'] ?? null,
        execLows: $overrides['execLows'] ?? null,
        execCloses: $overrides['execCloses'] ?? null,
        execVolumes: $overrides['execVolumes'] ?? null,
        ohlcvData: $overrides['ohlcvData'] ?? $ohlcvData,
        executionOhlcvData: $overrides['executionOhlcvData'] ?? null,
        strategy: $overrides['strategy'] ?? new class {},
        signalTimeframe: $overrides['signalTimeframe'] ?? null,
        executionTimeframe: $overrides['executionTimeframe'] ?? null,
        multiTimeframeData: $overrides['multiTimeframeData'] ?? null,
        progressCallback: $overrides['progressCallback'] ?? null,
    );
}

function makePositionDto(array $overrides = []): PositionDto
{
    return new PositionDto(
        id: $overrides['id'] ?? 'pos_test',
        symbol: $overrides['symbol'] ?? 'BTC/USDT',
        direction: $overrides['direction'] ?? 'long',
        quantity: $overrides['quantity'] ?? '1',
        entryPrice: $overrides['entryPrice'] ?? '100',
        entryTime: $overrides['entryTime'] ?? Carbon::now(),
        realizedPnl: $overrides['realizedPnl'] ?? '0',
        stopLoss: $overrides['stopLoss'] ?? null,
        takeProfit: $overrides['takeProfit'] ?? null,
    );
}

function invokePrivate(object $object, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($object, $method);

    return $ref->invoke($object, ...$args);
}

describe('BacktestLoopRunner', function () {
    describe('constructor', function () {
        it('initializes positions as empty Vector', function () {
            $runner = makeRunner();

            expect($runner->positions)->toBeInstanceOf(Vector::class)
                ->and($runner->positions->count())->toBe(0);
        });

        it('initializes currentCapital from initialCapital', function () {
            $runner = makeRunner(['initialCapital' => '50000']);

            expect($runner->currentCapital)->toBe('50000');
        });

        it('initializes barEquityCurve as empty Vector', function () {
            $runner = makeRunner();

            expect($runner->barEquityCurve)->toBeInstanceOf(Vector::class)
                ->and($runner->barEquityCurve->count())->toBe(0);
        });

        it('initializes positionTradeDetails as empty array', function () {
            $runner = makeRunner();

            expect($runner->positionTradeDetails)->toBe([]);
        });

        it('initializes watermarks as empty arrays', function () {
            $runner = makeRunner();

            expect($runner->highWaterMarks)->toBe([])
                ->and($runner->lowWaterMarks)->toBe([])
                ->and($runner->barsInPositionTracker)->toBe([]);
        });
    });

    describe('checkStaticSlTp (private)', function () {
        it('returns null when position has no stop loss or take profit', function () {
            $runner = makeRunner();
            $position = makePositionDto();
            $bar = [
                BacktestLoopRunner::BAR_T => 1700000000,
                BacktestLoopRunner::BAR_O => 100.0,
                BacktestLoopRunner::BAR_H => 105.0,
                BacktestLoopRunner::BAR_L => 95.0,
                BacktestLoopRunner::BAR_C => 100.0,
                BacktestLoopRunner::BAR_V => 1000.0,
            ];

            $result = invokePrivate($runner, 'checkStaticSlTp', [$position, $bar]);

            expect($result)->toBeNull();
        });

        it('triggers stop_loss for long when low breaches SL', function () {
            $runner = makeRunner();
            $position = makePositionDto(['stopLoss' => '97']);
            $bar = [
                BacktestLoopRunner::BAR_T => 1700000000,
                BacktestLoopRunner::BAR_O => 100.0,
                BacktestLoopRunner::BAR_H => 105.0,
                BacktestLoopRunner::BAR_L => 96.0,
                BacktestLoopRunner::BAR_C => 100.0,
                BacktestLoopRunner::BAR_V => 1000.0,
            ];

            $result = invokePrivate($runner, 'checkStaticSlTp', [$position, $bar]);

            expect($result)->toBeInstanceOf(ExitTrigger::class)
                ->and($result->ruleId)->toBe('stop_loss')
                ->and($result->exitPrice)->toBe(97.0);
        });

        it('does not trigger stop_loss when low does not breach SL', function () {
            $runner = makeRunner();
            $position = makePositionDto(['stopLoss' => '97']);
            $bar = [
                BacktestLoopRunner::BAR_T => 1700000000,
                BacktestLoopRunner::BAR_O => 100.0,
                BacktestLoopRunner::BAR_H => 105.0,
                BacktestLoopRunner::BAR_L => 98.0,
                BacktestLoopRunner::BAR_C => 100.0,
                BacktestLoopRunner::BAR_V => 1000.0,
            ];

            $result = invokePrivate($runner, 'checkStaticSlTp', [$position, $bar]);

            expect($result)->toBeNull();
        });

        it('triggers stop_loss for short when high breaches SL', function () {
            $runner = makeRunner();
            $position = makePositionDto(['direction' => 'short', 'stopLoss' => '105']);
            $bar = [
                BacktestLoopRunner::BAR_T => 1700000000,
                BacktestLoopRunner::BAR_O => 100.0,
                BacktestLoopRunner::BAR_H => 106.0,
                BacktestLoopRunner::BAR_L => 95.0,
                BacktestLoopRunner::BAR_C => 100.0,
                BacktestLoopRunner::BAR_V => 1000.0,
            ];

            $result = invokePrivate($runner, 'checkStaticSlTp', [$position, $bar]);

            expect($result)->toBeInstanceOf(ExitTrigger::class)
                ->and($result->ruleId)->toBe('stop_loss')
                ->and($result->exitPrice)->toBe(105.0);
        });

        it('triggers take_profit for long when high breaches TP', function () {
            $runner = makeRunner();
            $position = makePositionDto(['takeProfit' => '108']);
            $bar = [
                BacktestLoopRunner::BAR_T => 1700000000,
                BacktestLoopRunner::BAR_O => 100.0,
                BacktestLoopRunner::BAR_H => 109.0,
                BacktestLoopRunner::BAR_L => 95.0,
                BacktestLoopRunner::BAR_C => 100.0,
                BacktestLoopRunner::BAR_V => 1000.0,
            ];

            $result = invokePrivate($runner, 'checkStaticSlTp', [$position, $bar]);

            expect($result)->toBeInstanceOf(ExitTrigger::class)
                ->and($result->ruleId)->toBe('take_profit')
                ->and($result->exitPrice)->toBe(108.0);
        });

        it('triggers take_profit for short when low breaches TP', function () {
            $runner = makeRunner();
            $position = makePositionDto(['direction' => 'short', 'takeProfit' => '90']);
            $bar = [
                BacktestLoopRunner::BAR_T => 1700000000,
                BacktestLoopRunner::BAR_O => 100.0,
                BacktestLoopRunner::BAR_H => 105.0,
                BacktestLoopRunner::BAR_L => 89.0,
                BacktestLoopRunner::BAR_C => 100.0,
                BacktestLoopRunner::BAR_V => 1000.0,
            ];

            $result = invokePrivate($runner, 'checkStaticSlTp', [$position, $bar]);

            expect($result)->toBeInstanceOf(ExitTrigger::class)
                ->and($result->ruleId)->toBe('take_profit')
                ->and($result->exitPrice)->toBe(90.0);
        });

        it('prefers stop_loss over take_profit when both breach', function () {
            $runner = makeRunner();
            $position = makePositionDto(['stopLoss' => '95', 'takeProfit' => '105']);
            $bar = [
                BacktestLoopRunner::BAR_T => 1700000000,
                BacktestLoopRunner::BAR_O => 100.0,
                BacktestLoopRunner::BAR_H => 110.0,
                BacktestLoopRunner::BAR_L => 90.0,
                BacktestLoopRunner::BAR_C => 100.0,
                BacktestLoopRunner::BAR_V => 1000.0,
            ];

            $result = invokePrivate($runner, 'checkStaticSlTp', [$position, $bar]);

            expect($result->ruleId)->toBe('stop_loss');
        });

        it('triggers when low equals SL exactly', function () {
            $runner = makeRunner();
            $position = makePositionDto(['stopLoss' => '95']);
            $bar = [
                BacktestLoopRunner::BAR_T => 1700000000,
                BacktestLoopRunner::BAR_O => 100.0,
                BacktestLoopRunner::BAR_H => 105.0,
                BacktestLoopRunner::BAR_L => 95.0,
                BacktestLoopRunner::BAR_C => 100.0,
                BacktestLoopRunner::BAR_V => 1000.0,
            ];

            $result = invokePrivate($runner, 'checkStaticSlTp', [$position, $bar]);

            expect($result)->not->toBeNull()
                ->and($result->ruleId)->toBe('stop_loss');
        });
    });

    describe('buildTradeDetail (private)', function () {
        it('includes direction and entry/exit prices', function () {
            $runner = makeRunner();
            $position = new PositionDto(
                id: 'pos_1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.01',
                entryPrice: '50000',
                entryTime: Carbon::createFromTimestamp(1700000000),
                realizedPnl: '150.50',
                exitPrice: '50150.50',
                exitTime: Carbon::createFromTimestamp(1700003600),
            );

            $result = invokePrivate($runner, 'buildTradeDetail', [$position, 51000.0, 49000.0, 10]);

            expect($result['direction'])->toBe('long')
                ->and($result['entry_price'])->toBe(50000.0)
                ->and($result['exit_price'])->toBe(50150.5)
                ->and($result['pnl'])->toBe(150.5)
                ->and($result['bars_held'])->toBe(10)
                ->and($result['quantity'])->toBe(0.01);
        });

        it('computes MFE and MAE for long positions', function () {
            $runner = makeRunner();
            $position = new PositionDto(
                id: 'pos_1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '1',
                entryPrice: '100',
                entryTime: Carbon::now(),
                realizedPnl: '10',
            );

            $result = invokePrivate($runner, 'buildTradeDetail', [$position, 120.0, 90.0, 5]);

            expect($result['mfe'])->toBe(20.0)
                ->and($result['mae'])->toBe(10.0);
        });

        it('computes MFE and MAE for short positions', function () {
            $runner = makeRunner();
            $position = new PositionDto(
                id: 'pos_1',
                symbol: 'BTC/USDT',
                direction: 'short',
                quantity: '1',
                entryPrice: '100',
                entryTime: Carbon::now(),
                realizedPnl: '10',
            );

            $result = invokePrivate($runner, 'buildTradeDetail', [$position, 115.0, 85.0, 5]);

            expect($result['mfe'])->toBe(15.0)
                ->and($result['mae'])->toBe(15.0);
        });

        it('sets MAE and MFE to null when watermarks are null', function () {
            $runner = makeRunner();
            $position = new PositionDto(
                id: 'pos_1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '1',
                entryPrice: '100',
                entryTime: Carbon::now(),
                realizedPnl: '10',
            );

            $result = invokePrivate($runner, 'buildTradeDetail', [$position, null, null, null]);

            expect($result['mfe'])->toBeNull()
                ->and($result['mae'])->toBeNull()
                ->and($result['bars_held'])->toBeNull();
        });
    });

    describe('with execution timeframe', function () {
        it('accepts execution timeframe arrays', function () {
            $runner = makeRunner([
                'signalTimeframe' => TimeframeEnum::H1,
                'executionTimeframe' => TimeframeEnum::M1,
                'execTimestamps' => [1700000000, 1700000060],
                'execOpens' => [100.0, 100.5],
                'execHighs' => [101.0, 101.5],
                'execLows' => [99.0, 99.5],
                'execCloses' => [100.5, 101.0],
                'execVolumes' => [500.0, 600.0],
            ]);

            expect($runner->positions)->toBeInstanceOf(Vector::class);
        });
    });
});
