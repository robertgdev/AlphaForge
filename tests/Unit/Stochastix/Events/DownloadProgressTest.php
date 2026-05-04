<?php

use App\Stochastix\Events\DownloadProgress;
use Carbon\Carbon;
use Illuminate\Broadcasting\PresenceChannel;

describe('DownloadProgress', function () {
    it('can be constructed with all parameters', function () {
        $event = new DownloadProgress(
            jobId: 'download_123',
            symbol: 'BTC/USDT',
            lastTimestamp: 1672531200,
            recordsFetchedInBatch: 500,
            totalDuration: 31536000,
            currentProgress: 15768000
        );

        expect($event->jobId)->toBe('download_123')
            ->and($event->symbol)->toBe('BTC/USDT')
            ->and($event->lastTimestamp)->toBe(1672531200)
            ->and($event->recordsFetchedInBatch)->toBe(500)
            ->and($event->totalDuration)->toBe(31536000)
            ->and($event->currentProgress)->toBe(15768000);
    });

    it('broadcasts on presence channel when jobId is set', function () {
        $event = new DownloadProgress(
            jobId: 'download_123',
            symbol: 'BTC/USDT',
            lastTimestamp: 1672531200,
            recordsFetchedInBatch: 500,
            totalDuration: 31536000,
            currentProgress: 15768000
        );

        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(1)
            ->and($channels[0])->toBeInstanceOf(PresenceChannel::class);
    });

    it('returns empty array when jobId is null', function () {
        $event = new DownloadProgress(
            jobId: null,
            symbol: 'BTC/USDT',
            lastTimestamp: 1672531200,
            recordsFetchedInBatch: 500,
            totalDuration: 31536000,
            currentProgress: 15768000
        );

        $channels = $event->broadcastOn();

        expect($channels)->toBeEmpty();
    });

    it('broadcasts with correct event name', function () {
        $event = new DownloadProgress(
            jobId: 'download_123',
            symbol: 'BTC/USDT',
            lastTimestamp: 1672531200,
            recordsFetchedInBatch: 500,
            totalDuration: 31536000,
            currentProgress: 15768000
        );

        expect($event->broadcastAs())->toBe('download.progress');
    });

    it('calculates percent complete correctly', function () {
        $event = new DownloadProgress(
            jobId: 'download_123',
            symbol: 'BTC/USDT',
            lastTimestamp: 1672531200,
            recordsFetchedInBatch: 500,
            totalDuration: 100000,
            currentProgress: 50000
        );

        $data = $event->broadcastWith();

        expect($data['percent_complete'])->toBe(50.0);
    });

    it('caps percent complete at 100', function () {
        $event = new DownloadProgress(
            jobId: 'download_123',
            symbol: 'BTC/USDT',
            lastTimestamp: 1672531200,
            recordsFetchedInBatch: 500,
            totalDuration: 100000,
            currentProgress: 150000
        );

        $data = $event->broadcastWith();

        expect($data['percent_complete'])->toBe(100);
    });

    it('handles zero total duration', function () {
        $event = new DownloadProgress(
            jobId: 'download_123',
            symbol: 'BTC/USDT',
            lastTimestamp: 1672531200,
            recordsFetchedInBatch: 500,
            totalDuration: 0,
            currentProgress: 0
        );

        $data = $event->broadcastWith();

        expect($data['percent_complete'])->toBe(0);
    });

    it('includes all required data in broadcast payload', function () {
        Carbon::setTestNow('2024-01-15 10:30:00');

        $event = new DownloadProgress(
            jobId: 'download_123',
            symbol: 'BTC/USDT',
            lastTimestamp: 1672531200,
            recordsFetchedInBatch: 500,
            totalDuration: 31536000,
            currentProgress: 15768000
        );

        $data = $event->broadcastWith();

        expect($data)->toHaveKeys([
            'job_id',
            'symbol',
            'last_timestamp',
            'records_in_batch',
            'percent_complete',
            'timestamp',
        ])
            ->and($data['job_id'])->toBe('download_123')
            ->and($data['symbol'])->toBe('BTC/USDT')
            ->and($data['last_timestamp'])->toBe(1672531200)
            ->and($data['records_in_batch'])->toBe(500);

        Carbon::setTestNow();
    });
});
