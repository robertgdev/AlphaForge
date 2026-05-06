<?php

use App\AlphaForge\Events\BacktestProgress;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;

describe('BacktestProgress', function () {
    it('can be constructed with all parameters', function () {
        $event = new BacktestProgress(
            backtestId: 'bt_123',
            userId: '42',
            percent: 50,
            message: 'Processing...'
        );

        expect($event->backtestId)->toBe('bt_123')
            ->and($event->userId)->toBe('42')
            ->and($event->percent)->toBe(50)
            ->and($event->message)->toBe('Processing...');
    });

    it('broadcasts on presence channel and user channel', function () {
        $event = new BacktestProgress(
            backtestId: 'bt_123',
            userId: '42',
            percent: 50,
            message: 'Processing...'
        );

        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(2)
            ->and($channels[0])->toBeInstanceOf(PresenceChannel::class)
            ->and($channels[1])->toBeInstanceOf(Channel::class);
    });

    it('broadcasts on correct presence channel name', function () {
        $event = new BacktestProgress(
            backtestId: 'bt_abc',
            userId: '1',
            percent: 0,
            message: 'Starting'
        );

        $channels = $event->broadcastOn();

        expect((string) $channels[0])->toBe('presence-backtest.bt_abc');
    });

    it('broadcasts on correct user channel name', function () {
        $event = new BacktestProgress(
            backtestId: 'bt_abc',
            userId: '7',
            percent: 0,
            message: 'Starting'
        );

        $channels = $event->broadcastOn();

        expect((string) $channels[1])->toBe('user.7.backtests');
    });

    it('broadcasts with correct event name', function () {
        $event = new BacktestProgress(
            backtestId: 'bt_123',
            userId: '42',
            percent: 50,
            message: 'Processing...'
        );

        expect($event->broadcastAs())->toBe('backtest.progress');
    });

    it('includes all required data in broadcast payload', function () {
        Carbon\Carbon::setTestNow('2024-06-15 14:00:00');

        $event = new BacktestProgress(
            backtestId: 'bt_xyz',
            userId: '10',
            percent: 75,
            message: 'Calculating statistics...'
        );

        $data = $event->broadcastWith();

        expect($data)->toHaveKeys(['backtest_id', 'percent', 'message', 'timestamp'])
            ->and($data['backtest_id'])->toBe('bt_xyz')
            ->and($data['percent'])->toBe(75)
            ->and($data['message'])->toBe('Calculating statistics...')
            ->and($data['timestamp'])->toBe('2024-06-15T14:00:00+00:00');

        Carbon\Carbon::setTestNow();
    });

    it('does not include userId in broadcast payload', function () {
        $event = new BacktestProgress(
            backtestId: 'bt_123',
            userId: '42',
            percent: 50,
            message: 'Processing...'
        );

        $data = $event->broadcastWith();

        expect($data)->not->toHaveKey('user_id');
    });

    it('uses dispatchable trait', function () {
        expect(method_exists(BacktestProgress::class, 'dispatch'))->toBeTrue();
    });

    it('handles zero percent', function () {
        $event = new BacktestProgress(
            backtestId: 'bt_1',
            userId: '1',
            percent: 0,
            message: 'Starting backtest...'
        );

        expect($event->percent)->toBe(0);

        $data = $event->broadcastWith();
        expect($data['percent'])->toBe(0);
    });

    it('handles 100 percent', function () {
        $event = new BacktestProgress(
            backtestId: 'bt_1',
            userId: '1',
            percent: 100,
            message: 'Completed'
        );

        expect($event->percent)->toBe(100);

        $data = $event->broadcastWith();
        expect($data['percent'])->toBe(100);
    });
});
