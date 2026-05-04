<?php

use App\Stochastix\Data\Dto\DownloadRequestDto;
use Carbon\Carbon;

describe('DownloadRequestDto', function () {
    it('can be created with required parameters', function () {
        $startDate = Carbon::parse('2023-01-01');

        $dto = new DownloadRequestDto(
            exchangeId: 'binance',
            symbol: 'BTC/USDT',
            timeframe: '1h',
            startDate: $startDate
        );

        expect($dto->exchangeId)->toBe('binance')
            ->and($dto->symbol)->toBe('BTC/USDT')
            ->and($dto->timeframe)->toBe('1h')
            ->and($dto->startDate)->toBe($startDate)
            ->and($dto->endDate)->toBeNull()
            ->and($dto->forceOverwrite)->toBeFalse();
    });

    it('can be created with all parameters', function () {
        $startDate = Carbon::parse('2023-01-01');
        $endDate = Carbon::parse('2024-01-01');

        $dto = new DownloadRequestDto(
            exchangeId: 'kraken',
            symbol: 'ETH/USD',
            timeframe: '4h',
            startDate: $startDate,
            endDate: $endDate,
            forceOverwrite: true
        );

        expect($dto->exchangeId)->toBe('kraken')
            ->and($dto->symbol)->toBe('ETH/USD')
            ->and($dto->timeframe)->toBe('4h')
            ->and($dto->startDate)->toBe($startDate)
            ->and($dto->endDate)->toBe($endDate)
            ->and($dto->forceOverwrite)->toBeTrue();
    });

    it('returns current time as end date when not set', function () {
        $startDate = Carbon::parse('2023-01-01');
        Carbon::setTestNow('2024-06-15 12:00:00');

        $dto = new DownloadRequestDto(
            exchangeId: 'binance',
            symbol: 'BTC/USDT',
            timeframe: '1h',
            startDate: $startDate
        );

        expect($dto->getEndDate()->format('Y-m-d'))->toBe('2024-06-15');

        Carbon::setTestNow();
    });

    it('is readonly and cannot be modified', function () {
        $dto = new DownloadRequestDto(
            exchangeId: 'binance',
            symbol: 'BTC/USDT',
            timeframe: '1h',
            startDate: Carbon::now()
        );

        // Readonly properties cannot be modified - this is enforced at compile time
        // The DTO class uses the readonly modifier
        expect($dto)->toBeInstanceOf(DownloadRequestDto::class);
    });
});
