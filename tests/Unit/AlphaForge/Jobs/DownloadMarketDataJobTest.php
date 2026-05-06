<?php

use App\AlphaForge\Data\Dto\DownloadRequestDto;
use App\AlphaForge\Jobs\DownloadMarketDataJob;
use Carbon\Carbon;

describe('DownloadMarketDataJob', function () {
    beforeEach(function () {
        $this->startDate = Carbon::parse('2024-01-01');
        $this->endDate = Carbon::parse('2024-06-30');

        $this->job = new DownloadMarketDataJob(
            exchangeId: 'binance',
            symbol: 'BTC/USDT',
            timeframe: '1h',
            startDate: $this->startDate,
            endDate: $this->endDate,
            forceOverwrite: true,
            jobId: 'download_test_123'
        );
    });

    describe('constructor', function () {
        it('sets all properties correctly', function () {
            expect($this->job->exchangeId)->toBe('binance')
                ->and($this->job->symbol)->toBe('BTC/USDT')
                ->and($this->job->timeframe)->toBe('1h')
                ->and($this->job->startDate->toDateString())->toBe('2024-01-01')
                ->and($this->job->endDate->toDateString())->toBe('2024-06-30')
                ->and($this->job->forceOverwrite)->toBeTrue()
                ->and($this->job->jobId)->toBe('download_test_123');
        });

        it('defaults forceOverwrite to false', function () {
            $job = new DownloadMarketDataJob(
                exchangeId: 'kraken',
                symbol: 'ETH/USDT',
                timeframe: '1d',
                startDate: Carbon::parse('2024-01-01'),
                endDate: Carbon::parse('2024-06-30')
            );

            expect($job->forceOverwrite)->toBeFalse();
        });

        it('generates jobId when not provided', function () {
            $job = new DownloadMarketDataJob(
                exchangeId: 'binance',
                symbol: 'BTC/USDT',
                timeframe: '1h',
                startDate: Carbon::parse('2024-01-01'),
                endDate: Carbon::parse('2024-06-30')
            );

            expect($job->jobId)->toStartWith('download_');
        });

        it('uses provided jobId when given', function () {
            $job = new DownloadMarketDataJob(
                exchangeId: 'binance',
                symbol: 'BTC/USDT',
                timeframe: '1h',
                startDate: Carbon::parse('2024-01-01'),
                endDate: Carbon::parse('2024-06-30'),
                forceOverwrite: false,
                jobId: 'custom_id'
            );

            expect($job->jobId)->toBe('custom_id');
        });
    });

    describe('job configuration', function () {
        it('has correct number of tries', function () {
            expect($this->job->tries)->toBe(3);
        });

        it('has correct timeout', function () {
            expect($this->job->timeout)->toBe(3600);
        });

        it('implements ShouldQueue', function () {
            expect($this->job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
        });
    });

    describe('tags', function () {
        it('returns correct tags', function () {
            $tags = $this->job->tags();

            expect($tags)->toBe([
                'exchange:binance',
                'symbol:BTC/USDT',
                'timeframe:1h',
            ]);
        });

        it('returns tags with different values', function () {
            $job = new DownloadMarketDataJob(
                exchangeId: 'kraken',
                symbol: 'ETH/USDT',
                timeframe: '4h',
                startDate: Carbon::parse('2024-01-01'),
                endDate: Carbon::parse('2024-06-30')
            );

            expect($job->tags())->toBe([
                'exchange:kraken',
                'symbol:ETH/USDT',
                'timeframe:4h',
            ]);
        });
    });

    describe('toDto', function () {
        it('creates DownloadRequestDto with correct values', function () {
            $dto = $this->job->toDto();

            expect($dto)->toBeInstanceOf(DownloadRequestDto::class)
                ->and($dto->exchangeId)->toBe('binance')
                ->and($dto->symbol)->toBe('BTC/USDT')
                ->and($dto->timeframe)->toBe('1h')
                ->and($dto->startDate->toDateString())->toBe('2024-01-01')
                ->and($dto->endDate->toDateString())->toBe('2024-06-30')
                ->and($dto->forceOverwrite)->toBeTrue();
        });

        it('creates dto with forceOverwrite false when not set', function () {
            $job = new DownloadMarketDataJob(
                exchangeId: 'kraken',
                symbol: 'ETH/USDT',
                timeframe: '4h',
                startDate: Carbon::parse('2024-01-01'),
                endDate: Carbon::parse('2024-06-30')
            );

            $dto = $job->toDto();

            expect($dto->forceOverwrite)->toBeFalse();
        });

        it('dto endDate is preserved from job', function () {
            $dto = $this->job->toDto();

            expect($dto->endDate->toDateString())->toBe('2024-06-30');
        });
    });
});
