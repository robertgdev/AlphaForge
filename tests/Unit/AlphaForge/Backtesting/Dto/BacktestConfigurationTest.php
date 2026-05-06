<?php

use App\AlphaForge\Backtesting\Dto\BacktestConfiguration;
use App\AlphaForge\Common\Enum\TimeframeEnum;

describe('BacktestConfiguration', function () {
    it('creates with required parameters', function () {
        $config = new BacktestConfiguration(
            strategyAlias: 'sma_crossover',
            symbols: ['BTC/USDT'],
            timeframe: TimeframeEnum::H1,
            dataSourceExchangeId: 'binance',
            initialCapital: '10000',
            stakeCurrency: 'USDT',
        );

        expect($config->strategyAlias)->toBe('sma_crossover')
            ->and($config->symbols)->toBe(['BTC/USDT'])
            ->and($config->timeframe)->toBe(TimeframeEnum::H1)
            ->and($config->dataSourceExchangeId)->toBe('binance')
            ->and($config->initialCapital)->toBe('10000')
            ->and($config->stakeCurrency)->toBe('USDT');
    });

    it('defaults strategy inputs to empty array', function () {
        $config = new BacktestConfiguration(
            strategyAlias: 'sma_crossover',
            symbols: ['BTC/USDT'],
            timeframe: TimeframeEnum::H1,
            dataSourceExchangeId: 'binance',
            initialCapital: '10000',
            stakeCurrency: 'USDT',
        );

        expect($config->strategyInputs)->toBe([]);
    });

    it('defaults commission config to percentage with 0.1% rate', function () {
        $config = new BacktestConfiguration(
            strategyAlias: 'sma_crossover',
            symbols: ['BTC/USDT'],
            timeframe: TimeframeEnum::H1,
            dataSourceExchangeId: 'binance',
            initialCapital: '10000',
            stakeCurrency: 'USDT',
        );

        expect($config->commissionConfig)->toBe(['type' => 'percentage', 'rate' => 0.001]);
    });

    it('defaults dates to null', function () {
        $config = new BacktestConfiguration(
            strategyAlias: 'sma_crossover',
            symbols: ['BTC/USDT'],
            timeframe: TimeframeEnum::H1,
            dataSourceExchangeId: 'binance',
            initialCapital: '10000',
            stakeCurrency: 'USDT',
        );

        expect($config->startDate)->toBeNull()
            ->and($config->endDate)->toBeNull();
    });

    it('defaults execution timeframe to null', function () {
        $config = new BacktestConfiguration(
            strategyAlias: 'sma_crossover',
            symbols: ['BTC/USDT'],
            timeframe: TimeframeEnum::H1,
            dataSourceExchangeId: 'binance',
            initialCapital: '10000',
            stakeCurrency: 'USDT',
        );

        expect($config->executionTimeframe)->toBeNull();
    });

    it('accepts all optional parameters', function () {
        $startDate = new \DateTimeImmutable('2024-01-01');
        $endDate = new \DateTimeImmutable('2024-12-31');

        $config = new BacktestConfiguration(
            strategyAlias: 'sma_crossover',
            symbols: ['BTC/USDT', 'ETH/USDT'],
            timeframe: TimeframeEnum::H1,
            dataSourceExchangeId: 'binance',
            initialCapital: 50000.0,
            stakeCurrency: 'USDT',
            strategyInputs: ['fastPeriod' => 10, 'slowPeriod' => 20],
            commissionConfig: ['type' => 'fixed', 'rate' => 5],
            startDate: $startDate,
            endDate: $endDate,
            executionTimeframe: TimeframeEnum::M5,
        );

        expect($config->strategyInputs)->toBe(['fastPeriod' => 10, 'slowPeriod' => 20])
            ->and($config->commissionConfig)->toBe(['type' => 'fixed', 'rate' => 5])
            ->and($config->startDate)->toBe($startDate)
            ->and($config->endDate)->toBe($endDate)
            ->and($config->executionTimeframe)->toBe(TimeframeEnum::M5)
            ->and($config->symbols)->toBe(['BTC/USDT', 'ETH/USDT']);
    });

    it('accepts float initial capital', function () {
        $config = new BacktestConfiguration(
            strategyAlias: 'sma_crossover',
            symbols: ['BTC/USDT'],
            timeframe: TimeframeEnum::H1,
            dataSourceExchangeId: 'binance',
            initialCapital: 10000.50,
            stakeCurrency: 'USDT',
        );

        expect($config->initialCapital)->toBe(10000.50);
    });
});
