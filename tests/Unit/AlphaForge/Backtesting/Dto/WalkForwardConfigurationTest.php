<?php

use App\AlphaForge\Backtesting\Dto\WalkForwardConfiguration;
use App\AlphaForge\Backtesting\Optimization\OptimizationMethod;
use App\AlphaForge\Common\Enum\TimeframeEnum;

describe('WalkForwardConfiguration', function () {
    it('creates from array with snake_case keys', function () {
        $config = WalkForwardConfiguration::fromArray([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => TimeframeEnum::H1,
            'exchange' => 'binance',
            'initial_capital' => '10000',
            'stake_currency' => 'USDT',
            'method' => 'random',
            'iterations' => 500,
            'objective' => 'balanced',
            'split_ratio' => 0.80,
            'top_n' => 25,
        ]);

        expect($config->strategyAlias)->toBe('sma_crossover')
            ->and($config->symbols)->toBe(['BTCUSDT'])
            ->and($config->method)->toBe(OptimizationMethod::RANDOM)
            ->and($config->iterations)->toBe(500)
            ->and($config->objective)->toBe('balanced')
            ->and($config->splitRatio)->toBe(0.80)
            ->and($config->topN)->toBe(25);
    });

    it('creates from array with camelCase keys', function () {
        $config = WalkForwardConfiguration::fromArray([
            'strategyAlias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => TimeframeEnum::H1,
            'exchange' => 'binance',
            'initialCapital' => '10000',
            'stakeCurrency' => 'USDT',
            'topN' => 10,
            'splitRatio' => 0.70,
        ]);

        expect($config->strategyAlias)->toBe('sma_crossover')
            ->and($config->initialCapital)->toBe('10000')
            ->and($config->stakeCurrency)->toBe('USDT')
            ->and($config->topN)->toBe(10)
            ->and($config->splitRatio)->toBe(0.70);
    });

    it('defaults method to random', function () {
        $config = new WalkForwardConfiguration;

        expect($config->method)->toBe(OptimizationMethod::RANDOM);
    });

    it('defaults objective to sharpe_ratio', function () {
        $config = new WalkForwardConfiguration;

        expect($config->objective)->toBe('sharpe_ratio');
    });

    it('defaults topN to 50', function () {
        $config = new WalkForwardConfiguration;

        expect($config->topN)->toBe(50);
    });

    it('defaults splitRatio to 0.75', function () {
        $config = new WalkForwardConfiguration;

        expect($config->splitRatio)->toBe(0.75);
    });

    it('defaults oosStartDate to null', function () {
        $config = new WalkForwardConfiguration;

        expect($config->oosStartDate)->toBeNull();
    });

    it('defaults executionTimeframe to null', function () {
        $config = new WalkForwardConfiguration;

        expect($config->executionTimeframe)->toBeNull();
    });

    it('defaults minTrades to null', function () {
        $config = new WalkForwardConfiguration;

        expect($config->minTrades)->toBeNull();
    });

    it('parses execution_timeframe from string', function () {
        $config = WalkForwardConfiguration::fromArray([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => TimeframeEnum::H1,
            'exchange' => 'binance',
            'execution_timeframe' => '1m',
        ]);

        expect($config->executionTimeframe)->toBe(TimeframeEnum::M1);
    });

    it('parses executionTimeframe as enum', function () {
        $config = WalkForwardConfiguration::fromArray([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => TimeframeEnum::H1,
            'exchange' => 'binance',
            'executionTimeframe' => TimeframeEnum::M5,
        ]);

        expect($config->executionTimeframe)->toBe(TimeframeEnum::M5);
    });

    it('parses min_trades from array', function () {
        $config = WalkForwardConfiguration::fromArray([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => TimeframeEnum::H1,
            'exchange' => 'binance',
            'min_trades' => 10,
        ]);

        expect($config->minTrades)->toBe(10);
    });

    it('parses minTrades from camelCase', function () {
        $config = WalkForwardConfiguration::fromArray([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => TimeframeEnum::H1,
            'exchange' => 'binance',
            'minTrades' => 15,
        ]);

        expect($config->minTrades)->toBe(15);
    });

    it('handles timeframe as string', function () {
        $config = WalkForwardConfiguration::fromArray([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
        ]);

        expect($config->timeframe)->toBe(TimeframeEnum::H1);
    });

    it('handles method as enum', function () {
        $config = WalkForwardConfiguration::fromArray([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => TimeframeEnum::H1,
            'exchange' => 'binance',
            'method' => OptimizationMethod::GENETIC,
        ]);

        expect($config->method)->toBe(OptimizationMethod::GENETIC);
    });

    it('sets genetic parameters', function () {
        $config = WalkForwardConfiguration::fromArray([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => TimeframeEnum::H1,
            'exchange' => 'binance',
            'method' => 'genetic',
            'population_size' => 100,
            'generations' => 30,
            'mutation_rate' => 0.2,
            'crossover_rate' => 0.8,
        ]);

        expect($config->populationSize)->toBe(100)
            ->and($config->generations)->toBe(30)
            ->and($config->mutationRate)->toBe(0.2)
            ->and($config->crossoverRate)->toBe(0.8);
    });

    it('handles date strings', function () {
        $config = WalkForwardConfiguration::fromArray([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => TimeframeEnum::H1,
            'exchange' => 'binance',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
        ]);

        expect($config->startDate)->toBeInstanceOf(DateTimeImmutable::class)
            ->and($config->endDate)->toBeInstanceOf(DateTimeImmutable::class);
    });

    it('handles oosStartDate', function () {
        $config = WalkForwardConfiguration::fromArray([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => TimeframeEnum::H1,
            'exchange' => 'binance',
            'oos_start_date' => '2025-07-01',
        ]);

        expect($config->oosStartDate)->toBe('2025-07-01');
    });

    it('defaults parameterOverrides to null', function () {
        $config = new WalkForwardConfiguration;

        expect($config->parameterOverrides)->toBeNull();
    });

    it('handles parameterOverrides from array', function () {
        $overrides = ['fastPeriod' => ['min' => 5, 'max' => 20, 'step' => 5]];
        $config = WalkForwardConfiguration::fromArray([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => TimeframeEnum::H1,
            'exchange' => 'binance',
            'parameter_overrides' => $overrides,
        ]);

        expect($config->parameterOverrides)->toBe($overrides);
    });
});
