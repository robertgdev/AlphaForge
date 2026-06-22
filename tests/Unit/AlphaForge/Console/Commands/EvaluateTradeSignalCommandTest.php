<?php

use App\AlphaForge\Console\Commands\EvaluateAllTradeSignalsCommand;
use App\AlphaForge\Console\Commands\EvaluateTradeSignalCommand;
use App\AlphaForge\Models\TradeSignal;
use App\AlphaForge\Services\Dto\SignalEvaluationResult;
use Illuminate\Console\Command;

function makeSignalInstance(array $overrides = []): TradeSignal
{
    $signal = new TradeSignal(array_merge([
        'exchange' => 'binance',
        'symbol' => 'BTCUSDT',
        'direction' => 'LONG',
        'entry_price' => '50000',
        'stop_loss' => '49000',
        'take_profit' => '52000',
        'trailing_stop_enabled' => false,
        'trailing_stop_percent' => null,
        'trailing_stop_high_water_mark' => '0',
        'entry_timestamp' => 1700000000,
        'timeframe' => '1h',
        'status' => 'open',
    ], $overrides));

    $signal->id = '019a0000-0000-7000-8000-000000000001';

    return $signal;
}

describe('EvaluateTradeSignalCommand', function () {
    describe('signature', function () {
        it('has correct command signature', function () {
            $ref = new ReflectionClass(EvaluateTradeSignalCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:signal:evaluate')
                ->and($defaultProps['signature'])->toContain('{direction?')
                ->and($defaultProps['signature'])->toContain('{exchange?')
                ->and($defaultProps['signature'])->toContain('{symbol?')
                ->and($defaultProps['signature'])->toContain('{entry-price?')
                ->and($defaultProps['signature'])->toContain('{stop-loss?')
                ->and($defaultProps['signature'])->toContain('{take-profit?')
                ->and($defaultProps['signature'])->toContain('--entry-timestamp=')
                ->and($defaultProps['signature'])->toContain('--trailing-percent=')
                ->and($defaultProps['signature'])->toContain('--timeframe=')
                ->and($defaultProps['signature'])->toContain('--re-evaluate')
                ->and($defaultProps['signature'])->toContain('--signal-id=')
                ->and($defaultProps['signature'])->toContain('--list-open');
        });

        it('has description', function () {
            $ref = new ReflectionClass(EvaluateTradeSignalCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['description'])->not->toBeEmpty();
        });

        it('extends Command', function () {
            expect(is_a(EvaluateTradeSignalCommand::class, Command::class, true))->toBeTrue();
        });
    });

    describe('applyResult()', function () {
        it('applies winner result to model', function () {
            $cmd = new EvaluateTradeSignalCommand;
            $signal = makeSignalInstance();
            $result = new SignalEvaluationResult(
                status: 'winner',
                exitPrice: 52000.0,
                exitTimestamp: 1700000100,
                exitReason: 'take_profit',
                profitLossPct: 4.0,
                profitLossAbs: 2000.0,
            );

            $ref = new ReflectionMethod($cmd, 'applyResult');
            $ref->invoke($cmd, $signal, $result);

            expect($signal->status)->toBe('winner');
            expect((float) $signal->exit_price)->toBe(52000.0);
            expect($signal->exit_timestamp)->toBe(1700000100);
            expect($signal->exit_reason)->toBe('take_profit');
            expect((float) $signal->profit_loss_pct)->toBe(4.0);
            expect((float) $signal->profit_loss_abs)->toBe(2000.0);
        });

        it('applies loser result to model', function () {
            $cmd = new EvaluateTradeSignalCommand;
            $signal = makeSignalInstance();
            $result = new SignalEvaluationResult(
                status: 'loser',
                exitPrice: 49000.0,
                exitTimestamp: 1700000100,
                exitReason: 'stop_loss',
                profitLossPct: -2.0,
                profitLossAbs: -1000.0,
            );

            $ref = new ReflectionMethod($cmd, 'applyResult');
            $ref->invoke($cmd, $signal, $result);

            expect($signal->status)->toBe('loser');
            expect((float) $signal->exit_price)->toBe(49000.0);
            expect($signal->exit_reason)->toBe('stop_loss');
        });

        it('does not modify status when result is open', function () {
            $cmd = new EvaluateTradeSignalCommand;
            $signal = makeSignalInstance();
            $result = new SignalEvaluationResult(status: 'open');

            $ref = new ReflectionMethod($cmd, 'applyResult');
            $ref->invoke($cmd, $signal, $result);

            expect($signal->status)->toBe('open');
            expect($signal->exit_price)->toBeNull();
            expect($signal->exit_reason)->toBeNull();
        });
    });
});

describe('EvaluateAllTradeSignalsCommand', function () {
    describe('signature', function () {
        it('has correct command signature', function () {
            $ref = new ReflectionClass(EvaluateAllTradeSignalsCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['signature'])->toContain('alphaforge:signal:evaluate-all')
                ->and($defaultProps['signature'])->toContain('--timeframe=')
                ->and($defaultProps['signature'])->toContain('--symbol=')
                ->and($defaultProps['signature'])->toContain('--limit=');
        });

        it('has description', function () {
            $ref = new ReflectionClass(EvaluateAllTradeSignalsCommand::class);
            $defaultProps = $ref->getDefaultProperties();

            expect($defaultProps['description'])->not->toBeEmpty();
        });

        it('extends Command', function () {
            expect(is_a(EvaluateAllTradeSignalsCommand::class, Command::class, true))->toBeTrue();
        });
    });
});
