<?php

use App\AlphaForge\Backtesting\Dto\WalkForwardConfiguration;
use App\AlphaForge\Backtesting\Optimization\Optimizer;
use App\AlphaForge\Backtesting\Service\Backtester;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardService;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use Carbon\Carbon;
use Safe\DateTimeImmutable;

describe('WalkForwardService computeDateSplit', function () {
    beforeEach(function () {
        $this->service = new WalkForwardService(
            Mockery::mock(Optimizer::class),
            Mockery::mock(Backtester::class),
        );
    });

    it('splits date range by split ratio', function () {
        $config = new WalkForwardConfiguration;
        $config->strategyAlias = 'sma_crossover';
        $config->symbols = ['BTCUSDT'];
        $config->timeframe = TimeframeEnum::H1;
        $config->exchange = 'binance';
        $config->initialCapital = '10000';
        $config->stakeCurrency = 'USDT';
        $config->startDate = new DateTimeImmutable('2024-01-01');
        $config->endDate = new DateTimeImmutable('2026-01-01');
        $config->splitRatio = 0.75;

        [$isStart, $isEnd, $oosStart, $oosEnd] = $this->service->computeDateSplit($config);

        expect($isStart->toDateString())->toBe('2024-01-01')
            ->and($oosEnd->toDateString())->toBe('2026-01-01');

        $totalDays = $isStart->diffInDays($oosEnd);
        $isDays = $isStart->diffInDays($isEnd);
        $oosDays = $oosStart->diffInDays($oosEnd);

        expect($isDays)->toBeGreaterThan(0)
            ->and($oosDays)->toBeGreaterThan(0)
            ->and($isDays + $oosDays)->toBeLessThanOrEqual($totalDays + 2);

        $isFraction = $isDays / $totalDays;
        expect($isFraction)->toBeGreaterThan(0.70)
            ->and($isFraction)->toBeLessThan(0.80);
    });

    it('respects explicit oosStartDate over split ratio', function () {
        $config = new WalkForwardConfiguration;
        $config->strategyAlias = 'sma_crossover';
        $config->symbols = ['BTCUSDT'];
        $config->timeframe = TimeframeEnum::H1;
        $config->exchange = 'binance';
        $config->initialCapital = '10000';
        $config->stakeCurrency = 'USDT';
        $config->startDate = new DateTimeImmutable('2024-01-01');
        $config->endDate = new DateTimeImmutable('2026-01-01');
        $config->splitRatio = 0.75;
        $config->oosStartDate = '2025-07-01';

        [$isStart, $isEnd, $oosStart, $oosEnd] = $this->service->computeDateSplit($config);

        expect($isStart->toDateString())->toBe('2024-01-01')
            ->and($oosStart->toDateString())->toBe('2025-07-01')
            ->and($oosEnd->toDateString())->toBe('2026-01-01')
            ->and($isEnd->toDateString())->toBe('2025-06-30');
    });

    it('uses default dates when not provided', function () {
        $config = new WalkForwardConfiguration;
        $config->strategyAlias = 'sma_crossover';
        $config->symbols = ['BTCUSDT'];
        $config->timeframe = TimeframeEnum::H1;
        $config->exchange = 'binance';
        $config->initialCapital = '10000';
        $config->stakeCurrency = 'USDT';
        $config->splitRatio = 0.75;

        [$isStart, $isEnd, $oosStart, $oosEnd] = $this->service->computeDateSplit($config);

        expect($isStart)->toBeInstanceOf(Carbon::class)
            ->and($oosEnd)->toBeInstanceOf(Carbon::class)
            ->and($oosStart->gt($isEnd))->toBeTrue()
            ->and($oosEnd->gt($oosStart))->toBeTrue();
    });

    it('throws when split ratio leaves no OOS period', function () {
        $config = new WalkForwardConfiguration;
        $config->strategyAlias = 'sma_crossover';
        $config->symbols = ['BTCUSDT'];
        $config->timeframe = TimeframeEnum::H1;
        $config->exchange = 'binance';
        $config->initialCapital = '10000';
        $config->stakeCurrency = 'USDT';
        $config->startDate = new DateTimeImmutable('2024-01-01');
        $config->endDate = new DateTimeImmutable('2024-01-02');
        $config->splitRatio = 0.9999;

        expect(fn () => $this->service->computeDateSplit($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('splits 50/50 with ratio 0.5', function () {
        $config = new WalkForwardConfiguration;
        $config->strategyAlias = 'sma_crossover';
        $config->symbols = ['BTCUSDT'];
        $config->timeframe = TimeframeEnum::H1;
        $config->exchange = 'binance';
        $config->initialCapital = '10000';
        $config->stakeCurrency = 'USDT';
        $config->startDate = new DateTimeImmutable('2024-01-01');
        $config->endDate = new DateTimeImmutable('2026-01-01');
        $config->splitRatio = 0.50;

        [$isStart, $isEnd, $oosStart, $oosEnd] = $this->service->computeDateSplit($config);

        $totalDays = $isStart->diffInDays($oosEnd);
        $isDays = $isStart->diffInDays($isEnd);

        $isFraction = $isDays / $totalDays;
        expect($isFraction)->toBeGreaterThan(0.45)
            ->and($isFraction)->toBeLessThan(0.55);
    });

    it('OOS start comes after IS end', function () {
        $config = new WalkForwardConfiguration;
        $config->strategyAlias = 'sma_crossover';
        $config->symbols = ['BTCUSDT'];
        $config->timeframe = TimeframeEnum::H1;
        $config->exchange = 'binance';
        $config->initialCapital = '10000';
        $config->stakeCurrency = 'USDT';
        $config->startDate = new DateTimeImmutable('2024-01-01');
        $config->endDate = new DateTimeImmutable('2026-01-01');
        $config->splitRatio = 0.75;

        [$isStart, $isEnd, $oosStart, $oosEnd] = $this->service->computeDateSplit($config);

        expect($oosStart->gt($isEnd))->toBeTrue();
    });
});

describe('WalkForwardService executionTimeframe propagation', function () {
    it('passes executionTimeframe in WalkForwardConfiguration', function () {
        $config = new WalkForwardConfiguration;
        $config->strategyAlias = 'sma_crossover';
        $config->symbols = ['BTCUSDT'];
        $config->timeframe = TimeframeEnum::H1;
        $config->exchange = 'binance';
        $config->initialCapital = '10000';
        $config->stakeCurrency = 'USDT';
        $config->splitRatio = 0.75;
        $config->executionTimeframe = TimeframeEnum::M1;
        $config->minTrades = 10;

        expect($config->executionTimeframe)->toBe(TimeframeEnum::M1)
            ->and($config->minTrades)->toBe(10);
    });
});

describe('WalkForwardService computeDateSplit edge cases', function () {
    beforeEach(function () {
        $this->service = new WalkForwardService(
            Mockery::mock(Optimizer::class),
            Mockery::mock(Backtester::class),
        );
    });

    it('handles very short date range with small split ratio', function () {
        $config = new WalkForwardConfiguration;
        $config->strategyAlias = 'sma_crossover';
        $config->symbols = ['BTCUSDT'];
        $config->timeframe = TimeframeEnum::H1;
        $config->exchange = 'binance';
        $config->initialCapital = '10000';
        $config->stakeCurrency = 'USDT';
        $config->startDate = new DateTimeImmutable('2024-01-01');
        $config->endDate = new DateTimeImmutable('2024-02-01');
        $config->splitRatio = 0.50;

        [$isStart, $isEnd, $oosStart, $oosEnd] = $this->service->computeDateSplit($config);

        expect($oosStart->gt($isEnd))->toBeTrue()
            ->and($oosEnd->gt($oosStart))->toBeTrue();
    });

    it('handles leap year date range correctly', function () {
        $config = new WalkForwardConfiguration;
        $config->strategyAlias = 'sma_crossover';
        $config->symbols = ['BTCUSDT'];
        $config->timeframe = TimeframeEnum::H1;
        $config->exchange = 'binance';
        $config->initialCapital = '10000';
        $config->stakeCurrency = 'USDT';
        $config->startDate = new DateTimeImmutable('2024-02-01');
        $config->endDate = new DateTimeImmutable('2024-03-31');
        $config->splitRatio = 0.50;

        [$isStart, $isEnd, $oosStart, $oosEnd] = $this->service->computeDateSplit($config);

        expect($oosStart->gt($isEnd))->toBeTrue();
    });

    it('throws when split ratio is 1.0', function () {
        $config = new WalkForwardConfiguration;
        $config->strategyAlias = 'sma_crossover';
        $config->symbols = ['BTCUSDT'];
        $config->timeframe = TimeframeEnum::H1;
        $config->exchange = 'binance';
        $config->initialCapital = '10000';
        $config->stakeCurrency = 'USDT';
        $config->startDate = new DateTimeImmutable('2024-01-01');
        $config->endDate = new DateTimeImmutable('2024-12-31');
        $config->splitRatio = 1.0;

        expect(fn () => $this->service->computeDateSplit($config))
            ->toThrow(InvalidArgumentException::class);
    });

    it('oosStartDate at exact end date throws exception', function () {
        $config = new WalkForwardConfiguration;
        $config->strategyAlias = 'sma_crossover';
        $config->symbols = ['BTCUSDT'];
        $config->timeframe = TimeframeEnum::H1;
        $config->exchange = 'binance';
        $config->initialCapital = '10000';
        $config->stakeCurrency = 'USDT';
        $config->startDate = new DateTimeImmutable('2024-01-01');
        $config->endDate = new DateTimeImmutable('2024-12-31');
        $config->splitRatio = 0.75;
        $config->oosStartDate = '2024-12-31';

        expect(fn () => $this->service->computeDateSplit($config))
            ->toThrow(InvalidArgumentException::class);
    });
});
