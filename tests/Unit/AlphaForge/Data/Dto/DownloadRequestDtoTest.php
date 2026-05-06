<?php

use App\AlphaForge\Data\Dto\DownloadRequestDto;
use Carbon\Carbon;

describe('DownloadRequestDto', function () {
    it('creates with required parameters', function () {
        $startDate = Carbon::parse('2024-01-01');
        $dto = new DownloadRequestDto(
            exchangeId: 'binance',
            symbol: 'BTC/USDT',
            timeframe: '1h',
            startDate: $startDate,
        );

        expect($dto->exchangeId)->toBe('binance')
            ->and($dto->symbol)->toBe('BTC/USDT')
            ->and($dto->timeframe)->toBe('1h')
            ->and($dto->startDate)->toBe($startDate)
            ->and($dto->endDate)->toBeNull()
            ->and($dto->forceOverwrite)->toBeFalse();
    });

    it('creates with all parameters', function () {
        $startDate = Carbon::parse('2024-01-01');
        $endDate = Carbon::parse('2024-12-31');
        $dto = new DownloadRequestDto(
            exchangeId: 'kraken',
            symbol: 'ETH/USDT',
            timeframe: '4h',
            startDate: $startDate,
            endDate: $endDate,
            forceOverwrite: true,
        );

        expect($dto->exchangeId)->toBe('kraken')
            ->and($dto->symbol)->toBe('ETH/USDT')
            ->and($dto->timeframe)->toBe('4h')
            ->and($dto->endDate)->toBe($endDate)
            ->and($dto->forceOverwrite)->toBeTrue();
    });

    describe('getEndDate', function () {
        it('returns provided end date', function () {
            $endDate = Carbon::parse('2024-12-31');
            $dto = new DownloadRequestDto(
                exchangeId: 'binance',
                symbol: 'BTC/USDT',
                timeframe: '1h',
                startDate: Carbon::parse('2024-01-01'),
                endDate: $endDate,
            );

            expect($dto->getEndDate()->equalTo($endDate))->toBeTrue();
        });

        it('defaults to now when end date is null', function () {
            $before = Carbon::now()->startOfSecond();
            $dto = new DownloadRequestDto(
                exchangeId: 'binance',
                symbol: 'BTC/USDT',
                timeframe: '1h',
                startDate: Carbon::parse('2024-01-01'),
            );

            $endDate = $dto->getEndDate();
            expect($endDate)->toBeInstanceOf(Carbon::class);
        });
    });
});
