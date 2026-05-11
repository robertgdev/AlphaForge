<?php

use App\AlphaForge\Backtesting\Optimization\OptimizationConfig;
use App\AlphaForge\Backtesting\Optimization\OptimizationMethod;
use App\AlphaForge\Common\Enum\TimeframeEnum;

describe('OptimizationConfig', function () {
    it('creates from array with snake_case keys', function () {
        $config = OptimizationConfig::fromArray([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => TimeframeEnum::H1,
            'exchange' => 'binance',
            'initial_capital' => '10000',
            'stake_currency' => 'USDT',
            'method' => 'random',
            'iterations' => 500,
            'objective' => 'balanced',
        ]);

        expect($config->strategyAlias)->toBe('sma_crossover')
            ->and($config->symbols)->toBe(['BTCUSDT'])
            ->and($config->method)->toBe(OptimizationMethod::RANDOM)
            ->and($config->iterations)->toBe(500)
            ->and($config->objective)->toBe('balanced');
    });

    it('creates from array with camelCase keys', function () {
        $config = OptimizationConfig::fromArray([
            'strategyAlias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => TimeframeEnum::H1,
            'exchange' => 'binance',
            'initialCapital' => '10000',
            'stakeCurrency' => 'USDT',
        ]);

        expect($config->strategyAlias)->toBe('sma_crossover')
            ->and($config->initialCapital)->toBe('10000')
            ->and($config->stakeCurrency)->toBe('USDT');
    });

    it('defaults method to random', function () {
        $config = new OptimizationConfig;

        expect($config->method)->toBe(OptimizationMethod::RANDOM);
    });

    it('defaults objective to sharpe_ratio', function () {
        $config = new OptimizationConfig;

        expect($config->objective)->toBe('sharpe_ratio');
    });

    it('defaults topN to 50', function () {
        $config = new OptimizationConfig;

        expect($config->topN)->toBe(50);
    });

    it('handles timeframe as string', function () {
        $config = OptimizationConfig::fromArray([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
        ]);

        expect($config->timeframe)->toBe(TimeframeEnum::H1);
    });

    it('handles method as enum', function () {
        $config = OptimizationConfig::fromArray([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => TimeframeEnum::H1,
            'exchange' => 'binance',
            'method' => OptimizationMethod::GENETIC,
        ]);

        expect($config->method)->toBe(OptimizationMethod::GENETIC);
    });

    it('sets genetic parameters', function () {
        $config = OptimizationConfig::fromArray([
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
        $config = OptimizationConfig::fromArray([
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

    it('defaults executionTimeframe to null', function () {
        $config = new OptimizationConfig;

        expect($config->executionTimeframe)->toBeNull();
    });

    it('parses execution_timeframe from string', function () {
        $config = OptimizationConfig::fromArray([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => TimeframeEnum::H1,
            'exchange' => 'binance',
            'execution_timeframe' => '1m',
        ]);

        expect($config->executionTimeframe)->toBe(TimeframeEnum::M1);
    });

    it('parses executionTimeframe as enum', function () {
        $config = OptimizationConfig::fromArray([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => TimeframeEnum::H1,
            'exchange' => 'binance',
            'executionTimeframe' => TimeframeEnum::M5,
        ]);

        expect($config->executionTimeframe)->toBe(TimeframeEnum::M5);
    });

    it('handles null execution_timeframe explicitly', function () {
        $config = OptimizationConfig::fromArray([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => TimeframeEnum::H1,
            'exchange' => 'binance',
            'execution_timeframe' => null,
        ]);

        expect($config->executionTimeframe)->toBeNull();
    });

    it('preserves executionTimeframe with full config', function () {
        $config = OptimizationConfig::fromArray([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => TimeframeEnum::H1,
            'exchange' => 'binance',
            'initial_capital' => '10000',
            'stake_currency' => 'USDT',
            'method' => 'random',
            'iterations' => 500,
            'objective' => 'balanced',
            'execution_timeframe' => '5m',
        ]);

        expect($config->executionTimeframe)->toBe(TimeframeEnum::M5)
            ->and($config->method)->toBe(OptimizationMethod::RANDOM)
            ->and($config->iterations)->toBe(500);
    });
});
